<?php

/**
 * Nextcloud - iTop
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Controller;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\ItopAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

use Psr\Log\LoggerInterface;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ICrypto $crypto,
		private IL10N $l10n,
		private ItopAPIService $itopAPIService,
		private LoggerInterface $logger,
		private ?string $userId
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Set user configuration values
	 *
	 * @NoAdminRequired
	 *
	 * @param array $values key/value pairs to store in user preferences
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	public function setConfig(array $values): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		foreach ($values as $key => $value) {
			if ($key === 'token') {
				if ($value === '') {
					$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
				} else {
					$encryptedToken = $this->crypto->encrypt($value);
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $encryptedToken);
				}
			} else {
				$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
			}
		}

		// Test the connection if token was provided
		if (isset($values['token']) && $values['token'] !== '') {
			try {
				$userInfo = $this->itopAPIService->getCurrentUser($this->userId);
				if (isset($userInfo['error'])) {
					return new DataResponse(['message' => $userInfo['error']], Http::STATUS_BAD_REQUEST);
				}
				$result = ['message' => $this->l10n->t('iTop connection successful')];
			} catch (\Exception $e) {
				$this->logger->error('Error testing iTop connection: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				return new DataResponse(['message' => $this->l10n->t('Failed to connect to iTop: ') . $e->getMessage()], Http::STATUS_BAD_REQUEST);
			}
		} else {
			$result = ['message' => $this->l10n->t('Configuration saved')];
		}

		return new DataResponse($result);
	}

	/**
	 * Set admin configuration values
	 *
	 * @param array $values key/value pairs to store in admin preferences
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if ($key === 'admin_instance_url') {
				// Validate URL format
				if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
					return new DataResponse(['message' => $this->l10n->t('Invalid URL format')], Http::STATUS_BAD_REQUEST);
				}
			}
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}

		return new DataResponse(['message' => $this->l10n->t('Admin configuration saved')]);
	}
}
