<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCA\Itop\AppInfo\Application;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Self-service discovery: a user links their own account without an
 * administrator and without an iTop personal token, which portal-only users
 * cannot obtain.
 *
 * This is the one binding route where an untrusted user's own attribute
 * decides which iTop identity they receive, and person_id is the
 * authorisation boundary for everything downstream. That is why it is opt-in
 * and off by default, and why the properties below are structural rather than
 * validated:
 *
 *  - Nothing is accepted from the caller but the signed-in IUser. The value
 *    matched on is read off the account by IdentityResolver, so there is
 *    nothing to forge and no way to probe iTop for which addresses exist.
 *  - confirm() re-resolves instead of trusting what the browser was shown,
 *    and binds only on an exact match. A user confirming a name mismatch
 *    about themselves is not a meaningful check; an administrator comparing
 *    two names is.
 *  - A non-exact result carries its tier and nothing else. Naming the matched
 *    person to a user who may not be that person is a disclosure, and it is
 *    exactly what an attacker probing for who holds an address would want.
 *  - An account that is already bound is never re-bound. Otherwise a user
 *    binds, changes the attribute the match is made on, and re-binds to
 *    somebody else; moving a link stays an administrator's unlink.
 */
class DiscoveryService {

	public function __construct(
		private IdentityResolver $resolver,
		private BindingConfig $bindingConfig,
		private IdentityBindingService $bindingService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What, if anything, this account may link itself to.
	 *
	 * @return array one of:
	 *               ['status' => 'disabled'|'already_bound'|'excluded'|'failed'],
	 *               ['status' => 'proposal', 'personId' => string, 'itopName' => string],
	 *               ['status' => 'not_self_serviceable', 'tier' => string]
	 */
	public function discover(IUser $user): array {
		$refusal = $this->refuse($user);
		if ($refusal !== null) {
			return $refusal;
		}

		$match = $this->resolve($user);
		if (!$match instanceof MatchResult) {
			return $match;
		}

		if (!$match->isBindable()) {
			return $this->notSelfServiceable($match);
		}

		return [
			'status' => 'proposal',
			'personId' => $match->personId,
			'itopName' => $match->itopName,
		];
	}

	/**
	 * Link this account to the person it re-resolves to, if that is exact.
	 *
	 * Takes the user and nothing else. A personId in a request body would have
	 * nowhere to land, which is what stops the tier system being bypassed by
	 * posting an identity the resolver never agreed to; and the proposal the
	 * browser is holding may be minutes old, so it is re-derived here rather
	 * than trusted.
	 *
	 * @return array as discover(), plus
	 *               ['status' => 'bound', 'personId' => string, 'itopName' => string]
	 */
	public function confirm(IUser $user): array {
		$refusal = $this->refuse($user);
		if ($refusal !== null) {
			return $refusal;
		}

		$match = $this->resolve($user);
		if (!$match instanceof MatchResult) {
			return $match;
		}

		if (!$match->isBindable()) {
			return $this->notSelfServiceable($match);
		}

		try {
			$this->bindingService->bind(
				$user->getUID(),
				$match->personId,
				$match->userId,
				IdentityBindingService::METHOD_DISCOVERY,
				// The attribute that was actually matched, so the audit line
				// in bind() records what decided this identity.
				$this->bindingConfig->getMatchSource(),
			);
		} catch (Throwable $e) {
			// Reachable by a race: the person taken by another account, or an
			// exclusion added, between resolving and writing. bind() is the
			// single write chokepoint and refuses both. The message names
			// another account and is not shown to an end user.
			$this->logger->warning('iTop: self-service discovery could not bind {uid}', [
				'app' => Application::APP_ID,
				'uid' => $user->getUID(),
				'exception' => $e,
			]);
			return ['status' => 'failed'];
		}

		$this->logger->info('iTop identity bound by self-service discovery: {uid} -> person {personId}', [
			'app' => Application::APP_ID,
			'uid' => $user->getUID(),
			'personId' => $match->personId,
			'sourceAttribute' => $this->bindingConfig->getMatchSource(),
		]);

		return [
			'status' => 'bound',
			'personId' => $match->personId,
			'itopName' => $match->itopName,
		];
	}

	/**
	 * The preconditions both endpoints share.
	 *
	 * @return ?array the refusal, or null if this account may proceed
	 */
	private function refuse(IUser $user): ?array {
		if (!$this->bindingConfig->isDiscoveryEnabled()) {
			return ['status' => 'disabled'];
		}

		$uid = $user->getUID();

		// Excluded accounts are deliberately out of scope for matching, so
		// resolving one would both spend an iTop call and offer an action that
		// must never be taken.
		if ($this->bindingConfig->isExcluded($uid)) {
			return ['status' => 'excluded'];
		}

		if ($this->bindingService->isBound($uid)) {
			return ['status' => 'already_bound'];
		}

		return null;
	}

	/**
	 * @return MatchResult|array the match, or a 'failed' result
	 */
	private function resolve(IUser $user) {
		try {
			return $this->resolver->resolve($user);
		} catch (Throwable $e) {
			// The message can carry the iTop URL and query detail, so it is
			// logged for the administrator and not returned to the user.
			$this->logger->warning('iTop: self-service discovery could not resolve {uid}', [
				'app' => Application::APP_ID,
				'uid' => $user->getUID(),
				'exception' => $e,
			]);
			return ['status' => 'failed'];
		}
	}

	/**
	 * The tier and nothing else -- deliberately not the person, the id or the
	 * account a conflict names.
	 */
	private function notSelfServiceable(MatchResult $match): array {
		return ['status' => 'not_self_serviceable', 'tier' => $match->tier];
	}
}
