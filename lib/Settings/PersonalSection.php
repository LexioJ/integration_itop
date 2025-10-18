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
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
	) {
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('integration_itop', 'app-dark.svg');
	}

	public function getID(): string {
		return 'integration_itop';
	}

	public function getName(): string {
		$displayName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');
		return $this->l10n->t('%s integration', [$displayName]);
	}

	public function getPriority(): int {
		return 80;
	}
}
