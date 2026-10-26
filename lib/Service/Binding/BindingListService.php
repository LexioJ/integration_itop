<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCA\Itop\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Lists Nextcloud users together with their iTop binding state.
 *
 * Enumerates every user to compute filtered totals: "how many accounts are
 * still unbound" cannot be answered from a single page, and a page-local
 * answer would under-report. That is fine at helpdesk scale and matches what
 * occ itop:bind already does; it is recorded as a known limit in the spec.
 */
class BindingListService {

	public const FILTER_ALL = 'all';
	public const FILTER_UNBOUND = 'unbound';
	public const FILTER_BOUND = 'bound';
	public const FILTER_EXCLUDED = 'excluded';

	public const FILTERS = [
		self::FILTER_ALL,
		self::FILTER_UNBOUND,
		self::FILTER_BOUND,
		self::FILTER_EXCLUDED,
	];

	public function __construct(
		private BindingConfig $bindingConfig,
		private IUserManager $userManager,
		private IUserConfig $userConfig,
		private IdentityResolver $identityResolver,
	) {
	}

	/**
	 * @param ?array $restrictUids when given, only these uids are considered
	 * @return array{total: int, page: int, pageSize: int, rows: array}
	 */
	public function list(string $filter, string $search, int $page, int $pageSize, ?array $restrictUids = null): array {
		if (!in_array($filter, self::FILTERS, true)) {
			$filter = self::FILTER_ALL;
		}
		$page = max(1, $page);
		$pageSize = max(1, min(500, $pageSize));

		// Hash set, not in_array: a group can have thousands of members and
		// this runs once per account on the instance.
		$restrict = $restrictUids === null ? null : array_fill_keys($restrictUids, true);

		// Read once, not per account: BindingConfig::isExcluded() re-reads and
		// json_decodes the whole exclusion list on every call, so asking it per
		// row meant one decode per account on the instance.
		$excluded = array_fill_keys($this->bindingConfig->getExcludedUsers(), true);

		// Four reads total instead of four per account: on a 20k-account
		// instance that is the difference between ~80k queries and 4 to then
		// throw all but a page away.
		$personIds = $this->userConfig->getValuesByUsers(Application::APP_ID, 'person_id');
		$userIds = $this->userConfig->getValuesByUsers(Application::APP_ID, 'user_id');
		$methods = $this->userConfig->getValuesByUsers(Application::APP_ID, 'binding_method');
		$boundAts = $this->userConfig->getValuesByUsers(Application::APP_ID, 'binding_at');

		$rows = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$rows, $filter, $search, $restrict, $excluded, $personIds, $userIds, $methods, $boundAts) {
			// Checked before describe(), which costs four array lookups.
			if ($restrict !== null && !isset($restrict[$user->getUID()])) {
				return;
			}

			$row = $this->describe($user, $excluded, $personIds, $userIds, $methods, $boundAts);

			if (!$this->matchesSearch($row, $search)) {
				return;
			}
			if (!$this->matchesFilter($row, $filter)) {
				return;
			}

			$rows[] = $row;
		});

		// Stable order: without it, paging can repeat or skip rows between calls.
		usort($rows, static fn (array $a, array $b) => strcmp($a['uid'], $b['uid']));

		return [
			'total' => count($rows),
			'page' => $page,
			'pageSize' => $pageSize,
			'rows' => array_values(array_slice($rows, ($page - 1) * $pageSize, $pageSize)),
		];
	}

	/**
	 * @param array<string,true> $excluded hash set of excluded uids, read once by list()
	 * @param array<string,mixed> $personIds uid => person_id, read once by list()
	 * @param array<string,mixed> $userIds uid => user_id, read once by list()
	 * @param array<string,mixed> $methods uid => binding_method, read once by list()
	 * @param array<string,mixed> $boundAts uid => binding_at, read once by list()
	 * @return array{uid: string, displayName: string, email: string, bound: bool,
	 *               personId: ?string, userId: ?string, method: ?string,
	 *               boundAt: ?int, excluded: bool, sourceValue: string}
	 */
	private function describe(IUser $user, array $excluded, array $personIds, array $userIds, array $methods, array $boundAts): array {
		$uid = $user->getUID();

		$personId = (string)($personIds[$uid] ?? '');
		$userId = (string)($userIds[$uid] ?? '');
		$method = (string)($methods[$uid] ?? '');
		$boundAt = (string)($boundAts[$uid] ?? '');

		return [
			'uid' => $uid,
			'displayName' => $user->getDisplayName(),
			'email' => (string)$user->getSystemEMailAddress(),
			'bound' => $personId !== '',
			'personId' => $personId !== '' ? $personId : null,
			'userId' => $userId !== '' ? $userId : null,
			'method' => $method !== '' ? $method : null,
			'boundAt' => $boundAt !== '' ? (int)$boundAt : null,
			'excluded' => isset($excluded[$uid]),
			// The tier column needs to show what is actually being compared
			// when the rule matches on uid or display name, not just email.
			'sourceValue' => $this->identityResolver->sourceValueFor($user),
		];
	}

	private function matchesSearch(array $row, string $search): bool {
		$needle = trim(mb_strtolower($search));
		if ($needle === '') {
			return true;
		}

		foreach (['uid', 'displayName', 'email'] as $field) {
			if (str_contains(mb_strtolower((string)$row[$field]), $needle)) {
				return true;
			}
		}

		return false;
	}

	private function matchesFilter(array $row, string $filter): bool {
		return match ($filter) {
			// An excluded account is not an unbound candidate: it is deliberately
			// out of scope for matching, so listing it as work-to-do is wrong.
			self::FILTER_UNBOUND => !$row['bound'] && !$row['excluded'],
			self::FILTER_BOUND => $row['bound'],
			self::FILTER_EXCLUDED => $row['excluded'],
			default => true,
		};
	}
}
