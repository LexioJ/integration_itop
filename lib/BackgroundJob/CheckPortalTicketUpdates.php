<?php

/**
 * Nextcloud - iTop
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\BackgroundJob;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\ItopAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Background job to check for portal user ticket updates and send notifications
 *
 * Runs every 5 minutes and checks CMDBChangeOp for:
 * - Ticket status changes
 * - Agent responses (public_log entries)
 * - Ticket resolutions
 *
 * Implements per-user interval checking and respects granular notification toggles
 */
class CheckPortalTicketUpdates extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private ItopAPIService $itopService,
		private IUserManager $userManager,
		private IConfig $config,
		private INotificationManager $notificationManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Run every 5 minutes
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$startTime = microtime(true);

		// Get admin configured default interval (in seconds)
		$adminDefaultInterval = (int)$this->config->getAppValue(
			Application::APP_ID,
			'default_notification_interval',
			'60'
		) * 60; // Convert minutes to seconds

		$usersProcessed = 0;
		$usersSkipped = 0;
		$notificationsSent = 0;

		$this->userManager->callForAllUsers(function (IUser $user) use ($adminDefaultInterval, &$usersProcessed, &$usersSkipped, &$notificationsSent) {
			$userId = $user->getUID();

			// Check if should process this user
			if (!$this->shouldCheckUser($userId, $adminDefaultInterval)) {
				$usersSkipped++;
				return;
			}

			// Process notifications for this user
			$sentCount = $this->checkPortalNotifications($userId);
			$notificationsSent += $sentCount;
			$usersProcessed++;
		});

		$duration = round((microtime(true) - $startTime) * 1000);

		$this->logger->info('Portal notification check completed', [
			'app' => Application::APP_ID,
			'users_processed' => $usersProcessed,
			'users_skipped' => $usersSkipped,
			'notifications_sent' => $notificationsSent,
			'duration_ms' => $duration,
		]);
	}

	/**
	 * Check if we should process notifications for this user
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $adminDefaultInterval Admin configured default interval in seconds
	 * @return bool
	 */
	private function shouldCheckUser(string $userId, int $adminDefaultInterval): bool {
		// Check master toggle
		$enabled = $this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1';
		if (!$enabled) {
			return false;
		}

		// Check person_id configured
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		if (empty($personId)) {
			return false;
		}

		// Check if all portal notifications are disabled
		$disabledPortal = $this->config->getUserValue($userId, Application::APP_ID, 'disabled_portal_notifications', '');
		if ($disabledPortal === 'all') {
			return false;
		}

		// Get user's custom interval (fallback to admin default)
		$userInterval = (int)$this->config->getUserValue(
			$userId,
			Application::APP_ID,
			'notification_check_interval',
			(string)($adminDefaultInterval / 60)
		) * 60; // Convert minutes to seconds

		// Check interval using Unix timestamp
		$lastCheck = $this->config->getUserValue($userId, Application::APP_ID, 'notification_last_portal_check', '');

		if (empty($lastCheck)) {
			return true; // First run
		}

		// Parse Unix timestamp
		$lastCheckTimestamp = (int)$lastCheck;
		$now = time();
		$diff = $now - $lastCheckTimestamp;

		return $diff >= $userInterval;
	}

	/**
	 * Check for portal notifications for a user and send them
	 *
	 * @return int Number of notifications sent
	 */
	private function checkPortalNotifications(string $userId): int {
		$notificationCount = 0;

		try {
			// Get last check Unix timestamp
			$lastCheckStr = $this->config->getUserValue($userId, Application::APP_ID, 'notification_last_portal_check', '');
			$lastCheckTimestamp = empty($lastCheckStr) ? (time() - (30 * 24 * 60 * 60)) : (int)$lastCheckStr; // Default 30 days ago

			// Get effective enabled portal notifications (respects admin config and user preferences)
			$enabledNotifications = Application::getEffectiveEnabledPortalNotifications($this->config, $userId);

			if (empty($enabledNotifications)) {
				// No notifications enabled, update timestamp and return
				$this->config->setUserValue($userId, Application::APP_ID, 'notification_last_portal_check', (string)time());
				return 0;
			}

			// Get user's ticket IDs (including resolved to detect resolution notifications)
			$ticketIds = $this->itopService->getUserTicketIds($userId, true, true); // portal only, include resolved

			$this->logger->debug('Portal notification check for user', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'lastCheckTimestamp' => $lastCheckTimestamp,
				'enabledNotifications' => $enabledNotifications,
				'ticketCount' => count($ticketIds),
				'ticketIds' => $ticketIds
			]);

			if (empty($ticketIds)) {
				// No tickets, update timestamp and return
				$this->config->setUserValue($userId, Application::APP_ID, 'notification_last_portal_check', (string)time());
				return 0;
			}

			// Check which notification types are enabled
			$checkStatusChanged = in_array('ticket_status_changed', $enabledNotifications);
			$checkAgentResponded = in_array('agent_responded', $enabledNotifications);
			$checkTicketResolved = in_array('ticket_resolved', $enabledNotifications);
			$checkAgentAssigned = in_array('agent_assigned', $enabledNotifications);

			// Query optimization: Only query for CMDBChangeOpSetAttributeScalar if needed
			if ($checkStatusChanged || $checkTicketResolved || $checkAgentAssigned) {
				$statusChanges = $this->itopService->getChangeOps($userId, $ticketIds, $lastCheckTimestamp, ['status', 'agent_id']);

				$this->logger->debug('Status changes detected', [
					'app' => Application::APP_ID,
					'userId' => $userId,
					'changeCount' => count($statusChanges)
				]);

				foreach ($statusChanges as $change) {
					// Handle agent assignment changes
					if ($change['attcode'] === 'agent_id' && $checkAgentAssigned) {
						// Skip if agent didn't actually change
						if ($change['oldvalue'] === $change['newvalue']) {
							continue;
						}

						// Resolve agent IDs to names
						$agentIds = array_filter([$change['oldvalue'], $change['newvalue']], function ($id) {
							return !empty($id) && $id != '0';
						});
						$agentNames = !empty($agentIds) ? $this->itopService->resolveUserNames($userId, $agentIds) : [];

						$oldAgent = $change['oldvalue'] == '0' || empty($change['oldvalue'])
							? 'Unassigned'
							: ($agentNames[$change['oldvalue']] ?? $change['oldvalue']);
						$newAgent = $change['newvalue'] == '0' || empty($change['newvalue'])
							? 'Unassigned'
							: ($agentNames[$change['newvalue']] ?? $change['newvalue']);

						$this->sendNotification($userId, 'agent_assigned', [
							'ticket_id' => $change['objkey'],
							'ticket_class' => $change['objclass'],
							'old_agent' => $oldAgent,
							'new_agent' => $newAgent,
							'timestamp' => $change['date']
						]);
						$notificationCount++;
						continue;
					}

					// Handle status changes
					if ($change['attcode'] !== 'status') {
						continue;
					}

					// Skip if status didn't actually change (oldvalue == newvalue)
					if ($change['oldvalue'] === $change['newvalue']) {
						continue;
					}

					// Check for resolution
					if ($checkTicketResolved && $change['newvalue'] === 'resolved') {
						$this->sendNotification($userId, 'ticket_resolved', [
							'ticket_id' => $change['objkey'],
							'ticket_class' => $change['objclass'],
							'old_status' => $change['oldvalue'],
							'new_status' => $change['newvalue'],
							'timestamp' => $change['date']
						]);
						$notificationCount++;
					} elseif ($checkStatusChanged) {
						// Generic status change
						$this->sendNotification($userId, 'ticket_status_changed', [
							'ticket_id' => $change['objkey'],
							'ticket_class' => $change['objclass'],
							'old_status' => $change['oldvalue'],
							'new_status' => $change['newvalue'],
							'timestamp' => $change['date']
						]);
						$notificationCount++;
					}

					// Rate limit: max 20 notifications per user per run
					if ($notificationCount >= 20) {
						break;
					}
				}
			}

			// Query optimization: Only query for CMDBChangeOpSetAttributeCaseLog if needed
			if ($checkAgentResponded && $notificationCount < 20) {
				$logChanges = $this->itopService->getCaseLogChanges($userId, $ticketIds, $lastCheckTimestamp, ['public_log']);

				$this->logger->debug('Log changes detected', [
					'app' => Application::APP_ID,
					'userId' => $userId,
					'changeCount' => count($logChanges)
				]);

				// Get user's iTop user_id to filter out self-comments
				$userItopId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id', '');

				foreach ($logChanges as $change) {
					// Skip if no user attribution (system entry)
					if (empty($change['user_id'])) {
						continue;
					}

					// Skip if user is commenting on their own ticket (don't notify self)
					if (!empty($userItopId) && $change['user_id'] === $userItopId) {
						continue;
					}

					$this->sendNotification($userId, 'agent_responded', [
						'ticket_id' => $change['objkey'],
						'ticket_class' => $change['objclass'],
						'agent_name' => $change['userinfo'],
						'timestamp' => $change['date']
					]);
					$notificationCount++;

					// Rate limit
					if ($notificationCount >= 20) {
						break;
					}
				}
			}

			// Update last check timestamp to current Unix timestamp
			$this->config->setUserValue(
				$userId,
				Application::APP_ID,
				'notification_last_portal_check',
				(string)time()
			);

		} catch (\Exception $e) {
			$this->logger->error('Error checking portal notifications for user: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'exception' => $e
			]);
		}

		return $notificationCount;
	}

	/**
	 * Send a notification to the user
	 */
	private function sendNotification(string $userId, string $subject, array $params): void {
		try {
			// Parse timestamp from iTop (format: '2025-11-05 22:40:21')
			// iTop returns timestamps in server local time, which may differ from PHP's default timezone
			// We need to get Nextcloud's configured timezone to parse correctly
			$timestamp = $params['timestamp'] ?? null;
			if ($timestamp) {
				// Get Nextcloud's configured timezone (defaults to UTC if not set)
				$timezoneStr = $this->config->getSystemValue('default_timezone', 'UTC');
				try {
					$timezone = new \DateTimeZone($timezoneStr);
				} catch (\Exception $e) {
					// Fallback to UTC if timezone is invalid
					$timezone = new \DateTimeZone('UTC');
				}

				$dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $timestamp, $timezone);
				if ($dateTime === false) {
					// Fallback if format is different
					$dateTime = new \DateTime($timestamp, $timezone);
				}
			} else {
				$dateTime = new \DateTime();
			}

			// Create unique object key to prevent duplicate notifications
			// Format: ticket_id|subject|timestamp_hash
			// This ensures each change generates a unique notification
			$timestampHash = $timestamp ? substr(md5($timestamp), 0, 8) : time();
			$objectKey = $params['ticket_id'] . '|' . $subject . '|' . $timestampHash;

			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime($dateTime)
				->setObject('ticket', $objectKey)
				->setSubject($subject, $params);

			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			$this->logger->error('Failed to send notification: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'subject' => $subject
			]);
		}
	}
}
