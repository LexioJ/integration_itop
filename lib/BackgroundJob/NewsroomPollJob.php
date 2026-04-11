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
use OCA\Itop\Service\NewsroomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that polls iTop for new Newsroom notifications.
 *
 * Runs every 15 minutes. Newsroom mirroring is an opt-in feature
 * (users must enable newsroom_mirroring_enabled in Personal Settings).
 * Only users with a configured person_id and the opt-in flag set are
 * processed.
 *
 * Parallel execution is disabled to prevent race conditions on the
 * per-user last_newsroom_id tracking value.
 */
class NewsroomPollJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private NewsroomService $newsroomService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Poll every 15 minutes; newsroom items are not as time-critical as
		// ticket status changes, so we use TIME_INSENSITIVE to avoid
		// contributing to server load during peak hours.
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$startTime = microtime(true);

		try {
			$created = $this->newsroomService->pollAndCreateNotifications();

			$duration = round((microtime(true) - $startTime) * 1000);

			if ($created > 0) {
				$this->logger->info('Newsroom poll completed', [
					'app'                   => Application::APP_ID,
					'notifications_created' => $created,
					'duration_ms'           => $duration,
				]);
			}
		} catch (\Exception $e) {
			$this->logger->error('Newsroom poll failed', [
				'app'       => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
