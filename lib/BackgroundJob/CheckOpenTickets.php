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

use OCA\Itop\Service\ItopAPIService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;

use Psr\Log\LoggerInterface;

class CheckOpenTickets extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private ItopAPIService $itopAPIService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Check every 15 minutes
		$this->setInterval(15 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$this->itopAPIService->checkOpenTickets();
		$this->logger->info('iTop open tickets check completed');
	}
}
