<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCA\Itop\AppInfo\Application;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a batch of accounts against iTop for the administration screen.
 *
 * Each resolve costs one iTop API call, so this only ever runs when an
 * administrator asks for it, and never on page load. The batch is capped for
 * the same reason.
 *
 * One failing account is reported and skipped rather than aborting the batch,
 * matching how occ itop:bind behaves: an administrator working through a list
 * should not lose the whole run to one bad record.
 */
class SuggestionService {

	/**
	 * One iTop request per account, so a single click is bounded. The screen
	 * suggests for a selection or a page, both well under this.
	 */
	public const MAX_BATCH = 50;

	public function __construct(
		private IdentityResolver $resolver,
		private BindingConfig $bindingConfig,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array $uids accounts to resolve
	 * @return array uid => result, each with a status and, when resolved, tier fields
	 */
	public function suggest(array $uids): array {
		$excluded = array_fill_keys($this->bindingConfig->getExcludedUsers(), true);
		$results = [];

		foreach (array_slice(array_values(array_unique($uids)), 0, self::MAX_BATCH) as $uid) {
			$uid = (string)$uid;

			// Excluded accounts are deliberately out of scope for matching, so
			// resolving them would both waste an iTop call and offer an action
			// that must never be taken.
			if (isset($excluded[$uid])) {
				$results[$uid] = ['status' => 'excluded'];
				continue;
			}

			$user = $this->userManager->get($uid);
			if ($user === null) {
				$results[$uid] = ['status' => 'unknown'];
				continue;
			}

			try {
				$match = $this->resolver->resolve($user);
				$results[$uid] = [
					'status' => 'resolved',
					'tier' => $match->tier,
					'personId' => $match->personId,
					'userId' => $match->userId,
					'itopName' => $match->itopName,
					'ncName' => $match->ncName,
					'sourceValue' => $match->sourceValue,
					'candidates' => $match->candidates,
					'conflictUid' => $match->conflictUid,
				];
			} catch (Throwable $e) {
				$this->logger->warning('iTop: could not resolve {uid}', [
					'app' => Application::APP_ID,
					'uid' => $uid,
					'exception' => $e,
				]);
				$results[$uid] = ['status' => 'failed', 'message' => $e->getMessage()];
			}
		}

		return $results;
	}
}
