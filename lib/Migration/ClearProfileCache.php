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
 * Expire every cached portal-only verdict so it is recomputed against iTop.
 *
 * The rule changed: a user is portal-only when the "Portal user" profile is
 * assigned at all, not only when it is their sole profile. Values cached under
 * the old rule can wrongly mark a portal-confined user as a back-end user,
 * which skips the CI scoping in ItopAPIService::searchCIs() and produces
 * back-office links the user cannot open. Expiring the cache makes the next
 * profile lookup authoritative instead of waiting for the TTL to lapse.
 *
 * Registered as a post-migration step, so it runs on every app upgrade and not
 * only this one. That is deliberate and harmless: the effect is one indexed
 * DELETE and one fresh profile lookup per active user after an upgrade, which
 * is the safe side of a rule that has already been corrected once.
 */
class ClearProfileCache implements IRepairStep {

	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	public function getName(): string {
		return 'Expire cached iTop portal-only status';
	}

	public function run(IOutput $output): void {
		// Expire rather than erase, and expire the timestamp only.
		// ProfileService::getCachedProfileStatus() treats an empty
		// profiles_last_check as a miss, so the next lookup is authoritative
		// either way -- which is this step's whole purpose.
		//
		// is_portal_only is deliberately left in place. Three consumers read it
		// raw, bypassing ProfileService, with a permissive default of '0':
		// ItopAPIService::buildTicketUrl(), Notification\Notifier (its own copy,
		// to avoid a circular dependency) and
		// BackgroundJob\CheckAgentTicketUpdates::shouldCheckUser(). Deleting the
		// key makes an absent verdict read as "back-end user", so a
		// portal-confined user's next notification links to a back-office URL
		// iTop denies them -- and nothing on the notification path calls
		// ProfileService, so the key would only return if that user happened to
		// open the Dashboard or use search. A stale verdict is usable; an absent
		// one is permissive.
		//
		// deleteKey() removes the key for every user of this app in one call.
		$this->userConfig->deleteKey(Application::APP_ID, 'profiles_last_check');

		$output->info('Cached portal-only status expired; it will be recomputed from iTop on next use.');
	}
}
