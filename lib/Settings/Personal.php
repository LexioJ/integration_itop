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
use OCP\Security\ICrypto;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
		private ICrypto $crypto,
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

		// Check if user has configured their Person ID
		$personId = $this->config->getUserValue($this->userId, Application::APP_ID, 'person_id', '');
		$personIdConfigured = $personId !== '';

		$navigationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'navigation_enabled', '0') === '1';
		$notificationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'notification_enabled', '0') === '1';
		$searchEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'search_enabled', '1') === '1'; // Default: enabled (opt-out)

		// Get admin-configured display name and URL
		$displayName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');

		// Check if admin has configured application token
		$hasApplicationToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '') !== '';

		$parameters = [
			'display_name' => $displayName,
			'admin_url' => $adminUrl,
			'person_id_configured' => $personIdConfigured,
			'person_id' => $personId, // For display purposes only
			'has_application_token' => $hasApplicationToken,
			'navigation_enabled' => $navigationEnabled,
			'notification_enabled' => $notificationEnabled,
			'search_enabled' => $searchEnabled,
		];

		return new TemplateResponse(Application::APP_ID, 'personalSettings', $parameters);
	}

	public function getSection(): string {
		return 'integration_itop';
	}

	public function getPriority(): int {
		return 10;
	}
}
