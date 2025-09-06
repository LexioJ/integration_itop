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

class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$adminInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');

		$parameters = [
			'admin_instance_url' => $adminInstanceUrl,
		];

		return new TemplateResponse(Application::APP_ID, 'adminSettings', $parameters);
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
