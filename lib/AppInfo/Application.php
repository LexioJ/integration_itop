<?php

/**
 * Nextcloud - iTop
 *
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\AppInfo;

use Closure;
use OCA\Itop\Dashboard\ItopWidget;
use OCA\Itop\Dashboard\ItopAgentWidget;
use OCA\Itop\Listener\ItopReferenceListener;
use OCA\Itop\Notification\Notifier;
use OCA\Itop\Reference\ItopReferenceProvider;
use OCA\Itop\Search\ItopSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\INavigationManager;

use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_itop';

	/**
	 * Supported CI classes for browsing, search, and preview
	 */
	public const SUPPORTED_CI_CLASSES = [
		'PC', 'Phone', 'IPPhone', 'MobilePhone', 'Tablet',
		'Printer', 'Peripheral', 'PCSoftware', 'OtherSoftware', 'WebApplication', 'Software'
	];

	/**
	 * CI class configuration states
	 */
	public const CI_CLASS_STATE_DISABLED = 'disabled';
	public const CI_CLASS_STATE_FORCED = 'forced';          // Enabled for all users (no opt-out)
	public const CI_CLASS_STATE_USER_CHOICE = 'user_choice'; // Enabled but users can opt-out

	/**
	 * Notification configuration states (same as CI classes)
	 */
	public const NOTIFICATION_STATE_DISABLED = 'disabled';      // Not available, never sent
	public const NOTIFICATION_STATE_FORCED = 'forced';          // Mandatory for all users (no opt-out)
	public const NOTIFICATION_STATE_USER_CHOICE = 'user_choice'; // Enabled but users can opt-out

	/**
	 * Portal notification types (shortened values without notify_ prefix)
	 */
	public const PORTAL_NOTIFICATION_TYPES = [
		'ticket_status_changed',
		'agent_responded',
		'ticket_resolved',
		'agent_assigned'
	];

	/**
	 * Agent notification types (shortened values without notify_ prefix)
	 */
	public const AGENT_NOTIFICATION_TYPES = [
		'ticket_assigned',
		'ticket_reassigned',
		'team_unassigned_new',
		'ticket_tto_warning',
		'ticket_ttr_warning',
		'ticket_sla_breach',
		'ticket_priority_critical',
		'ticket_comment'
	];

	private IConfig $config;

	/**
	 * Get app version from info.xml
	 *
	 * @param IAppManager $appManager Nextcloud app manager
	 * @return string App version
	 */
	public static function getVersion(IAppManager $appManager): string {
		return $appManager->getAppVersion(self::APP_ID);
	}

	/**
	 * Get CI class configuration from admin settings
	 * Returns array mapping class names to their state (disabled/forced/user_choice)
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array Map of class name => state
	 */
	public static function getCIClassConfig(IConfig $config): array {
		$configJson = $config->getAppValue(self::APP_ID, 'ci_class_config', '');

		if ($configJson === '') {
			// Default: all classes disabled (opt-in model)
			$defaultConfig = [];
			foreach (self::SUPPORTED_CI_CLASSES as $class) {
				$defaultConfig[$class] = self::CI_CLASS_STATE_DISABLED;
			}
			return $defaultConfig;
		}

		$classConfig = json_decode($configJson, true);
		if (!is_array($classConfig)) {
			// Fallback on invalid JSON
			$defaultConfig = [];
			foreach (self::SUPPORTED_CI_CLASSES as $class) {
				$defaultConfig[$class] = self::CI_CLASS_STATE_DISABLED;
			}
			return $defaultConfig;
		}

		// Ensure all supported classes have a state
		foreach (self::SUPPORTED_CI_CLASSES as $class) {
			if (!isset($classConfig[$class])) {
				$classConfig[$class] = self::CI_CLASS_STATE_DISABLED;
			}
		}

		return $classConfig;
	}

	/**
	 * Get list of admin-enabled CI classes (forced + user_choice)
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array List of enabled CI class names
	 */
	public static function getEnabledCIClasses(IConfig $config): array {
		$classConfig = self::getCIClassConfig($config);
		$enabled = [];

		foreach ($classConfig as $class => $state) {
			if ($state === self::CI_CLASS_STATE_FORCED || $state === self::CI_CLASS_STATE_USER_CHOICE) {
				$enabled[] = $class;
			}
		}

		return $enabled;
	}

	/**
	 * Get list of classes where users can opt-out
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array List of CI class names with user_choice state
	 */
	public static function getUserChoiceCIClasses(IConfig $config): array {
		$classConfig = self::getCIClassConfig($config);
		$userChoice = [];

		foreach ($classConfig as $class => $state) {
			if ($state === self::CI_CLASS_STATE_USER_CHOICE) {
				$userChoice[] = $class;
			}
		}

		return $userChoice;
	}

	/**
	 * Get effective enabled CI classes for a specific user
	 * Forced classes: always included
	 * User-choice classes: included unless user disabled them
	 *
	 * @param IConfig $config Nextcloud config service
	 * @param string $userId Nextcloud user ID
	 * @return array List of effective enabled CI class names for this user
	 */
	public static function getEffectiveEnabledCIClasses(IConfig $config, string $userId): array {
		$classConfig = self::getCIClassConfig($config);
		$effective = [];

		// Get user-disabled classes
		$userDisabledJson = $config->getUserValue($userId, self::APP_ID, 'disabled_ci_classes', '');
		$userDisabled = [];
		if ($userDisabledJson !== '') {
			$userDisabled = json_decode($userDisabledJson, true);
			if (!is_array($userDisabled)) {
				$userDisabled = [];
			}
		}

		foreach ($classConfig as $class => $state) {
			if ($state === self::CI_CLASS_STATE_FORCED) {
				// Forced classes: always enabled, user can't opt-out
				$effective[] = $class;
			} elseif ($state === self::CI_CLASS_STATE_USER_CHOICE) {
				// User-choice classes: enabled unless user disabled it
				if (!in_array($class, $userDisabled, true)) {
					$effective[] = $class;
				}
			}
			// disabled state: skip
		}

		return $effective;
	}

	/**
	 * Get portal notification configuration from admin settings
	 * Returns array mapping notification types to their state (disabled/forced/user_choice)
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array Map of notification type => state
	 */
	public static function getPortalNotificationConfig(IConfig $config): array {
		$configJson = $config->getAppValue(self::APP_ID, 'portal_notification_config', '');

		if ($configJson === '') {
			// Default: all types enabled as user_choice
			$defaultConfig = [];
			foreach (self::PORTAL_NOTIFICATION_TYPES as $type) {
				$defaultConfig[$type] = self::NOTIFICATION_STATE_USER_CHOICE;
			}
			return $defaultConfig;
		}

		$notificationConfig = json_decode($configJson, true);
		if (!is_array($notificationConfig)) {
			// Fallback on invalid JSON
			$defaultConfig = [];
			foreach (self::PORTAL_NOTIFICATION_TYPES as $type) {
				$defaultConfig[$type] = self::NOTIFICATION_STATE_USER_CHOICE;
			}
			return $defaultConfig;
		}

		// Ensure all supported types have a state
		foreach (self::PORTAL_NOTIFICATION_TYPES as $type) {
			if (!isset($notificationConfig[$type])) {
				$notificationConfig[$type] = self::NOTIFICATION_STATE_USER_CHOICE;
			}
		}

		return $notificationConfig;
	}

	/**
	 * Get agent notification configuration from admin settings
	 * Returns array mapping notification types to their state (disabled/forced/user_choice)
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array Map of notification type => state
	 */
	public static function getAgentNotificationConfig(IConfig $config): array {
		$configJson = $config->getAppValue(self::APP_ID, 'agent_notification_config', '');

		if ($configJson === '') {
			// Default: most types user_choice, some disabled, SLA breach/critical forced
			$defaultConfig = [];
			foreach (self::AGENT_NOTIFICATION_TYPES as $type) {
				if ($type === 'team_unassigned_new') {
					$defaultConfig[$type] = self::NOTIFICATION_STATE_DISABLED;
				} elseif (in_array($type, ['ticket_sla_breach', 'ticket_priority_critical'])) {
					$defaultConfig[$type] = self::NOTIFICATION_STATE_FORCED;
				} else {
					$defaultConfig[$type] = self::NOTIFICATION_STATE_USER_CHOICE;
				}
			}
			return $defaultConfig;
		}

		$notificationConfig = json_decode($configJson, true);
		if (!is_array($notificationConfig)) {
			// Fallback on invalid JSON
			$defaultConfig = [];
			foreach (self::AGENT_NOTIFICATION_TYPES as $type) {
				if ($type === 'team_unassigned_new') {
					$defaultConfig[$type] = self::NOTIFICATION_STATE_DISABLED;
				} elseif (in_array($type, ['ticket_sla_breach', 'ticket_priority_critical'])) {
					$defaultConfig[$type] = self::NOTIFICATION_STATE_FORCED;
				} else {
					$defaultConfig[$type] = self::NOTIFICATION_STATE_USER_CHOICE;
				}
			}
			return $defaultConfig;
		}

		// Ensure all supported types have a state
		foreach (self::AGENT_NOTIFICATION_TYPES as $type) {
			if (!isset($notificationConfig[$type])) {
				if ($type === 'team_unassigned_new') {
					$notificationConfig[$type] = self::NOTIFICATION_STATE_DISABLED;
				} elseif (in_array($type, ['ticket_sla_breach', 'ticket_priority_critical'])) {
					$notificationConfig[$type] = self::NOTIFICATION_STATE_FORCED;
				} else {
					$notificationConfig[$type] = self::NOTIFICATION_STATE_USER_CHOICE;
				}
			}
		}

		return $notificationConfig;
	}

	/**
	 * Get list of portal notification types where users can opt-out
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array List of notification types with user_choice state
	 */
	public static function getUserChoicePortalNotifications(IConfig $config): array {
		$notificationConfig = self::getPortalNotificationConfig($config);
		$userChoice = [];

		foreach ($notificationConfig as $type => $state) {
			if ($state === self::NOTIFICATION_STATE_USER_CHOICE) {
				$userChoice[] = $type;
			}
		}

		return $userChoice;
	}

	/**
	 * Get list of agent notification types where users can opt-out
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array List of notification types with user_choice state
	 */
	public static function getUserChoiceAgentNotifications(IConfig $config): array {
		$notificationConfig = self::getAgentNotificationConfig($config);
		$userChoice = [];

		foreach ($notificationConfig as $type => $state) {
			if ($state === self::NOTIFICATION_STATE_USER_CHOICE) {
				$userChoice[] = $type;
			}
		}

		return $userChoice;
	}

	/**
	 * Get list of forced (mandatory) portal notifications
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array List of notification types with forced state
	 */
	public static function getForcedPortalNotifications(IConfig $config): array {
		$notificationConfig = self::getPortalNotificationConfig($config);
		$forced = [];

		foreach ($notificationConfig as $type => $state) {
			if ($state === self::NOTIFICATION_STATE_FORCED) {
				$forced[] = $type;
			}
		}

		return $forced;
	}

	/**
	 * Get list of forced (mandatory) agent notifications
	 *
	 * @param IConfig $config Nextcloud config service
	 * @return array List of notification types with forced state
	 */
	public static function getForcedAgentNotifications(IConfig $config): array {
		$notificationConfig = self::getAgentNotificationConfig($config);
		$forced = [];

		foreach ($notificationConfig as $type => $state) {
			if ($state === self::NOTIFICATION_STATE_FORCED) {
				$forced[] = $type;
			}
		}

		return $forced;
	}

	/**
	 * Get effective enabled portal notifications for a specific user
	 * Forced notifications: always included
	 * User-choice notifications: included unless user disabled them
	 *
	 * @param IConfig $config Nextcloud config service
	 * @param string $userId Nextcloud user ID
	 * @return array List of effective enabled notification types for this user
	 */
	public static function getEffectiveEnabledPortalNotifications(IConfig $config, string $userId): array {
		$notificationConfig = self::getPortalNotificationConfig($config);
		$effective = [];

		// Get user-disabled notifications
		$userDisabledJson = $config->getUserValue($userId, self::APP_ID, 'disabled_portal_notifications', '');
		
		// Check for master toggle off
		if ($userDisabledJson === 'all') {
			// Only forced notifications are active
			return self::getForcedPortalNotifications($config);
		}
		
		$userDisabled = [];
		if ($userDisabledJson !== '') {
			$userDisabled = json_decode($userDisabledJson, true);
			if (!is_array($userDisabled)) {
				$userDisabled = [];
			}
		}

		foreach ($notificationConfig as $type => $state) {
			if ($state === self::NOTIFICATION_STATE_FORCED) {
				// Forced notifications: always enabled, user can't opt-out
				$effective[] = $type;
			} elseif ($state === self::NOTIFICATION_STATE_USER_CHOICE) {
				// User-choice notifications: enabled unless user disabled it
				if (!in_array($type, $userDisabled, true)) {
					$effective[] = $type;
				}
			}
			// disabled state: skip
		}

		return $effective;
	}

	/**
	 * Get effective enabled agent notifications for a specific user
	 * Forced notifications: always included
	 * User-choice notifications: included unless user disabled them
	 *
	 * @param IConfig $config Nextcloud config service
	 * @param string $userId Nextcloud user ID
	 * @return array List of effective enabled notification types for this user
	 */
	public static function getEffectiveEnabledAgentNotifications(IConfig $config, string $userId): array {
		$notificationConfig = self::getAgentNotificationConfig($config);
		$effective = [];

		// Get user-disabled notifications
		$userDisabledJson = $config->getUserValue($userId, self::APP_ID, 'disabled_agent_notifications', '');
		
		// Check for master toggle off
		if ($userDisabledJson === 'all') {
			// Only forced notifications are active
			return self::getForcedAgentNotifications($config);
		}
		
		$userDisabled = [];
		if ($userDisabledJson !== '') {
			$userDisabled = json_decode($userDisabledJson, true);
			if (!is_array($userDisabled)) {
				$userDisabled = [];
			}
		}

		foreach ($notificationConfig as $type => $state) {
			if ($state === self::NOTIFICATION_STATE_FORCED) {
				// Forced notifications: always enabled, user can't opt-out
				$effective[] = $type;
			} elseif ($state === self::NOTIFICATION_STATE_USER_CHOICE) {
				// User-choice notifications: enabled unless user disabled it
				if (!in_array($type, $userDisabled, true)) {
					$effective[] = $type;
				}
			}
			// disabled state: skip
		}

		return $effective;
	}

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->config = $container->get(IConfig::class);

		$manager = $container->get(INotificationManager::class);
		$manager->registerNotifierService(Notifier::class);
	}

	public function register(IRegistrationContext $context): void {
		// Register dashboard widgets with proper IL10N from app container
		$context->registerService(ItopWidget::class, function ($c) {
			return new ItopWidget(
				$c->get(IL10N::class),
				$c->get(IConfig::class),
				$c->get(IURLGenerator::class),
				$c->get(\OCA\Itop\Service\ItopAPIService::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get('userId')
			);
		});
		$context->registerService(ItopAgentWidget::class, function ($c) {
			return new ItopAgentWidget(
				$c->get(IL10N::class),
				$c->get(IConfig::class),
				$c->get(IURLGenerator::class),
				$c->get(\OCA\Itop\Service\ItopAPIService::class),
				$c->get(\OCA\Itop\Service\ProfileService::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get('userId')
			);
		});
		
		$context->registerDashboardWidget(ItopWidget::class);
		$context->registerDashboardWidget(ItopAgentWidget::class);
		$context->registerSearchProvider(ItopSearchProvider::class);

		$context->registerReferenceProvider(ItopReferenceProvider::class);
		$context->registerEventListener(RenderReferenceEvent::class, ItopReferenceListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(Closure::fromCallable([$this, 'registerNavigation']));
	}

	public function registerNavigation(IUserSession $userSession): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			$container = $this->getContainer();

			if ($this->config->getUserValue($userId, self::APP_ID, 'navigation_enabled', '0') === '1') {
				$itopUrl = $this->config->getUserValue($userId, self::APP_ID, 'url', '');
				if ($itopUrl !== '') {
					$container->get(INavigationManager::class)->add(function () use ($container, $itopUrl) {
						$urlGenerator = $container->get(IURLGenerator::class);
						$l10n = $container->get(IL10N::class);
						return [
							'id' => self::APP_ID,

							'order' => 10,

							// the route that will be shown on startup
							'href' => $itopUrl,

							// the icon that will be shown in the navigation
							// this file needs to exist in img/
							'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),

							// the title of your application. This will be used in the
							// navigation or on the settings page of your app
							'name' => $l10n->t('iTop'),
						];
					});
				}
			}
		}
	}
}
