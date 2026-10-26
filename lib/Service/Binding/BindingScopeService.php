<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Searches the things an administrator can narrow the association table to —
 * one account, a Nextcloud group, or a Nextcloud Team — and resolves a chosen
 * scope to the account ids it covers.
 *
 * Teams come from the optional circles app. Every call into it is guarded by
 * isEnabledForAnyone() and wrapped in a try/catch: with circles absent the
 * teams source returns nothing and the picker omits that section, rather than
 * the whole association screen failing.
 */
class BindingScopeService {

	public const SCOPE_ACCOUNT = 'account';
	public const SCOPE_GROUP = 'group';
	public const SCOPE_TEAM = 'team';

	public const SCOPES = [
		self::SCOPE_ACCOUNT,
		self::SCOPE_GROUP,
		self::SCOPE_TEAM,
	];

	private const SUGGESTION_LIMIT = 10;
	private const CIRCLES_APP_ID = 'circles';

	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{accounts: array, groups: array, teams: array, teamsAvailable: bool}
	 */
	public function search(string $term): array {
		$term = trim($term);
		$teamsAvailable = $this->teamsAvailable();

		if ($term === '') {
			return [
				'accounts' => [],
				'groups' => [],
				'teams' => [],
				'teamsAvailable' => $teamsAvailable,
			];
		}

		return [
			'accounts' => $this->searchAccounts($term),
			'groups' => $this->searchGroups($term),
			'teams' => $teamsAvailable ? $this->searchTeams($term) : [],
			'teamsAvailable' => $teamsAvailable,
		];
	}

	/**
	 * Account ids covered by a scope.
	 *
	 * An unknown id yields an empty list, never every account: silently
	 * widening a scope the administrator narrowed is the dangerous direction,
	 * because the next action on this screen may be a bulk exclude.
	 *
	 * @return array list of uids
	 */
	public function resolveUids(string $type, string $id): array {
		if ($id === '') {
			return [];
		}

		return match ($type) {
			self::SCOPE_ACCOUNT => $this->userManager->userExists($id) ? [$id] : [],
			self::SCOPE_GROUP => $this->groupUids($id),
			self::SCOPE_TEAM => $this->teamUids($id),
			default => [],
		};
	}

	public function isKnownScope(string $type): bool {
		return in_array($type, self::SCOPES, true);
	}

	public function teamsAvailable(): bool {
		return $this->appManager->isEnabledForAnyone(self::CIRCLES_APP_ID);
	}

	private function searchAccounts(string $term): array {
		// Search both uid and display name: an admin looking for "test1" is
		// typing the uid, one looking for "Picasso" is typing the name.
		$found = array_merge(
			(array)$this->userManager->search($term, self::SUGGESTION_LIMIT),
			(array)$this->userManager->searchDisplayName($term, self::SUGGESTION_LIMIT),
		);

		$suggestions = [];
		foreach ($found as $user) {
			$uid = $user->getUID();
			$suggestions[$uid] = [
				'type' => self::SCOPE_ACCOUNT,
				'id' => $uid,
				'label' => $user->getDisplayName(),
			];
		}

		return array_values(array_slice($suggestions, 0, self::SUGGESTION_LIMIT));
	}

	private function searchGroups(string $term): array {
		$suggestions = [];
		foreach ((array)$this->groupManager->search($term, self::SUGGESTION_LIMIT) as $group) {
			$suggestions[] = [
				'type' => self::SCOPE_GROUP,
				'id' => $group->getGID(),
				'label' => $group->getDisplayName(),
			];
		}

		return $suggestions;
	}

	private function groupUids(string $gid): array {
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return [];
		}

		return array_values(array_map(
			static fn (IUser $user) => $user->getUID(),
			$group->getUsers(),
		));
	}

	private function searchTeams(string $term): array {
		$needle = mb_strtolower($term);
		$suggestions = [];

		try {
			$manager = Server::get(\OCA\Circles\CirclesManager::class);
			$manager->startSuperSession();

			$probe = new \OCA\Circles\Model\Probes\CircleProbe();
			$probe->filterSingleCircles();
			$probe->filterPersonalCircles();
			$probe->filterHiddenCircles();

			foreach ($manager->getCircles($probe) as $circle) {
				$label = $circle->getDisplayName();
				if (!str_contains(mb_strtolower($label), $needle)) {
					continue;
				}
				$suggestions[] = [
					'type' => self::SCOPE_TEAM,
					'id' => $circle->getSingleId(),
					'label' => $label,
				];
				if (count($suggestions) >= self::SUGGESTION_LIMIT) {
					break;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('iTop: could not search teams', ['exception' => $e]);
			return [];
		}

		return $suggestions;
	}

	private function teamUids(string $singleId): array {
		if (!$this->teamsAvailable()) {
			return [];
		}

		try {
			$manager = Server::get(\OCA\Circles\CirclesManager::class);
			$manager->startSuperSession();

			$uids = [];
			foreach ($manager->getCircle($singleId)->getInheritedMembers() as $member) {
				// Teams can contain groups and other teams; only real accounts
				// belong in a table of Nextcloud accounts.
				if ($member->getUserType() !== \OCA\Circles\Model\Member::TYPE_USER) {
					continue;
				}
				$uids[$member->getUserId()] = true;
			}

			return array_keys($uids);
		} catch (Throwable $e) {
			$this->logger->warning('iTop: could not resolve team members', ['exception' => $e]);
			return [];
		}
	}
}
