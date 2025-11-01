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
use OCP\IConfig;
use OCP\IL10N;
use OCP\INavigationManager;

use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_itop';
	public const VERSION = '1.1.0';

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

	private IConfig $config;

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

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->config = $container->get(IConfig::class);

		$manager = $container->get(INotificationManager::class);
		$manager->registerNotifierService(Notifier::class);
	}

	public function register(IRegistrationContext $context): void {
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
