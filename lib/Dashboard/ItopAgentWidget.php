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
use OCA\Itop\Service\ProfileService;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IWidget;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use Psr\Log\LoggerInterface;

/**
 * Agent Dashboard Widget
 *
 * Shows operational metrics and queues for iTop agents:
 * - My assigned tickets
 * - Team queue tickets
 * - Escalated tickets
 * - Upcoming changes
 *
 * Only visible to users with agent profiles (is_portal_only = false)
 */
class ItopAgentWidget implements IWidget, IConditionalWidget {

	public function __construct(
		private IL10N $l10n,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private ItopAPIService $itopAPIService,
		private ProfileService $profileService,
		private LoggerInterface $logger,
		private ?string $userId
	) {
		$this->logger->info('ItopAgentWidget constructed for user: ' . ($userId ?? 'null'), ['app' => Application::APP_ID]);
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'integration_itop_agent';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		// Use admin-configured display name with " - Agent" suffix
		$displayName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');
		return $this->l10n->t('%s - Agent', [$displayName]);
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 11; // Display right after the portal widget (order 10)
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
	 * Check if this widget should be enabled for the current user
	 *
	 * @inheritDoc
	 */
	public function isEnabled(): bool {
		if ($this->userId === null) {
			$this->logger->debug('ItopAgentWidget disabled: userId is null', ['app' => Application::APP_ID]);
			return false;
		}

		// Check if user has person_id configured
		$personId = $this->config->getUserValue($this->userId, Application::APP_ID, 'person_id', '');
		if ($personId === '') {
			$this->logger->debug('ItopAgentWidget disabled: person_id not set', [
				'app' => Application::APP_ID,
				'userId' => $this->userId
			]);
			return false;
		}

		// Only show to non-portal users (agents)
		try {
			$isPortalOnly = $this->profileService->isPortalOnly($this->userId);

			if ($isPortalOnly) {
				$this->logger->debug('ItopAgentWidget disabled: user is portal-only', [
					'app' => Application::APP_ID,
					'userId' => $this->userId
				]);
				return false;
			}

			$this->logger->info('ItopAgentWidget enabled for agent user', [
				'app' => Application::APP_ID,
				'userId' => $this->userId
			]);
			return true;

		} catch (\Exception $e) {
			$this->logger->error('ItopAgentWidget: Failed to check profile status', [
				'app' => Application::APP_ID,
				'userId' => $this->userId,
				'error' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function load(): void {
		$this->logger->debug('ItopAgentWidget::load() called for user: ' . ($this->userId ?? 'null'), ['app' => Application::APP_ID]);

		if ($this->userId !== null && $this->isEnabled()) {
			$this->logger->info('Loading iTop agent dashboard script for user: ' . $this->userId, ['app' => Application::APP_ID]);
			Util::addScript(Application::APP_ID, 'integration_itop-agentDashboard');
		} else {
			$this->logger->debug('iTop agent dashboard not loaded: widget not enabled', [
				'app' => Application::APP_ID,
				'userId' => $this->userId
			]);
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
}