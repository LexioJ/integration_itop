<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Migration;

use OCA\Itop\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Drop every cached portal-only verdict so it is recomputed against iTop.
 *
 * The rule changed: a user is portal-only when the "Portal user" profile is
 * assigned at all, not only when it is their sole profile. Values cached under
 * the old rule can wrongly mark a portal-confined user as a back-end user,
 * which skips the CI scoping in ItopAPIService::searchCIs() and produces
 * back-office links the user cannot open. Clearing the cache makes the next
 * profile lookup authoritative instead of waiting for the TTL to lapse.
 */
class ClearProfileCache implements IRepairStep {

	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	public function getName(): string {
		return 'Clear cached iTop portal-only status';
	}

	public function run(IOutput $output): void {
		// deleteKey() removes the key for every user of this app in one call
		$this->userConfig->deleteKey(Application::APP_ID, 'is_portal_only');
		$this->userConfig->deleteKey(Application::APP_ID, 'profiles_last_check');

		$output->info('Cached portal-only status cleared; it will be recomputed from iTop on next use.');
	}
}
