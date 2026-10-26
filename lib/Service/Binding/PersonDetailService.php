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
use OCA\Itop\Service\ProfileService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves iTop person name/email/profile for a whole binding-table page.
 *
 * The binding table itself makes no iTop calls: it reads bindings out of
 * IUserConfig. This is the first thing that does, which is why it batches
 * into at most two queries for the page rather than one pair per row -- 25
 * rows at one round trip each would make this the slowest screen in the app.
 * A third query joins in only when at least one row has no stored user_id
 * (see fetchProfilesByContactId()), so a page where every row has one still
 * costs exactly two.
 */
class PersonDetailService {

	public function __construct(
		private ItopAPIService $itopAPIService,
		private ProfileService $profileService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array $rows rows shaped like BindingListService::list()'s, i.e.
	 *                     each with 'personId' and 'userId' (both ?string)
	 * @return array<string, array{name: string, email: string, profile: string, status: string}>
	 *               keyed by personId; unbound rows are simply absent
	 */
	public function describeMany(array $rows): array {
		[$personIds, $userIdByPerson] = $this->collectIds($rows);

		if ($personIds === []) {
			// No bound row on the page. Skip both queries rather than ask iTop
			// for an empty id list.
			return [];
		}

		try {
			$persons = $this->fetchPersons($personIds);
			$profilesByUserId = $userIdByPerson === []
				? []
				: $this->fetchProfiles(array_values($userIdByPerson));
		} catch (Throwable $e) {
			// iTop being unreachable must not break the table it enriches, and
			// must not look like "these people don't exist" to the admin -- so
			// this is 'unavailable', not 'not_found', and it is logged once
			// rather than once per id.
			$this->logger->warning('Could not resolve iTop person details: {message}', [
				'app' => Application::APP_ID,
				'message' => $e->getMessage(),
			]);
			return $this->unavailable($personIds);
		}

		// Rows bound through the admin table's manual picker never get a stored
		// user_id -- BindingController deliberately passes null there, because
		// guessing one at bind time could attach a User belonging to a
		// different person and grant an unauthorised profile. Resolve those at
		// read time instead, via the User's own link back to its Person. This
		// is a display-time lookup only; nothing here is written back to the
		// binding, so the deliberate null at bind time stands.
		$personsWithoutUserId = array_values(array_diff($personIds, array_keys($userIdByPerson)));
		$profilesByPersonId = [];
		if ($personsWithoutUserId !== []) {
			try {
				$profilesByPersonId = $this->fetchProfilesByContactId($personsWithoutUserId);
			} catch (Throwable $e) {
				// Unlike the two queries above, this one only ever enriches a
				// row that would otherwise be 'unknown' -- it has nothing to
				// mark 'unavailable'. So a failure here is logged and
				// swallowed rather than degrading the whole page: those rows
				// simply stay at the 'unknown' they would have had without
				// this lookup at all.
				$this->logger->warning('Could not resolve iTop profile by contactid: {message}', [
					'app' => Application::APP_ID,
					'message' => $e->getMessage(),
				]);
			}
		}

		$result = [];
		foreach ($personIds as $personId) {
			$key = (string)$personId;
			$fields = $persons[$personId] ?? null;

			if ($fields === null) {
				$result[$key] = ['name' => '', 'email' => '', 'profile' => 'unknown', 'status' => 'not_found'];
				continue;
			}

			$profile = 'unknown';
			$userId = $userIdByPerson[$personId] ?? null;
			if ($userId !== null && isset($profilesByUserId[$userId])) {
				$profile = ProfileService::determinePortalOnly($profilesByUserId[$userId]) ? 'portal' : 'agent';
			} elseif (isset($profilesByPersonId[$personId])) {
				$profile = ProfileService::determinePortalOnly($profilesByPersonId[$personId]) ? 'portal' : 'agent';
			}

			$result[$key] = [
				'name' => trim(($fields['first_name'] ?? '') . ' ' . ($fields['name'] ?? '')),
				'email' => (string)($fields['email'] ?? ''),
				'profile' => $profile,
				'status' => 'ok',
			];
		}

		return $result;
	}

	/**
	 * @param array $rows as received by describeMany()
	 * @return array{0: list<int>, 1: array<int,int>} distinct person ids, and
	 *               the user id to query profiles with for each of them
	 */
	private function collectIds(array $rows): array {
		$personIds = [];
		$userIdByPerson = [];

		foreach ($rows as $row) {
			$personId = $row['personId'] ?? null;
			// Validated here, once, rather than trusted by whatever builds the
			// OQL id list below: these ids end up as OQL literals.
			if (!is_numeric($personId)) {
				continue;
			}
			$personId = (int)$personId;
			$personIds[$personId] = true;

			$userId = $row['userId'] ?? null;
			if (is_numeric($userId) && !isset($userIdByPerson[$personId])) {
				$userIdByPerson[$personId] = (int)$userId;
			}
		}

		return [array_keys($personIds), $userIdByPerson];
	}

	/**
	 * @param list<int> $personIds validated ids, safe to join into OQL
	 * @return array<int, array<string,mixed>> fields keyed by Person id
	 */
	private function fetchPersons(array $personIds): array {
		$response = $this->itopAPIService->appRequest([
			'operation' => 'core/get',
			'class' => 'Person',
			'key' => sprintf('SELECT Person WHERE id IN (%s)', implode(',', $personIds)),
			'output_fields' => 'id,first_name,name,email',
		]);

		// iTop answers a failed call with HTTP 200 and a non-zero `code`, which
		// appRequest() has already turned into an `error` key by the time this
		// runs -- so that key, not the HTTP status and not the presence of
		// `objects`, is what distinguishes a failure here.
		if (isset($response['error'])) {
			throw new \RuntimeException((string)$response['error']);
		}

		// A query that succeeded and matched nothing answers `code: 0` with
		// `objects` set to *null* -- not an empty array and not an absent key.
		// Treating that as a failure is what used to make an id iTop does not
		// have report as 'unavailable' whenever it was the only id on the page,
		// hiding the single broken link the whole 'not_found' state exists to
		// surface. Zero rows is an answer, so it returns one.
		$objects = $response['objects'] ?? null;
		if (!is_array($objects)) {
			return [];
		}

		$byId = [];
		foreach ($objects as $object) {
			$fields = $object['fields'] ?? [];
			if (!isset($fields['id'])) {
				continue;
			}
			$byId[(int)$fields['id']] = $fields;
		}

		return $byId;
	}

	/**
	 * @param list<int> $userIds validated ids, safe to join into OQL
	 * @return array<int, array<int,string>> parsed profile names keyed by User id
	 */
	private function fetchProfiles(array $userIds): array {
		$response = $this->itopAPIService->appRequest([
			'operation' => 'core/get',
			'class' => 'User',
			'key' => sprintf('SELECT User WHERE id IN (%s)', implode(',', $userIds)),
			'output_fields' => 'id,contactid,profile_list',
		]);

		// Same two-step as fetchPersons(): `error` means the call failed, and
		// everything else means it answered.
		if (isset($response['error'])) {
			throw new \RuntimeException((string)$response['error']);
		}

		// Zero matching Users is not a failure either, and here it is barely
		// even news: a person with no User record simply has no profile to
		// report, which describeMany() already renders as 'unknown' when the
		// id is missing from this map. Throwing instead would mark every
		// person on the page 'unavailable' -- claiming iTop could not be
		// reached -- because one of them has no iTop login.
		$objects = $response['objects'] ?? null;
		if (!is_array($objects)) {
			return [];
		}

		$byUserId = [];
		foreach ($objects as $object) {
			$fields = $object['fields'] ?? [];
			if (!isset($fields['id'])) {
				continue;
			}
			// Reuses ProfileService's own parsing of profile_list rather than a
			// second parser that could read iTop's various formats differently.
			$byUserId[(int)$fields['id']] = $this->profileService->parseProfileList($fields['profile_list'] ?? '');
		}

		return $byUserId;
	}

	/**
	 * Resolves a profile for persons that have no stored user_id, by matching
	 * on the User's own `contactid` link back to its Person -- the same
	 * convention ProfileService and IdentityResolver use when binding needs
	 * a User for a Person it was only given the id of.
	 *
	 * Delegates the actual lookup and one-match rule to
	 * fetchSingleUserByContactId(), which BindingController also uses
	 * directly (via findSingleUserIdForPerson()) to store a user_id at
	 * bind time rather than only at display time.
	 *
	 * @param list<int> $personIds validated ids, safe to join into OQL
	 * @return array<int, array<int,string>> parsed profile names keyed by Person id
	 */
	private function fetchProfilesByContactId(array $personIds): array {
		$usersByPerson = $this->fetchSingleUserByContactId($personIds);

		$profilesByPerson = [];
		foreach ($usersByPerson as $personId => $fields) {
			$profilesByPerson[$personId] = $this->profileService->parseProfileList($fields['profile_list'] ?? '');
		}

		return $profilesByPerson;
	}

	/**
	 * The single iTop User whose `contactid` points at each given Person.
	 *
	 * Unlike IdentityResolver::findUserIdForPerson(), which takes
	 * reset($objects) when a Person has more than one matching User, a
	 * Person with more than one match here is left out of the returned map
	 * entirely. The caller there only risks a mis-suggested bind the admin
	 * can reject; the callers here either drive an icon asserting the
	 * person's access level, or store a user_id that later feeds that same
	 * icon -- and a wrong guess in either case reads as a claim about
	 * someone's authorisation, worse than admitting it can't tell.
	 *
	 * @param list<int> $personIds validated ids, safe to join into OQL
	 * @return array<int, array<string,mixed>> fields of the single matching
	 *               User, keyed by Person id; a Person with zero or several
	 *               matches is simply absent
	 */
	private function fetchSingleUserByContactId(array $personIds): array {
		$response = $this->itopAPIService->appRequest([
			'operation' => 'core/get',
			'class' => 'User',
			'key' => sprintf('SELECT User WHERE contactid IN (%s)', implode(',', $personIds)),
			'output_fields' => 'id,contactid,profile_list',
		]);

		// Same two-step as fetchPersons() and fetchProfiles(): `error` means
		// the call failed, everything else means it answered -- including a
		// person with no matching User at all, which simply stays out of the
		// returned map rather than being treated as a failure.
		if (isset($response['error'])) {
			throw new \RuntimeException((string)$response['error']);
		}

		$objects = $response['objects'] ?? null;
		if (!is_array($objects)) {
			return [];
		}

		// Grouped by Person id first, rather than kept straight in the result
		// map, so a Person matched by more than one User can be detected and
		// excluded below instead of silently picking one.
		$usersByPerson = [];
		foreach ($objects as $object) {
			$fields = $object['fields'] ?? [];
			$personId = $fields['contactid'] ?? null;
			if (!isset($fields['id']) || !is_numeric($personId)) {
				continue;
			}
			$usersByPerson[(int)$personId][] = $fields;
		}

		$result = [];
		foreach ($usersByPerson as $personId => $users) {
			if (count($users) !== 1) {
				// Ambiguous: leave this person out. Callers treat absence the
				// same as a Person with no User at all.
				continue;
			}
			$result[$personId] = $users[0];
		}

		return $result;
	}

	/**
	 * The one iTop User id for a Person, for callers that need the id alone
	 * rather than a profile -- BindingController, when an administrator links
	 * a Person through the manual picker and no user_id came with the match.
	 *
	 * Applies the same one-match rule as the profile lookup above: a Person
	 * linked to more than one User is ambiguous, and storing a guess risks
	 * attaching a User -- and the profile it carries -- that belongs to
	 * someone else. A Person with no User at all simply has no id to store.
	 *
	 * @param string $personId the iTop Person id
	 * @return ?string the single matching User id, or null if there is none
	 *               or more than one
	 */
	public function findSingleUserIdForPerson(string $personId): ?string {
		if (!is_numeric($personId)) {
			return null;
		}

		$users = $this->fetchSingleUserByContactId([(int)$personId]);
		$fields = $users[(int)$personId] ?? null;

		return $fields !== null && isset($fields['id']) ? (string)$fields['id'] : null;
	}

	/**
	 * @param list<int> $personIds
	 * @return array<string, array{name: string, email: string, profile: string, status: string}>
	 */
	private function unavailable(array $personIds): array {
		$result = [];
		foreach ($personIds as $personId) {
			$result[(string)$personId] = ['name' => '', 'email' => '', 'profile' => 'unknown', 'status' => 'unavailable'];
		}
		return $result;
	}
}
