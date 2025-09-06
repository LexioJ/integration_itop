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

namespace OCA\Itop\Notification;

use InvalidArgumentException;
use OCA\Itop\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {

	public function __construct(
		private IFactory $factory,
		private IURLGenerator $url,
	) {
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return 'integration_itop';
	}

	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->factory->get('integration_itop')->t('iTop integration');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws InvalidArgumentException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'integration_itop') {
			// Not my app => throw
			throw new InvalidArgumentException();
		}

		$l = $this->factory->get('integration_itop', $languageCode);

		switch ($notification->getSubject()) {
			case 'new_open_tickets':
				$p = $notification->getSubjectParameters();
				$nbOpen = (int) ($p['nbOpen'] ?? 0);
				$link = $p['link'] ?? '';

				$notification->setParsedSubject($l->n('New iTop ticket assigned', 'New iTop tickets assigned', $nbOpen));
				$notification->setParsedMessage($l->n('You have %s new assigned ticket', 'You have %s new assigned tickets', $nbOpen, [$nbOpen]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				if ($link) {
					$notification->setLink($link);
				}

				return $notification;

			default:
				// Unknown subject => Unknown notification => throw
				throw new InvalidArgumentException();
		}
	}
}
