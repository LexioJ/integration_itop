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

namespace OCA\Itop\Service;

use OCA\Itop\AppInfo\Application;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Business logic for mirroring iTop Newsroom notifications to Nextcloud.
 *
 * Design principles:
 *  - Opt-in: only processes users who have set newsroom_mirroring_enabled = '1'
 *  - Deduplication: tracks last_newsroom_id per user so each item is notified once
 *  - Security: mark-as-read verifies ownership via contact_id in the OQL
 *  - Resilience: errors for one user do not prevent processing subsequent users
 */
class NewsroomService {

	public function __construct(
		private ItopAPIService $itopService,
		private IUserManager $userManager,
		private IConfig $config,
		private INotificationManager $notificationManager,
		private LoggerInterface $logger,
	) {
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Poll iTop for all opted-in users and create Nextcloud notifications for
	 * new, unread Newsroom items.
	 *
	 * @return int Total number of notifications created
	 */
	public function pollAndCreateNotifications(): int {
		$totalCreated = 0;
		$usersProcessed = 0;

		$this->userManager->callForAllUsers(function (IUser $user) use (&$totalCreated, &$usersProcessed) {
			$userId = $user->getUID();

			if (!$this->shouldProcessUser($userId)) {
				return;
			}

			$created = $this->processUser($userId);
			$totalCreated += $created;
			$usersProcessed++;
		});

		$this->logger->debug('Newsroom poll finished', [
			'app'              => Application::APP_ID,
			'users_processed'  => $usersProcessed,
			'notifications_created' => $totalCreated,
		]);

		return $totalCreated;
	}

	/**
	 * Mark a newsroom item as read in iTop and dismiss the corresponding
	 * Nextcloud notification.
	 *
	 * Called from NewsroomController when the user clicks "Mark as read".
	 *
	 * @param string $userId     Nextcloud user ID (must be authenticated)
	 * @param string $newsroomId iTop EventNotificationNewsroom key (as string)
	 * @return array ['success' => bool, 'error' => string|null]
	 */
	public function markAsRead(string $userId, string $newsroomId): array {
		// Validate the newsroom ID is a positive integer to prevent injection
		if (!is_numeric($newsroomId) || (int)$newsroomId <= 0) {
			return ['success' => false, 'error' => 'Invalid newsroom ID'];
		}

		$nid = (int)$newsroomId;

		// Get person_id for the security OQL filter
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		if (empty($personId) || !is_numeric($personId)) {
			return ['success' => false, 'error' => 'User not configured (missing person_id)'];
		}

		// Mark as read in iTop (OQL includes contact_id check for security)
		$success = $this->itopService->markNewsroomAsRead($userId, $nid, (int)$personId);

		if (!$success) {
			return ['success' => false, 'error' => 'Failed to update read status in iTop'];
		}

		// Dismiss the Nextcloud notification so it disappears from the bell icon
		$this->dismissNotification($userId, (string)$nid);

		return ['success' => true, 'error' => null];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine whether a user should be processed in the current polling run.
	 *
	 * Requirements:
	 *  1. person_id configured (iTop connection established)
	 *  2. newsroom_mirroring_enabled = '1' (opt-in)
	 */
	private function shouldProcessUser(string $userId): bool {
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		if (empty($personId)) {
			return false;
		}

		$enabled = $this->config->getUserValue($userId, Application::APP_ID, 'newsroom_mirroring_enabled', '0');
		return $enabled === '1';
	}

	/**
	 * Fetch new newsroom items for a single user and create Nextcloud notifications.
	 *
	 * @return int Number of notifications created for this user
	 */
	private function processUser(string $userId): int {
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		if (!is_numeric($personId) || (int)$personId <= 0) {
			return 0;
		}

		$personIdInt = (int)$personId;
		$lastId = (int)$this->config->getUserValue($userId, Application::APP_ID, 'last_newsroom_id', '0');

		try {
			$items = $this->itopService->getNewsroomItems($userId, $personIdInt, $lastId);
		} catch (\Exception $e) {
			$this->logger->error('Newsroom: failed to fetch items for user {userId}', [
				'app'       => Application::APP_ID,
				'userId'    => $userId,
				'exception' => $e,
			]);
			return 0;
		}

		if (empty($items)) {
			return 0;
		}

		$created = 0;
		foreach ($items as $item) {
			try {
				$this->createNotification($userId, $item);
				$created++;

				// Update last_newsroom_id after each successful notification so that
				// a mid-run crash does not cause the same item to be notified twice.
				if ($item['id'] > $lastId) {
					$lastId = $item['id'];
					$this->config->setUserValue($userId, Application::APP_ID, 'last_newsroom_id', (string)$lastId);
				}
			} catch (\Exception $e) {
				$this->logger->error('Newsroom: failed to create notification for item {id}', [
					'app'       => Application::APP_ID,
					'userId'    => $userId,
					'id'        => $item['id'],
					'exception' => $e,
				]);
			}
		}

		return $created;
	}

	/**
	 * Create a single Nextcloud notification from a newsroom item.
	 *
	 * Uses the iTop newsroom item ID as the object_id so the notification can be
	 * looked up and dismissed when the user clicks "Mark as read".
	 */
	private function createNotification(string $userId, array $item): void {
		$newsroomId = (string)$item['id'];

		// Parse the iTop date (format: 'Y-m-d H:i:s') with Nextcloud's configured timezone
		$timezoneStr = $this->config->getSystemValue('default_timezone', 'UTC');
		try {
			$timezone = new \DateTimeZone($timezoneStr);
		} catch (\Exception $e) {
			$timezone = new \DateTimeZone('UTC');
		}

		$dateTime = null;
		if (!empty($item['date'])) {
			$dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $item['date'], $timezone);
		}
		if ($dateTime === false || $dateTime === null) {
			$dateTime = new \DateTime('now', $timezone);
		}

		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime($dateTime)
			->setObject('newsroom', $newsroomId)
			->setSubject('newsroom_item', [
				'title'    => $item['title'],
				'message'  => $item['message'],
				'priority' => $item['priority'],
				'url'      => $item['url'],
			]);

		// Add "Mark as read" action button
		$action = $notification->createAction();
		$action->setLabel('mark_read')
			->setLink(
				'/apps/' . Application::APP_ID . '/newsroom/mark-read',
				'POST'
			)
			->setPrimary(true);
		$notification->addAction($action);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Dismiss a Nextcloud notification by object type + ID.
	 */
	private function dismissNotification(string $userId, string $newsroomId): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($userId)
				->setObject('newsroom', $newsroomId);

			$this->notificationManager->markProcessed($notification);
		} catch (\Exception $e) {
			// Non-fatal: the iTop update already succeeded
			$this->logger->warning('Newsroom: could not dismiss Nextcloud notification {id}', [
				'app'  => Application::APP_ID,
				'id'   => $newsroomId,
				'exception' => $e,
			]);
		}
	}
}
