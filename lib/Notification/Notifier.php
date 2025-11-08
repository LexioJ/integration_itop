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
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {

	public function __construct(
		private IFactory $factory,
		private IURLGenerator $url,
		private IConfig $config,
	) {
	}

	/**
	 * Build ticket URL based on user's portal access level
	 * Duplicated from ItopAPIService::buildTicketUrl() to avoid circular dependency
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $class iTop class (UserRequest, Incident, etc.)
	 * @param string $id Ticket ID
	 * @return string Full ticket URL
	 */
	private function buildTicketUrl(string $userId, string $class, string $id): string {
		$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$itopUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url') ?: $adminItopUrl;

		// Check if user is portal-only
		$isPortalOnly = $this->config->getUserValue($userId, Application::APP_ID, 'is_portal_only', '0') === '1';

		if ($isPortalOnly) {
			// Portal user - use portal URL format
			// Use 'view' for Person objects, 'edit' for tickets
			$operation = $class === 'Person' ? 'view' : 'edit';
			return $itopUrl . '/pages/exec.php/object/' . $operation . '/' . $class . '/' . $id . '?exec_module=itop-portal-base&exec_page=index.php&portal_id=itop-portal';
		} else {
			// Power user - use admin UI URL format
			return $itopUrl . '/pages/UI.php?operation=details&class=' . $class . '&id=' . $id;
		}
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
			// Portal notifications
			case 'ticket_status_changed':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';
				$oldStatus = $p['old_status'] ?? '';
				$newStatus = $p['new_status'] ?? '';

				$notification->setParsedSubject($l->t('Ticket status changed'));
				$notification->setParsedMessage($l->t('Status changed: %s → %s', [$oldStatus, $newStatus]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				// Add clickable link to ticket
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'agent_responded':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';
				$agentName = $p['agent_name'] ?? $l->t('Agent');

				$notification->setParsedSubject($l->t('Agent responded to your ticket'));
				$notification->setParsedMessage($l->t('%s added a response', [$agentName]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				// Add clickable link to ticket
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'ticket_resolved':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';

				$notification->setParsedSubject($l->t('Ticket resolved'));
				$notification->setParsedMessage($l->t('Your ticket has been resolved'));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				// Add clickable link to ticket
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

		case 'agent_assigned':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';
				$oldAgent = $p['old_agent'] ?? $l->t('Unassigned');
				$newAgent = $p['new_agent'] ?? $l->t('Unassigned');

				$notification->setParsedSubject($l->t('Assigned agent changed'));
				$notification->setParsedMessage($l->t('%s → %s', [$oldAgent, $newAgent]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				// Add clickable link to ticket
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			// Agent notifications
			case 'ticket_assigned':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';

				$notification->setParsedSubject($l->t('Ticket assigned to you'));
				$notification->setParsedMessage($l->t('A new ticket has been assigned to you'));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'ticket_reassigned':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';

				$notification->setParsedSubject($l->t('Ticket reassigned to you'));
				$notification->setParsedMessage($l->t('A ticket has been reassigned to you'));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'team_unassigned_new':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';
				$teamName = $p['team_name'] ?? $l->t('Your team');

				$notification->setParsedSubject($l->t('New unassigned ticket in %s', [$teamName]));
				$notification->setParsedMessage($l->t('A new ticket needs assignment in %s', [$teamName]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'ticket_tto_warning':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';
				$level = $p['level'] ?? 24; // hours

				$icon = match($level) {
					24 => '⏰',
					12 => '⚠️',
					4 => '🟠',
					1 => '🔴',
					default => '⚠️'
				};

				$notification->setParsedSubject($icon . ' ' . $l->t('TTO SLA warning: %dh remaining', [$level]));
				$notification->setParsedMessage($l->t('Ticket needs assignment within %d hours', [$level]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'ticket_ttr_warning':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';
				$level = $p['level'] ?? 24; // hours

				$icon = match($level) {
					24 => '⏰',
					12 => '⚠️',
					4 => '🟠',
					1 => '🔴',
					default => '⚠️'
				};

				$notification->setParsedSubject($icon . ' ' . $l->t('TTR SLA warning: %dh remaining', [$level]));
				$notification->setParsedMessage($l->t('Ticket needs resolution within %d hours', [$level]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'ticket_sla_breach':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';
				$slaType = $p['sla_type'] ?? 'SLA';

				$notification->setParsedSubject('🚨 ' . $l->t('%s SLA breached', [$slaType]));
				$notification->setParsedMessage($l->t('Ticket has breached %s SLA deadline', [$slaType]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'ticket_priority_critical':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';

				$notification->setParsedSubject('🔴 ' . $l->t('Ticket escalated to CRITICAL'));
				$notification->setParsedMessage($l->t('Ticket priority changed to critical'));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			case 'ticket_comment':
				$p = $notification->getSubjectParameters();
				$ticketId = $p['ticket_id'] ?? '';
				$ticketClass = $p['ticket_class'] ?? 'UserRequest';
				$commenterName = $p['commenter_name'] ?? $l->t('Someone');
				$logType = $p['log_type'] ?? 'public';

				$typeLabel = $logType === 'private' ? $l->t('private note') : $l->t('comment');

				$notification->setParsedSubject($l->t('New %s on your ticket', [$typeLabel]));
				$notification->setParsedMessage($l->t('%s added a %s', [$commenterName, $typeLabel]));
				$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));
				
				if ($ticketId) {
					$ticketUrl = $this->buildTicketUrl($notification->getUser(), $ticketClass, $ticketId);
					$notification->setLink($ticketUrl);
				}

				return $notification;

			default:
				// Unknown subject => Unknown notification => throw
				throw new InvalidArgumentException();
		}
	}
}
