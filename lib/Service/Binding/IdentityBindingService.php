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
use Psr\Log\LoggerInterface;

/**
 * Applies and removes the link between a Nextcloud account and an iTop person.
 *
 * person_id is the authorisation boundary for everything downstream, so every
 * write is logged with who was bound to whom and by which method.
 */
class IdentityBindingService {

	public const METHOD_ADMIN = 'admin';
	public const METHOD_TOKEN = 'token';
	public const METHOD_DISCOVERY = 'discovery';

	public function __construct(
		private BindingConfig $bindingConfig,
		private IUserConfig $userConfig,
		private LoggerInterface $logger,
	) {
	}

	public function isBound(string $uid): bool {
		return $this->userConfig->getValueString($uid, Application::APP_ID, 'person_id', '') !== '';
	}

	/**
	 * @return array{person_id: string, user_id: ?string, method: string, bound_at: int}|null
	 */
	public function getBinding(string $uid): ?array {
		$personId = $this->userConfig->getValueString($uid, Application::APP_ID, 'person_id', '');
		if ($personId === '') {
			return null;
		}

		$userId = $this->userConfig->getValueString($uid, Application::APP_ID, 'user_id', '');

		return [
			'person_id' => $personId,
			'user_id' => $userId !== '' ? $userId : null,
			'method' => $this->userConfig->getValueString($uid, Application::APP_ID, 'binding_method', ''),
			'bound_at' => (int)$this->userConfig->getValueString($uid, Application::APP_ID, 'binding_at', '0'),
		];
	}

	/**
	 * @throws \RuntimeException if the account is excluded from matching
	 */
	public function bind(string $uid, string $personId, ?string $userId, string $method, string $sourceAttribute = ''): void {
		if ($this->bindingConfig->isExcluded($uid)) {
			throw new \RuntimeException('User ' . $uid . ' is excluded from iTop identity binding');
		}

		// Drop derived state first. A crash mid-write must never leave a user_id
		// belonging to a different person than person_id: iTop profiles hang off
		// the User, so a mismatched pair can grant a profile that was never
		// authorised. ProfileService re-derives user_id from person_id when it
		// is absent, so clearing is always recoverable while a stale pair is
		// not. Portal status belongs to the previous identity too, so it is
		// dropped here rather than inherited by the new one.
		$this->clearProfileCache($uid);
		$this->userConfig->deleteUserConfig($uid, Application::APP_ID, 'user_id');

		$this->userConfig->setValueString($uid, Application::APP_ID, 'person_id', $personId);

		if ($userId !== null && $userId !== '') {
			$this->userConfig->setValueString($uid, Application::APP_ID, 'user_id', $userId);
		}

		$this->userConfig->setValueString($uid, Application::APP_ID, 'binding_method', $method);
		$this->userConfig->setValueString($uid, Application::APP_ID, 'binding_at', (string)time());

		$this->logger->info('iTop identity bound: {uid} -> person {personId} via {method}', [
			'app' => Application::APP_ID,
			'uid' => $uid,
			'personId' => $personId,
			'itopUserId' => $userId,
			'method' => $method,
			'sourceAttribute' => $sourceAttribute,
		]);
	}

	public function unbind(string $uid): void {
		foreach (['person_id', 'user_id', 'binding_method', 'binding_at'] as $key) {
			$this->userConfig->deleteUserConfig($uid, Application::APP_ID, $key);
		}

		$this->clearProfileCache($uid);

		$this->logger->info('iTop identity unbound: {uid}', [
			'app' => Application::APP_ID,
			'uid' => $uid,
		]);
	}

	private function clearProfileCache(string $uid): void {
		$this->userConfig->deleteUserConfig($uid, Application::APP_ID, 'is_portal_only');
		$this->userConfig->deleteUserConfig($uid, Application::APP_ID, 'profiles_last_check');
	}
}
