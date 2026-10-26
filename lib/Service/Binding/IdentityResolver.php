<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\ItopAPIService;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Finds the iTop identity that corresponds to a Nextcloud account.
 *
 * The single matcher behind admin-side binding, the occ command and (later)
 * self-service discovery, so the tier rules and OQL construction cannot drift
 * between those paths.
 *
 * The lookup value is always read from the Nextcloud account here. It is never
 * accepted from a request, which is what stops a user forging an identity or
 * probing iTop for which addresses exist.
 */
class IdentityResolver {

	/**
	 * Class and field for the manual person picker.
	 *
	 * Compile-time constants, not configuration and never anything from a
	 * request: these end up as OQL *identifiers*, and string escaping protects
	 * literals only. They are fixed rather than taken from the match target
	 * because the picker always searches people by name, whatever attribute the
	 * automatic match happens to be configured against.
	 */
	private const SEARCH_CLASS = 'Person';
	private const SEARCH_FIELD = 'friendlyname';

	/**
	 * Ceiling on what the picker may ask iTop for, so no caller can turn the
	 * search into a full directory export.
	 */
	private const SEARCH_MAX_LIMIT = 50;

	public function __construct(
		private BindingConfig $bindingConfig,
		private ItopAPIService $itopAPIService,
		private IdentityBindingService $bindingService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The Nextcloud-side value for the configured source attribute.
	 *
	 * Uses the system email rather than the primary email: the system address
	 * is the one an administrator or the user backend sets.
	 */
	public function sourceValueFor(IUser $user): string {
		return trim(match ($this->bindingConfig->getMatchSource()) {
			BindingConfig::SOURCE_UID => $user->getUID(),
			BindingConfig::SOURCE_DISPLAYNAME => $user->getDisplayName(),
			default => (string)$user->getSystemEMailAddress(),
		});
	}

	/**
	 * @throws \RuntimeException if iTop could not be queried
	 */
	public function resolve(IUser $user): MatchResult {
		$value = $this->sourceValueFor($user);
		if ($value === '') {
			// Nothing to match on. Never query with an empty value: in OQL that
			// would match every record with an empty field.
			return MatchResult::none('');
		}

		$target = $this->bindingConfig->resolveTarget($this->bindingConfig->getMatchTarget());
		$escaped = $this->itopAPIService->escapeOQLString($value);

		$isUserTarget = $target['class'] === 'User';
		$outputFields = $isUserTarget
			? 'id,login,contactid,contactid_friendlyname'
			: 'id,first_name,name,email';

		$response = $this->itopAPIService->appRequest([
			'operation' => 'core/get',
			'class' => $target['class'],
			'key' => sprintf("SELECT %s WHERE %s = '%s'", $target['class'], $target['field'], $escaped),
			'output_fields' => $outputFields,
		]);

		if (isset($response['error'])) {
			$this->logger->warning('Identity resolution failed for {uid}: {error}', [
				'app' => Application::APP_ID,
				'uid' => $user->getUID(),
				'error' => $response['error'],
			]);
			// "We could not reach iTop" is not "nothing matched". Returning
			// TIER_NONE here was safe -- it is never bindable, so an outage
			// could not produce a wrong identity -- but it told the user to ask
			// an administrator about a directory problem that does not exist,
			// and made occ itop:bind report every account as unmatched and exit
			// 0 during an outage.
			//
			// ItopAPIService::appRequest() catches every transport and API
			// failure and always returns an array, so throwing is the only way
			// to distinguish the two. The callers already handle it: both
			// DiscoveryService::resolve() and SuggestionService::suggest()
			// catch Throwable and report their existing 'failed' status, which
			// DiscoveryController renders as "could not be reached" with a 502.
			// The message can carry the iTop URL and query detail, so it reaches
			// the log and the admin-only screens, never an end user's response.
			throw new \RuntimeException('iTop identity resolution failed: ' . $response['error']);
		}

		$objects = $response['objects'] ?? [];
		if (!is_array($objects) || $objects === []) {
			// A genuine no-match: iTop answered, with nothing. iTop sends
			// objects as null for an empty result set, which is why an absent
			// key is not treated as a failure here.
			return MatchResult::none($value);
		}

		$candidates = array_values(array_map(
			fn (array $object) => $this->toCandidate($object, $isUserTarget),
			$objects
		));

		if (count($candidates) > 1) {
			// Never pick a winner. A human decides.
			return MatchResult::ambiguous($candidates, $value);
		}

		$candidate = $candidates[0];
		if ($candidate['person_id'] === '') {
			return MatchResult::none($value);
		}

		// Graded before the name check, because a person who is already taken
		// needs a human decision whichever way the names compare, and because
		// it saves the extra findUserIdForPerson() request on a result that can
		// never be applied. The matching source can be user-mutable (display
		// name and email both are on a stock instance), so without this a user
		// who renames themselves to a colleague resolves as exact against that
		// colleague's person.
		$conflict = $this->bindingService->findConflictingUid($candidate['person_id'], $user->getUID());
		if ($conflict !== null) {
			$this->logger->warning('iTop person {personId} already linked to {other}, not offering it to {uid}', [
				'app' => Application::APP_ID,
				'uid' => $user->getUID(),
				'other' => $conflict,
				'personId' => $candidate['person_id'],
			]);
			return MatchResult::conflict(
				$candidate['person_id'], $candidate['user_id'], $candidate['name'], $value, $conflict
			);
		}

		$userId = $isUserTarget
			? $candidate['user_id']
			: $this->findUserIdForPerson($candidate['person_id']);

		if (!NameMatcher::matches($user->getDisplayName(), $candidate['name'])) {
			return MatchResult::review(
				$candidate['person_id'], $userId, $candidate['name'], $value, $user->getDisplayName()
			);
		}

		return MatchResult::exact($candidate['person_id'], $userId, $candidate['name'], $value);
	}

	/**
	 * People whose iTop name contains $term, for the manual picker.
	 *
	 * Returns the same candidate shape resolve() produces, so the picker and
	 * the ambiguous-tier candidate list render identically.
	 *
	 * $term is the one user-supplied part of this query, so it is escaped as an
	 * OQL literal through the same escapeOQLString() resolve() uses -- there is
	 * deliberately no second query builder here with its own escaping.
	 *
	 * @param string $term free text typed by the administrator
	 * @param int $limit never from a request; capped at SEARCH_MAX_LIMIT
	 * @return list<array{person_id: string, user_id: ?string, name: string}>
	 */
	public function searchPersonsByName(string $term, int $limit = 20): array {
		$term = trim($term);
		if ($term === '') {
			// Never query with an empty term. LIKE '%%' matches every person on
			// the instance, which is a directory dump rather than a search.
			return [];
		}

		$limit = max(1, min($limit, self::SEARCH_MAX_LIMIT));
		$escaped = $this->itopAPIService->escapeOQLString($term);

		$response = $this->itopAPIService->appRequest([
			'operation' => 'core/get',
			'class' => self::SEARCH_CLASS,
			'key' => sprintf(
				"SELECT %s WHERE %s LIKE '%%%s%%'",
				self::SEARCH_CLASS,
				self::SEARCH_FIELD,
				$escaped,
			),
			'output_fields' => 'id,first_name,name,email',
			'limit' => $limit,
		]);

		if (isset($response['error'])) {
			$this->logger->warning('iTop person search failed: {error}', [
				'app' => Application::APP_ID,
				'error' => $response['error'],
			]);
			return [];
		}

		$objects = $response['objects'] ?? [];
		if (!is_array($objects) || $objects === []) {
			return [];
		}

		$candidates = array_values(array_map(
			fn (array $object) => $this->toCandidate($object, false),
			$objects
		));

		// Trimmed again locally: an iTop version that ignores the limit must not
		// turn into an unbounded response.
		return array_slice(array_filter(
			$candidates,
			static fn (array $candidate) => $candidate['person_id'] !== ''
		), 0, $limit);
	}

	/**
	 * @return array{person_id: string, user_id: ?string, name: string}
	 */
	private function toCandidate(array $object, bool $isUserTarget): array {
		$fields = $object['fields'] ?? [];

		if ($isUserTarget) {
			return [
				'person_id' => (string)($fields['contactid'] ?? ''),
				'user_id' => isset($fields['id']) ? (string)$fields['id'] : null,
				'name' => (string)($fields['contactid_friendlyname'] ?? ''),
			];
		}

		$name = trim(($fields['first_name'] ?? '') . ' ' . ($fields['name'] ?? ''));

		return [
			'person_id' => (string)($fields['id'] ?? $object['key'] ?? ''),
			'user_id' => null,
			'name' => $name,
		];
	}

	/**
	 * iTop profiles hang off the User, not the Person, so the User id is what
	 * later drives portal-only detection. A Person with no User is valid and
	 * yields null; ProfileService treats that as portal-only.
	 */
	private function findUserIdForPerson(string $personId): ?string {
		if (!is_numeric($personId)) {
			return null;
		}

		$response = $this->itopAPIService->appRequest([
			'operation' => 'core/get',
			'class' => 'User',
			'key' => 'SELECT User WHERE contactid = ' . (int)$personId,
			'output_fields' => 'id,login',
		]);

		if (isset($response['error']) || empty($response['objects'])) {
			return null;
		}

		$object = reset($response['objects']);
		$id = $object['fields']['id'] ?? null;

		return $id !== null ? (string)$id : null;
	}
}
