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
 * Background job to check for agent ticket updates and send notifications
 * 
 * Runs every 5 minutes and checks for:
 * - Ticket assignments (new + reassignments)
 * - Team unassigned new tickets
 * - SLA warnings (TTO/TTR with escalating 24h/12h/4h/1h thresholds, weekend-aware)
 * - SLA breaches
 * - Priority critical escalations
 * - Comments (public + private for agents)
 * 
 * Implements per-user interval checking and respects granular notification toggles
 */
class CheckAgentTicketUpdates extends TimedJob {

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
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
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
			$sentCount = $this->checkAgentNotifications($userId);
			$notificationsSent += $sentCount;
			$usersProcessed++;
		});

		$duration = round((microtime(true) - $startTime) * 1000);

		$this->logger->info('Agent notification check completed', [
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
		
		// Check if user is portal-only (skip agent notifications for portal-only users)
		$isPortalOnly = $this->config->getUserValue($userId, Application::APP_ID, 'is_portal_only', '0') === '1';
		if ($isPortalOnly) {
			return false;
		}
		
		// Check if all agent notifications are disabled
		$disabledAgent = $this->config->getUserValue($userId, Application::APP_ID, 'disabled_agent_notifications', '');
		if ($disabledAgent === 'all') {
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
		$lastCheck = $this->config->getUserValue($userId, Application::APP_ID, 'notification_last_agent_check', '');

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
	 * Check for agent notifications for a user and send them
	 * 
	 * @return int Number of notifications sent
	 */
	private function checkAgentNotifications(string $userId): int {
		$notificationCount = 0;

		try {
			// Get last check Unix timestamp
			$lastCheckStr = $this->config->getUserValue($userId, Application::APP_ID, 'notification_last_agent_check', '');
			$lastCheckTimestamp = empty($lastCheckStr) ? (time() - (30 * 24 * 60 * 60)) : (int)$lastCheckStr; // Default 30 days ago
			
			// Get effective enabled agent notifications (respects admin config and user preferences)
			$enabledNotifications = Application::getEffectiveEnabledAgentNotifications($this->config, $userId);
			
			if (empty($enabledNotifications)) {
				// No notifications enabled, update timestamp and return
				$this->config->setUserValue($userId, Application::APP_ID, 'notification_last_agent_check', (string)time());
				return 0;
			}

			$this->logger->debug('Agent notification check for user', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'lastCheckTimestamp' => $lastCheckTimestamp,
				'enabledNotifications' => $enabledNotifications
			]);

			// Check which notification types are enabled
			$checkAssigned = in_array('ticket_assigned', $enabledNotifications);
			$checkReassigned = in_array('ticket_reassigned', $enabledNotifications);
			$checkTeamUnassigned = in_array('team_unassigned_new', $enabledNotifications);
			$checkTtoWarning = in_array('ticket_tto_warning', $enabledNotifications);
			$checkTtrWarning = in_array('ticket_ttr_warning', $enabledNotifications);
			$checkSlaBreach = in_array('ticket_sla_breach', $enabledNotifications);
			$checkPriorityCritical = in_array('ticket_priority_critical', $enabledNotifications);
			$checkComment = in_array('ticket_comment', $enabledNotifications);

			// 1. Handle assignment and reassignment notifications
			if ($checkAssigned || $checkReassigned) {
				$notificationCount += $this->processAssignmentChanges(
					$userId, 
					$lastCheckTimestamp, 
					$checkAssigned, 
					$checkReassigned,
					$notificationCount
				);
			}

			// 2. Handle team unassigned new tickets
			if ($checkTeamUnassigned && $notificationCount < 20) {
				$notificationCount += $this->processTeamUnassignedTickets(
					$userId, 
					$lastCheckTimestamp,
					$notificationCount
				);
			}

			// 3. Handle SLA warnings (TTO and TTR)
			if (($checkTtoWarning || $checkTtrWarning) && $notificationCount < 20) {
				$notificationCount += $this->processSlaWarnings(
					$userId, 
					$lastCheckTimestamp,
					$checkTtoWarning,
					$checkTtrWarning,
					$notificationCount
				);
			}

			// 4. Handle SLA breaches
			if ($checkSlaBreach && $notificationCount < 20) {
				$notificationCount += $this->processSlaBreaches(
					$userId, 
					$lastCheckTimestamp,
					$notificationCount
				);
			}

			// 5. Handle priority critical escalations
			if ($checkPriorityCritical && $notificationCount < 20) {
				$notificationCount += $this->processPriorityChanges(
					$userId, 
					$lastCheckTimestamp,
					$notificationCount
				);
			}

			// 6. Handle comments (public + private for agents)
			if ($checkComment && $notificationCount < 20) {
				$notificationCount += $this->processComments(
					$userId, 
					$lastCheckTimestamp,
					$notificationCount
				);
			}

			// Update last check timestamp to current Unix timestamp
			$this->config->setUserValue(
				$userId,
				Application::APP_ID,
				'notification_last_agent_check',
				(string)time()
			);

		} catch (\Exception $e) {
			$this->logger->error('Error checking agent notifications for user: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'exception' => $e
			]);
		}

		return $notificationCount;
	}

	/**
	 * Process ticket assignment and reassignment changes
	 */
	private function processAssignmentChanges(
		string $userId, 
		int $lastCheckTimestamp, 
		bool $checkAssigned, 
		bool $checkReassigned,
		int $currentNotificationCount
	): int {
		$count = 0;
		
		// Get person_id for this user
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		if (empty($personId)) {
			return 0;
		}

		// Get all agent tickets (assigned to me, ongoing only)
		$ticketIds = $this->itopService->getAgentTicketIds($userId, false); // Don't include resolved
		
		if (empty($ticketIds)) {
			return 0;
		}

		// Query for agent_id changes
		$changes = $this->itopService->getChangeOps($userId, $ticketIds, $lastCheckTimestamp, ['agent_id']);
		
		foreach ($changes as $change) {
			if ($change['attcode'] !== 'agent_id') {
				continue;
			}

			// Skip if agent didn't actually change
			if ($change['oldvalue'] === $change['newvalue']) {
				continue;
			}

			// Check if this ticket was assigned to me
			if ($change['newvalue'] === $personId || $change['newvalue'] == $personId) {
				// Assigned to me
				if ($checkAssigned && (empty($change['oldvalue']) || $change['oldvalue'] == '0')) {
					// New assignment (from NULL or 0)
					$this->sendNotification($userId, 'ticket_assigned', [
						'ticket_id' => $change['objkey'],
						'ticket_class' => $change['objclass'],
						'timestamp' => $change['date']
					]);
					$count++;
				} elseif ($checkReassigned && !empty($change['oldvalue']) && $change['oldvalue'] != '0') {
					// Reassignment (from another agent)
					$this->sendNotification($userId, 'ticket_reassigned', [
						'ticket_id' => $change['objkey'],
						'ticket_class' => $change['objclass'],
						'old_agent_id' => $change['oldvalue'],
						'timestamp' => $change['date']
					]);
					$count++;
				}
			}

			// Rate limit
			if (($currentNotificationCount + $count) >= 20) {
				break;
			}
		}

		return $count;
	}

	/**
	 * Process new unassigned tickets in user's teams
	 */
	private function processTeamUnassignedTickets(
		string $userId,
		int $lastCheckTimestamp,
		int $currentNotificationCount
	): int {
		$count = 0;
		
		// Get user's teams
		$teams = $this->itopService->getUserTeams($userId);
		if (empty($teams)) {
			return 0;
		}
		
		// Extract team IDs and names for notifications
		$teamIds = array_column($teams, 'id');
		$teamNames = array_column($teams, 'friendlyname', 'id');

		if (empty($teamIds)) {
			return 0;
		}
		
		try {
			// Get newly created or team-assigned tickets that are still unassigned
			// Strategy: Query for team_id changes where tickets became assigned to user's teams
			// This detects both newly created tickets AND tickets reassigned to different teams
			
			$teamChanges = $this->itopService->getTeamAssignmentChanges(
				$userId,
				$teamIds,
				$lastCheckTimestamp
			);

			$this->logger->debug('Team unassigned ticket detection', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'teamCount' => count($teamIds),
				'changesFound' => count($teamChanges)
			]);

			foreach ($teamChanges as $change) {
				$teamId = $change['team_id'];
				$teamName = $teamNames[$teamId] ?? "Team #$teamId";

				$this->sendNotification($userId, 'team_unassigned_new', [
					'ticket_id' => $change['ticket_id'],
					'ticket_class' => $change['ticket_class'],
					'team_name' => $teamName,
					'team_id' => $teamId,
					'timestamp' => $change['timestamp']
				]);
				$count++;

				// Rate limit
				if (($currentNotificationCount + $count) >= 20) {
					break;
				}
			}
			
		} catch (\Exception $e) {
			$this->logger->error('Error processing team unassigned tickets: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'exception' => $e
			]);
		}

		return $count;
	}

	/**
	 * Process SLA warnings for TTO and TTR
	 * Uses crossing-time algorithm with weekend-aware thresholds
	 */
	private function processSlaWarnings(
		string $userId,
		int $lastCheckTimestamp,
		bool $checkTto,
		bool $checkTtr,
		int $currentNotificationCount
	): int {
		$count = 0;
		$now = time();

		// Check TTO warnings (team tickets approaching Time To Own deadline)
		if ($checkTto && $count + $currentNotificationCount < 20) {
			$ttoTickets = $this->itopService->getTicketsApproachingDeadline(
				$userId,
				'tto',
				'team_unassigned',
				$lastCheckTimestamp,
				$now
			);

			foreach ($ttoTickets as $ticket) {
				$this->sendNotification($userId, 'ticket_tto_warning', [
					'ticket_id' => $ticket['ticket_id'],
					'ticket_class' => $ticket['ticket_class'],
					'level' => $ticket['level'],
					'deadline' => $ticket['deadline'],
					'timestamp' => date('Y-m-d H:i:s', $now)
				]);
				$count++;

				if (($count + $currentNotificationCount) >= 20) {
					break;
				}
			}
		}

		// Check TTR warnings (my tickets approaching Time To Resolve deadline)
		if ($checkTtr && $count + $currentNotificationCount < 20) {
			$ttrTickets = $this->itopService->getTicketsApproachingDeadline(
				$userId,
				'ttr',
				'my',
				$lastCheckTimestamp,
				$now
			);

			foreach ($ttrTickets as $ticket) {
				$this->sendNotification($userId, 'ticket_ttr_warning', [
					'ticket_id' => $ticket['ticket_id'],
					'ticket_class' => $ticket['ticket_class'],
					'level' => $ticket['level'],
					'deadline' => $ticket['deadline'],
					'timestamp' => date('Y-m-d H:i:s', $now)
				]);
				$count++;

				if (($count + $currentNotificationCount) >= 20) {
					break;
				}
			}
		}

		return $count;
	}

	/**
	 * Process SLA breaches (sla_tto_passed, sla_ttr_passed)
	 */
	private function processSlaBreaches(
		string $userId,
		int $lastCheckTimestamp,
		int $currentNotificationCount
	): int {
		$count = 0;
		
		// Get agent tickets
		$ticketIds = $this->itopService->getAgentTicketIds($userId, false);
		
		if (empty($ticketIds)) {
			return 0;
		}

		// Query for SLA breach flags
		$changes = $this->itopService->getChangeOps($userId, $ticketIds, $lastCheckTimestamp, ['sla_tto_passed', 'sla_ttr_passed']);
		
		foreach ($changes as $change) {
			if (!in_array($change['attcode'], ['sla_tto_passed', 'sla_ttr_passed'])) {
				continue;
			}

			// Check if SLA was breached (changed to 1 or '1')
			if ($change['newvalue'] === '1' || $change['newvalue'] === 1) {
				$slaType = $change['attcode'] === 'sla_tto_passed' ? 'TTO' : 'TTR';
				
				$this->sendNotification($userId, 'ticket_sla_breach', [
					'ticket_id' => $change['objkey'],
					'ticket_class' => $change['objclass'],
					'sla_type' => $slaType,
					'timestamp' => $change['date']
				]);
				$count++;
			}

			// Rate limit
			if (($currentNotificationCount + $count) >= 20) {
				break;
			}
		}

		return $count;
	}

	/**
	 * Process priority changes to critical (priority = 1)
	 */
	private function processPriorityChanges(
		string $userId,
		int $lastCheckTimestamp,
		int $currentNotificationCount
	): int {
		$count = 0;
		
		// Get agent tickets
		$ticketIds = $this->itopService->getAgentTicketIds($userId, false);
		
		if (empty($ticketIds)) {
			return 0;
		}

		// Query for priority changes
		$changes = $this->itopService->getChangeOps($userId, $ticketIds, $lastCheckTimestamp, ['priority']);
		
		foreach ($changes as $change) {
			if ($change['attcode'] !== 'priority') {
				continue;
			}

			// Check if priority changed to critical (1)
			if (($change['newvalue'] === '1' || $change['newvalue'] === 1) && 
				($change['oldvalue'] !== '1' && $change['oldvalue'] !== 1)) {
				
				$this->sendNotification($userId, 'ticket_priority_critical', [
					'ticket_id' => $change['objkey'],
					'ticket_class' => $change['objclass'],
					'old_priority' => $change['oldvalue'],
					'timestamp' => $change['date']
				]);
				$count++;
			}

			// Rate limit
			if (($currentNotificationCount + $count) >= 20) {
				break;
			}
		}

		return $count;
	}

	/**
	 * Process comments (public_log + private_log for agents)
	 */
	private function processComments(
		string $userId,
		int $lastCheckTimestamp,
		int $currentNotificationCount
	): int {
		$count = 0;
		
		// Get agent tickets
		$ticketIds = $this->itopService->getAgentTicketIds($userId, false);
		
		if (empty($ticketIds)) {
			return 0;
		}

		// Query for case log changes (both public and private for agents)
		$logChanges = $this->itopService->getCaseLogChanges($userId, $ticketIds, $lastCheckTimestamp, ['public_log', 'private_log']);

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

			$logType = $change['attcode'] === 'private_log' ? 'private' : 'public';

			$this->sendNotification($userId, 'ticket_comment', [
				'ticket_id' => $change['objkey'],
				'ticket_class' => $change['objclass'],
				'commenter_name' => $change['userinfo'],
				'log_type' => $logType,
				'timestamp' => $change['date']
			]);
			$count++;

			// Rate limit
			if (($currentNotificationCount + $count) >= 20) {
				break;
			}
		}

		return $count;
	}

	/**
	 * Send a notification to the user
	 */
	private function sendNotification(string $userId, string $subject, array $params): void {
		try {
			// Parse timestamp from iTop (format: '2025-11-05 22:40:21')
			$timestamp = $params['timestamp'] ?? null;
			if ($timestamp) {
				// Get Nextcloud's configured timezone
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
