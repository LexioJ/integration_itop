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
		return $this->l10n->t('iTop tickets');
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
		if ($this->userId !== null) {
			$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token', '');
			if ($token !== '') {
				Util::addScript(Application::APP_ID, 'integration_itop-dashboard');
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(string $userId): array {
		if ($this->config->getUserValue($userId, Application::APP_ID, 'token', '') === '') {
			return [];
		}

		try {
			$tickets = $this->itopAPIService->getAssignedTickets($userId, null, 7);
			if (isset($tickets['error'])) {
				$this->logger->error('Error getting iTop tickets for dashboard: ' . $tickets['error'], ['app' => Application::APP_ID]);
				return [];
			}

			$result = [];
			$itopUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url', '') 
				?: $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');

			foreach ($tickets as $ticket) {
				$result[] = [
					'id' => $ticket['id'],
					'targetUrl' => $itopUrl . '/pages/UI.php?operation=details&class=UserRequest&id=' . $ticket['id'],
					'avatarUrl' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'ticket.svg')),
					'avatarUsername' => '',
					'overlayIconUrl' => $this->getPriorityIcon($ticket['priority'] ?? ''),
					'mainText' => $ticket['title'] ?? '',
					'subText' => $this->formatSubText($ticket),
				];
			}

			return $result;
		} catch (\Exception $e) {
			$this->logger->error('Error getting iTop tickets for dashboard: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return [];
		}
	}

	private function formatSubText(array $ticket): string {
		$parts = [];
		
		if (!empty($ticket['status'])) {
			$parts[] = $this->l10n->t('Status: %s', [$ticket['status']]);
		}
		
		if (!empty($ticket['caller'])) {
			$parts[] = $this->l10n->t('Caller: %s', [$ticket['caller']]);
		}
		
		if (!empty($ticket['creation_date'])) {
			$date = new \DateTime($ticket['creation_date']);
			$parts[] = $this->l10n->t('Created: %s', [$date->format('Y-m-d')]);
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
