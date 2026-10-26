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
