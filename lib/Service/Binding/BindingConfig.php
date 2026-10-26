<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCA\Itop\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Reads and writes the identity binding settings.
 *
 * Admin-side binding is always available and therefore has no toggle. The two
 * self-service paths -- personal token and discovery -- each have one, read
 * by isTokenBindingEnabled() and isDiscoveryEnabled() respectively. Both were
 * removed once already because nothing read them; see setSelfServiceMethods().
 */
class BindingConfig {

	public const SOURCE_EMAIL = 'email';
	public const SOURCE_UID = 'uid';
	public const SOURCE_DISPLAYNAME = 'displayname';

	public const TARGET_PERSON_EMAIL = 'person.email';
	public const TARGET_PERSON_EMPLOYEE_NUMBER = 'person.employee_number';
	public const TARGET_USER_LOGIN = 'user.login';

	private const SOURCES = [
		self::SOURCE_EMAIL,
		self::SOURCE_UID,
		self::SOURCE_DISPLAYNAME,
	];

	/**
	 * Allowlist of iTop query targets.
	 *
	 * These end up as OQL class and field *identifiers*. String escaping only
	 * protects literals, so an identifier must never come from stored input
	 * without passing through this map.
	 */
	private const TARGETS = [
		self::TARGET_PERSON_EMAIL => ['class' => 'Person', 'field' => 'email'],
		self::TARGET_PERSON_EMPLOYEE_NUMBER => ['class' => 'Person', 'field' => 'employee_number'],
		self::TARGET_USER_LOGIN => ['class' => 'User', 'field' => 'login'],
	];

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	public function getMatchSource(): string {
		$source = $this->appConfig->getValueString(Application::APP_ID, 'binding_match_source', self::SOURCE_EMAIL);
		return in_array($source, self::SOURCES, true) ? $source : self::SOURCE_EMAIL;
	}

	public function getMatchTarget(): string {
		$target = $this->appConfig->getValueString(Application::APP_ID, 'binding_match_target', self::TARGET_PERSON_EMAIL);
		return isset(self::TARGETS[$target]) ? $target : self::TARGET_PERSON_EMAIL;
	}

	/**
	 * @return string[] allowlisted Nextcloud-side source attributes
	 */
	public function getSources(): array {
		return self::SOURCES;
	}

	/**
	 * @return string[] allowlisted iTop-side targets
	 */
	public function getTargets(): array {
		return array_keys(self::TARGETS);
	}

	/**
	 * @throws \InvalidArgumentException if either value is not allowlisted
	 */
	public function setMatchRule(string $source, string $target): void {
		if (!in_array($source, self::SOURCES, true)) {
			throw new \InvalidArgumentException('Unknown match source: ' . $source);
		}
		if (!isset(self::TARGETS[$target])) {
			throw new \InvalidArgumentException('Unknown match target: ' . $target);
		}

		$this->appConfig->setValueString(Application::APP_ID, 'binding_match_source', $source);
		$this->appConfig->setValueString(Application::APP_ID, 'binding_match_target', $target);
	}

	/**
	 * Whether the configured source attribute is one an end user can change
	 * for themselves.
	 *
	 * This is the whole risk surface of self-service discovery: if a user can
	 * edit the attribute the match is made on, they can point it at a
	 * colleague's value and bind to that person. A uid is never mutable --
	 * Nextcloud exposes no setUID or rename API -- so matching on uid is safe
	 * regardless of instance settings.
	 *
	 * The email case mirrors lib/private/User/User.php: allow_user_to_change_email
	 * falls back to allow_user_to_change_display_name, and both default to true,
	 * so a stock instance is mutable.
	 */
	public function isSourceUserMutable(): bool {
		return $this->getSourceMutability()[$this->getMatchSource()];
	}

	/**
	 * Whether an end user can change each allowlisted source attribute
	 * themselves.
	 *
	 * The admin settings UI needs this for every source up front -- not just
	 * the currently-selected one -- so it can warn as soon as the admin picks
	 * a mutable source, before they save. isSourceUserMutable() reads from
	 * this map so the two can never disagree.
	 *
	 * @return array<string,bool> source => whether an end user can change it themselves
	 */
	public function getSourceMutability(): array {
		$displayNameMutable = $this->config->getSystemValueBool('allow_user_to_change_display_name', true);
		$emailMutable = $this->config->getSystemValueBool('allow_user_to_change_email', $displayNameMutable);

		return [
			self::SOURCE_UID => false,
			self::SOURCE_DISPLAYNAME => $displayNameMutable,
			self::SOURCE_EMAIL => $emailMutable,
		];
	}

