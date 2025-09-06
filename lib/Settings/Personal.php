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

namespace OCA\Itop\Settings;

use OCA\Itop\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
		private ?string $userId,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		if ($this->userId === null) {
			return new TemplateResponse(Application::APP_ID, 'personalSettings', []);
		}

		$userUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
		$navigationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'navigation_enabled', '0') === '1';
		$notificationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'notification_enabled', '0') === '1';
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token', '') !== '';

		// Get admin-configured URL as fallback
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');

		$parameters = [
			'url' => $userUrl,
			'admin_url' => $adminUrl,
			'token' => $token,
			'navigation_enabled' => $navigationEnabled,
			'notification_enabled' => $notificationEnabled,
		];

		return new TemplateResponse(Application::APP_ID, 'personalSettings', $parameters);
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
