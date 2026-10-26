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
use OCA\Itop\Service\Binding\BindingConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\Security\ICrypto;

class Personal implements ISettings {

	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IL10N $l10n,
		private ICrypto $crypto,
		private ?string $userId,
		private IAppManager $appManager,
		private BindingConfig $bindingConfig,
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
		$personId = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'person_id', '');
		$personIdConfigured = $personId !== '';

		$navigationEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'navigation_enabled', '0') === '1';
		$notificationEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'notification_enabled', '0') === '1';
		$searchEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'search_enabled', '1') === '1'; // Default: enabled (opt-out)
		$newsroomMirroringEnabled = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'newsroom_mirroring_enabled', '0') === '1'; // Default: disabled (opt-in)
		
		// Get portal notification preferences (default: enabled)
		$notifyTicketStatusChanged = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'notify_ticket_status_changed', '1') === '1';
		$notifyAgentResponded = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'notify_agent_responded', '1') === '1';
		$notifyTicketResolved = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'notify_ticket_resolved', '1') === '1';

		// Get admin-configured display name and URL
		$displayName = $this->appConfig->getValueString(Application::APP_ID, 'user_facing_name', 'iTop');
		$adminUrl = $this->appConfig->getValueString(Application::APP_ID, 'admin_instance_url', '');

		// Check if admin has configured application token
		$hasApplicationToken = $this->appConfig->getValueString(Application::APP_ID, 'application_token', '') !== '';
		
		// Get CI classes that users can configure (user_choice state)
		$userChoiceCIClasses = Application::getUserChoiceCIClasses($this->appConfig);
		$forcedCIClasses = Application::getForcedCIClasses($this->appConfig);
		
		// Get user's disabled CI classes
		$userDisabledJson = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'disabled_ci_classes', '');
		$userDisabledClasses = [];
		if ($userDisabledJson !== '') {
			$userDisabledClasses = json_decode($userDisabledJson, true);
			if (!is_array($userDisabledClasses)) {
				$userDisabledClasses = [];
			}
		}
		
		// Get notification configuration (3-state system)
		$userChoicePortalNotifications = Application::getUserChoicePortalNotifications($this->appConfig);
		$userChoiceAgentNotifications = Application::getUserChoiceAgentNotifications($this->appConfig);
		$forcedPortalNotifications = Application::getForcedPortalNotifications($this->appConfig);
		$forcedAgentNotifications = Application::getForcedAgentNotifications($this->appConfig);
		
		// Get user's disabled notifications
		$userDisabledPortalNotifications = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'disabled_portal_notifications', '');
		$userDisabledAgentNotifications = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'disabled_agent_notifications', '');
		
		// Parse disabled notification arrays
		$disabledPortalArray = [];
		if ($userDisabledPortalNotifications === 'all') {
			$disabledPortalArray = 'all';
		} elseif ($userDisabledPortalNotifications !== '') {
			$parsed = json_decode($userDisabledPortalNotifications, true);
			if (is_array($parsed)) {
				$disabledPortalArray = $parsed;
			}
		}
		
		$disabledAgentArray = [];
		if ($userDisabledAgentNotifications === 'all') {
			$disabledAgentArray = 'all';
		} elseif ($userDisabledAgentNotifications !== '') {
			$parsed = json_decode($userDisabledAgentNotifications, true);
			if (is_array($parsed)) {
				$disabledAgentArray = $parsed;
			}
		}
		
		// Get user's notification check interval (fallback to admin default)
		$adminDefaultInterval = (int)$this->appConfig->getValueString(Application::APP_ID, 'default_notification_interval', '60');
		$userNotificationInterval = (int)$this->userConfig->getValueString($this->userId, Application::APP_ID, 'notification_check_interval', (string)$adminDefaultInterval);

		$parameters = [
			'display_name' => $displayName,
			'admin_url' => $adminUrl,
			'person_id_configured' => $personIdConfigured,
			'person_id' => $personId, // For display purposes only
			'has_application_token' => $hasApplicationToken,
			'navigation_enabled' => $navigationEnabled,
			'notification_enabled' => $notificationEnabled,
			'search_enabled' => $searchEnabled,
			'newsroom_mirroring_enabled' => $newsroomMirroringEnabled,
			'notify_ticket_status_changed' => $notifyTicketStatusChanged,
			'notify_agent_responded' => $notifyAgentResponded,
			'notify_ticket_resolved' => $notifyTicketResolved,
			'user_choice_ci_classes' => $userChoiceCIClasses,
			'forced_ci_classes' => $forcedCIClasses,
			'user_disabled_ci_classes' => $userDisabledClasses,
			'binding_token_enabled' => $this->bindingConfig->isTokenBindingEnabled(),
			'binding_discovery_enabled' => $this->bindingConfig->isDiscoveryEnabled(),
			// 3-state notification configuration
			'user_choice_portal_notifications' => $userChoicePortalNotifications,
			'user_choice_agent_notifications' => $userChoiceAgentNotifications,
			'forced_portal_notifications' => $forcedPortalNotifications,
			'forced_agent_notifications' => $forcedAgentNotifications,
			'disabled_portal_notifications' => $disabledPortalArray,
			'disabled_agent_notifications' => $disabledAgentArray,
			'notification_check_interval' => $userNotificationInterval,
			'admin_default_interval' => $adminDefaultInterval,
			'version' => Application::getVersion($this->appManager),
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
