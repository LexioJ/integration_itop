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
 * Admin-side binding is always available and therefore has no toggle. Only the
 * two self-service paths can be switched off.
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

	public function isTokenBindingEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'binding_token_enabled', '1') === '1';
	}

	public function isDiscoveryEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'binding_discovery_enabled', '0') === '1';
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
	 * @return string[] Nextcloud user ids excluded from matching
	 */
	public function getExcludedUsers(): array {
		$json = $this->appConfig->getValueString(Application::APP_ID, 'binding_excluded_users', '');
		if ($json === '') {
			return [];
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_filter($decoded, 'is_string'));
	}

	public function isExcluded(string $uid): bool {
		return in_array($uid, $this->getExcludedUsers(), true);
	}

	public function setExcluded(string $uid, bool $excluded): void {
		$current = $this->getExcludedUsers();
		$next = $excluded
			? array_values(array_unique([...$current, $uid]))
			: array_values(array_diff($current, [$uid]));

		$this->appConfig->setValueString(Application::APP_ID, 'binding_excluded_users', json_encode($next));
	}
}
