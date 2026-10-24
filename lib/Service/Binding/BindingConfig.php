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
