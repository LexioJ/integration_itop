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
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;
use Psr\Log\LoggerInterface;

class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private IAppManager $appManager,
	) {
		$this->logger->info('iTop Admin settings constructor called', ['app' => Application::APP_ID]);
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$this->logger->info('iTop Admin getForm() called', ['app' => Application::APP_ID]);

		$adminInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
		$userFacingName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');
		$hasApplicationToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '') !== '';
		$this->logger->info('iTop Admin current config values - URL: ' . $adminInstanceUrl . ', Name: ' . $userFacingName . ', Has Token: ' . ($hasApplicationToken ? 'yes' : 'no'), ['app' => Application::APP_ID]);

		// Get notification configuration (default: 15 minutes)
		$defaultNotificationInterval = (int)$this->config->getAppValue(Application::APP_ID, 'default_notification_interval', '15');
		
		// Get 3-state notification configurations
		$portalNotificationConfig = Application::getPortalNotificationConfig($this->config);
		$agentNotificationConfig = Application::getAgentNotificationConfig($this->config);

		// Get cache TTL values (with defaults matching CacheService)
		$cacheTtlCiPreview = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_ci_preview', '60');
		$cacheTtlTicketInfo = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_ticket_info', '60');
		$cacheTtlSearch = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_search', '30');
		$cacheTtlPicker = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_picker', '60');
		$cacheTtlProfile = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_profile', '1800');

		// Get 3-state CI class configuration
		$ciClassConfig = Application::getCIClassConfig($this->config);

		$parameters = [
			'admin_instance_url' => $adminInstanceUrl,
			'user_facing_name' => $userFacingName,
			'has_application_token' => $hasApplicationToken,
			'last_updated' => date('Y-m-d H:i:s'),
			'version' => Application::getVersion($this->appManager),
			// Notification configuration
			'default_notification_interval' => $defaultNotificationInterval,
			'portal_notification_config' => $portalNotificationConfig,
			'agent_notification_config' => $agentNotificationConfig,
			'portal_notification_types' => Application::PORTAL_NOTIFICATION_TYPES,
			'agent_notification_types' => Application::AGENT_NOTIFICATION_TYPES,
			// Cache configuration
			'cache_ttl_ci_preview' => $cacheTtlCiPreview,
			'cache_ttl_ticket_info' => $cacheTtlTicketInfo,
			'cache_ttl_search' => $cacheTtlSearch,
			'cache_ttl_picker' => $cacheTtlPicker,
			'cache_ttl_profile' => $cacheTtlProfile,
			// CI class configuration
			'ci_class_config' => $ciClassConfig,
			'supported_ci_classes' => Application::SUPPORTED_CI_CLASSES,
			'connected_users' => 0, // Will be populated by JavaScript via AJAX
		];

		return new TemplateResponse(Application::APP_ID, 'adminSettings', $parameters);
	}

	public function getSection(): string {
		$this->logger->info('iTop Admin getSection() called', ['app' => Application::APP_ID]);
		return 'integration_itop';
	}

	public function getPriority(): int {
		return 10;
	}
}