	/**
	 * @return array{class: string, field: string}
	 * @throws \InvalidArgumentException if the target is not allowlisted
	 */
	public function resolveTarget(string $target): array {
		if (!isset(self::TARGETS[$target])) {
			throw new \InvalidArgumentException('Unknown iTop match target: ' . $target);
		}
		return self::TARGETS[$target];
	}

	/**
	 * Nextcloud user ids excluded from matching.
	 *
	 * Stored lazily. As a non-lazy app-config key the whole JSON list would be
	 * loaded on every request by every user on the instance, and only the admin
	 * binding screens and occ itop:bind ever need it. Every read and write of
	 * this key must pass lazy: true -- a value written lazily and read
	 * non-lazily comes back as the default, i.e. no exclusions at all.
	 *
	 * @return string[]
	 */
	public function getExcludedUsers(): array {
		$json = $this->appConfig->getValueString(Application::APP_ID, 'binding_excluded_users', '', true);
		if ($json === '') {
			return [];
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		// Integers are accepted as well as strings, and normalised back to
		// strings here. A numeric uid such as "1001" is ordinary with an LDAP or
		// SAML backend, and a list written by an older build could have stored
		// one as a JSON number; dropping it would silently pull a deliberately
		// excluded account back into scope for matching.
		$uids = array_filter($decoded, static fn ($uid) => is_string($uid) || is_int($uid));
		return array_values(array_map('strval', $uids));
	}

	/**
	 * Single-account check, for callers that look at one uid.
	 *
	 * A caller iterating many accounts must not use this in a loop: it re-reads
	 * and re-decodes the whole list per call. Read getExcludedUsers() once and
	 * build a hash set instead, as BindingListService::list() does.
	 */
	public function isExcluded(string $uid): bool {
		return in_array($uid, $this->getExcludedUsers(), true);
	}

	public function setExcluded(string $uid, bool $excluded): void {
		$this->setExcludedMany([$uid], $excluded);
	}

	/**
	 * Exclude or restore several accounts in one read-modify-write.
	 *
	 * setExcluded() per uid is 2N config operations for a bulk action, and two
	 * administrators acting at once lose one another's edit. The bulk bar on
	 * the associations screen makes that reachable.
	 *
	 * @param array $uids accounts to change
	 * @param bool $excluded true to exclude, false to restore
	 * @return array the uids actually changed
	 */
	public function setExcludedMany(array $uids, bool $excluded): array {
		$current = $this->getExcludedUsers();
		$set = array_fill_keys($current, true);
		$changed = [];

		foreach ($uids as $uid) {
			$uid = (string)$uid;
			if ($uid === '') {
				continue;
			}
			if ($excluded && !isset($set[$uid])) {
				$set[$uid] = true;
				$changed[] = $uid;
			} elseif (!$excluded && isset($set[$uid])) {
				unset($set[$uid]);
				$changed[] = $uid;
			}
		}

		if ($changed !== []) {
			// strval, because PHP casts a numeric-string array key to an int:
			// $set['1001'] comes back out of array_keys() as the integer 1001,
			// json_encodes as a number, and is then dropped on the next read.
			// The uid would disappear from the exclusion list the moment any
			// other account was excluded -- no badge, no error, no log.
			$this->writeExcludedUsers(array_map('strval', array_keys($set)));
		}

		return $changed;
	}

	/**
	 * Whether the personal-token self-service path may bind a Nextcloud
	 * account to an iTop person.
	 *
	 * Defaults to enabled: every existing install already relies on this
	 * path, and turning it off by default on upgrade would silently strand
	 * users who have not yet bound an account.
	 */
	public function isTokenBindingEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'binding_token_enabled', '1') === '1';
	}

	/**
	 * Whether self-service discovery -- matching a user's own account
	 * attribute against iTop without a token -- is permitted.
	 *
	 * Defaults to disabled: it is the one binding route where an untrusted
	 * user's attribute decides which identity they receive, so an
	 * administrator must opt in deliberately.
	 */
	public function isDiscoveryEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'binding_discovery_enabled', '0') === '1';
	}

	public function setSelfServiceMethods(bool $token, bool $discovery): void {
		$this->appConfig->setValueString(Application::APP_ID, 'binding_token_enabled', $token ? '1' : '0');
		$this->appConfig->setValueString(Application::APP_ID, 'binding_discovery_enabled', $discovery ? '1' : '0');
	}

	/**
	 * Persists the exclusion list. The only place that writes
	 * binding_excluded_users, so the lazy flag and JSON encoding exist once.
	 *
	 * @param string[] $uids
	 */
	private function writeExcludedUsers(array $uids): void {
		$this->appConfig->setValueString(Application::APP_ID, 'binding_excluded_users', json_encode($uids), true);
	}
}
