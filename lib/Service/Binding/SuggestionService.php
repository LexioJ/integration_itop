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
		private IdentityBindingService $bindingService,
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

	/**
	 * Re-resolve each account and bind the ones that come back exact.
	 *
	 * Re-resolving rather than trusting what the client last saw is the whole
	 * safety property: a tier the browser is holding may be minutes old, and a
	 * personId in a request body is simply an assertion. Nothing here reads an
	 * identity from the caller -- the only argument is a list of accounts.
	 *
	 * @param array $uids accounts to apply
	 * @return array uid => outcome
	 */
	public function apply(array $uids): array {
		$results = [];

		foreach ($this->suggest($uids) as $uid => $result) {
			if (($result['status'] ?? '') !== 'resolved') {
				$results[$uid] = $result;
				continue;
			}

			if ($result['tier'] !== MatchResult::TIER_EXACT) {
				// Deliberate: a bulk action never applies a row a human has not
				// looked at. "Apply exact only" is the behaviour, not the label.
				$results[$uid] = ['status' => 'skipped', 'tier' => $result['tier']];
				continue;
			}

			// suggest() grades accounts that are already bound, so they arrive
			// here too. Re-binding one would silently move an existing link to
			// whatever resolves today -- the dangerous direction. Moving a
			// person to another account stays an explicit unlink-then-apply.
			if ($this->bindingService->isBound($uid)) {
				$results[$uid] = ['status' => 'already_bound'];
				continue;
			}

			try {
				$this->bindingService->bind(
					$uid,
					$result['personId'],
					$result['userId'],
					IdentityBindingService::METHOD_ADMIN,
					$this->bindingConfig->getMatchSource(),
				);
				$results[$uid] = ['status' => 'bound', 'personId' => $result['personId']];
			} catch (Throwable $e) {
				// Reachable only by a race: an exclusion added or the person
				// taken between resolving and writing. bind() is the single
				// write chokepoint and refuses both, so the batch reports the
				// row and moves on rather than losing the whole run.
				$this->logger->warning('iTop: could not bind {uid}', [
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
