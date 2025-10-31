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

namespace OCA\Itop\Dashboard;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\ItopAPIService;
use OCP\Dashboard\IWidget;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use Psr\Log\LoggerInterface;

class ItopWidget implements IWidget {

	public function __construct(
		private IL10N $l10n,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private ItopAPIService $itopAPIService,
		private LoggerInterface $logger,
		private ?string $userId
	) {
		$this->logger->info('ItopWidget constructed for user: ' . ($userId ?? 'null'), ['app' => Application::APP_ID]);
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'integration_itop';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		// Use admin-configured display name (e.g., "ServicePoint") instead of hardcoded "iTop"
		$displayName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');
		return $displayName;
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconClass(): string {
		return 'icon-integration_itop';
	}

	/**
	 * @inheritDoc
	 */
	public function getUrl(): ?string {
		if ($this->userId === null) {
			return null;
		}
		
		return $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
	}

	/**
	 * @inheritDoc
	 */
	public function load(): void {
		$this->logger->debug('ItopWidget::load() called for user: ' . ($this->userId ?? 'null'), ['app' => Application::APP_ID]);
		
		if ($this->userId !== null) {
			$personId = $this->config->getUserValue($this->userId, Application::APP_ID, 'person_id', '');
			$this->logger->debug('Person ID for user ' . $this->userId . ': ' . ($personId !== '' ? $personId : 'empty'), ['app' => Application::APP_ID]);
			
			if ($personId !== '') {
				$this->logger->info('Loading iTop dashboard script for user: ' . $this->userId, ['app' => Application::APP_ID]);
				Util::addScript(Application::APP_ID, 'integration_itop-dashboard');
			} else {
				$this->logger->warning('iTop dashboard not loaded: person_id not set for user ' . $this->userId, ['app' => Application::APP_ID]);
			}
		} else {
			$this->logger->warning('iTop dashboard not loaded: userId is null', ['app' => Application::APP_ID]);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(string $userId): array {
		// Return empty array - we're using custom Vue widget instead
		// The Vue widget is loaded via load() method and handles all rendering
		return [];
	}

	private function formatSubText(array $ticket): string {
		$parts = [];
		
		if (!empty($ticket['status'])) {
			$parts[] = $this->l10n->t('Status: %s', [$ticket['status']]);
		}
		
		if (!empty($ticket['agent'])) {
			$parts[] = $this->l10n->t('Agent: %s', [$ticket['agent']]);
		}
		
		return implode(' • ', $parts);
	}

	private function getPriorityIcon(string $priority): string {
		switch (strtolower($priority)) {
			case 'high':
			case '3':
				return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'priority-high.svg'));
			case 'medium':
			case '2':
				return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'priority-medium.svg'));
			case 'low':
			case '1':
				return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'priority-low.svg'));
			default:
				return '';
		}
	}
}
