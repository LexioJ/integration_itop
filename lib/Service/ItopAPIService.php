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

namespace OCA\Itop\Service;

use DateTime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use OCA\Itop\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\PreConditionNotMetException;

use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class ItopAPIService {
	private ICache $cache;
	private IClient $client;

	/**
	 * Service to make requests to iTop REST/JSON API
	 *
	 * Architecture:
	 * - Uses dual-token approach for security and Portal user compatibility
	 * - Application token (admin-level) for all API queries
	 * - Personal token (user-provided) for one-time identity verification only
	 * - Person ID stored per user to filter all queries and ensure data isolation
	 */
	public function __construct(
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private INotificationManager $notificationManager,
		private ICrypto $crypto,
		ICacheFactory $cacheFactory,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '_global_info');
	}

	public function getItopUrl(string $userId): string {
		$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		return $this->config->getUserValue($userId, Application::APP_ID, 'url') ?: $adminItopUrl;
	}

	/**
	 * Build ticket URL based on user's portal access level
	 *
	 * Portal-only users get portal URLs (/pages/exec.php/object/edit/...)
	 * Power users (agents/admins) get admin UI URLs (/pages/UI.php?operation=details...)
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $class iTop class (UserRequest, Incident, etc.)
	 * @param string $id Ticket ID
	 * @return string Full ticket URL
	 */
	private function buildTicketUrl(string $userId, string $class, string $id): string {
		$itopUrl = $this->getItopUrl($userId);

		// Check if user is portal-only (cached by ProfileService)
		$isPortalOnly = $this->config->getUserValue($userId, Application::APP_ID, 'is_portal_only', '0') === '1';

		if ($isPortalOnly) {
			// Portal user - use portal URL format
			return $itopUrl . '/pages/exec.php/object/edit/' . $class . '/' . $id . '?exec_module=itop-portal-base&exec_page=index.php&portal_id=itop-portal';
		} else {
			// Power user - use admin UI URL format
			return $itopUrl . '/pages/UI.php?operation=details&class=' . $class . '&id=' . $id;
		}
	}

	/**
	 * Get application token for API requests
	 *
	 * @return string|null Decrypted application token or null if not configured
	 */
	private function getApplicationToken(): ?string {
		$encryptedToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '');

		if (empty($encryptedToken)) {
			return null;
		}

		try {
			return $this->crypto->decrypt($encryptedToken);
		} catch (\Exception $e) {
			$this->logger->error('Failed to decrypt application token: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return null;
		}
	}

	/**
	 * Get person ID for a user
	 *
	 * @param string $userId
	 * @return string|null Person ID or null if not configured
	 */
	private function getPersonId(string $userId): ?string {
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		return $personId !== '' ? $personId : null;
	}

	/**
	 * triggered by a cron job
	 * notifies user of their number of new assigned tickets
	 *
	 * @return void
	 */
	public function checkOpenTickets(): void {
		$this->userManager->callForAllUsers(function (IUser $user) {
			$this->checkOpenTicketsForUser($user->getUID());
		});
	}

	/**
	 * Check open tickets for a user and send notifications
	 *
	 * @param string $userId
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	private function checkOpenTicketsForUser(string $userId): void {
		// Check if user has configured their person_id
		$personId = $this->getPersonId($userId);
		$notificationEnabled = ($this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1');

		if ($personId && $notificationEnabled) {
			$itopUrl = $this->getItopUrl($userId);
			if ($itopUrl) {
				$lastNotificationCheck = $this->config->getUserValue($userId, Application::APP_ID, 'last_open_check');
				$lastNotificationCheck = $lastNotificationCheck === '' ? null : $lastNotificationCheck;

				// Get tickets created by user that are open
				$tickets = $this->getUserCreatedTickets($userId, $lastNotificationCheck);
				if (!isset($tickets['error']) && count($tickets) > 0) {
					$this->config->setUserValue($userId, Application::APP_ID, 'last_open_check', date('Y-m-d H:i:s'));
					$nbOpen = count($tickets);
					if ($nbOpen > 0) {
						$this->sendNCNotification($userId, 'new_open_tickets', [
							'nbOpen' => $nbOpen,
							'link' => $itopUrl
						]);
					}
				} elseif (isset($tickets['error-code']) && $tickets['error-code'] === Http::STATUS_UNAUTHORIZED) {
					// Application token invalid - admin needs to reconfigure
					$this->logger->warning('Application token appears invalid, notifications disabled', ['app' => Application::APP_ID]);
				}
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $params
	 * @return void
	 */
	private function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * Get tickets created by the current user (UserRequest + Incident)
	 *
	 * @param string $userId
	 * @param ?string $since
	 * @param ?int $limit
	 * @return array
	 * @throws PreConditionNotMetException|Exception
	 */
	public function getUserCreatedTickets(string $userId, ?string $since = null, ?int $limit = null): array {
		// Get current user's details first
		$userInfo = $this->getCurrentUser($userId);
		if (isset($userInfo['error'])) {
			return $userInfo;
		}

		// Extract user info - use full name for matching
		$firstName = $userInfo['user']['first_name'] ?? '';
		$lastName = $userInfo['user']['last_name'] ?? '';
		$fullName = trim($firstName . ' ' . $lastName);

		if (empty($fullName)) {
			return [];
		}

		$allTickets = [];

		$itopUrl = $this->getItopUrl($userId);

		// Get person_id for more precise queries
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');

		// Query UserRequest tickets where user is caller OR contact
		// Note: ORDER BY in complex queries with subqueries may not work, so we sort in PHP
		if ($personId) {
			$userRequestQuery = "SELECT UserRequest WHERE (caller_id = '$personId' OR id IN (SELECT UserRequest JOIN lnkContactToTicket ON lnkContactToTicket.ticket_id = UserRequest.id WHERE lnkContactToTicket.contact_id = '$personId')) AND status != 'closed'";
		} else {
			// Fallback to name-based query if no person_id
			$userRequestQuery = "SELECT UserRequest WHERE caller_id_friendlyname = '$fullName' AND status != 'closed'";
		}
		$userRequestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => $userRequestQuery,
			'output_fields' => '*'
		];

		if ($limit) {
			$userRequestParams['limit'] = $limit;
		}

		$userRequestResult = $this->request($userId, $userRequestParams);
		// Debug: log raw API response structure
		if (isset($userRequestResult['objects']) && count($userRequestResult['objects']) > 0) {
			$firstTicket = array_values($userRequestResult['objects'])[0];
			\OC::$server->get(\Psr\Log\LoggerInterface::class)->debug(
				'First UserRequest full structure: ' . json_encode($firstTicket),
				['app' => Application::APP_ID]
			);
		}
		if (isset($userRequestResult['objects'])) {
			foreach ($userRequestResult['objects'] as $objectKey => $ticket) {
				// The ticket ID is in the 'key' field of the ticket object
				$ticketId = $ticket['key'] ?? null;
				if (!$ticketId) {
					// Fallback: extract from object key like "UserRequest::2"
					$ticketId = strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey;
				}
				$allTickets[] = [
					'type' => 'UserRequest',
					'id' => $ticketId,
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'] ?? '',
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'] ?? 'unknown',
					'operational_status' => $ticket['fields']['operational_status'] ?? '',
					'priority' => $ticket['fields']['priority'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'close_date' => $ticket['fields']['close_date'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'UserRequest', $ticketId)
				];
			}
		}

		// Query Incident tickets where user is caller OR contact
		if ($personId) {
			$incidentQuery = "SELECT Incident WHERE (caller_id = '$personId' OR id IN (SELECT Incident JOIN lnkContactToTicket ON lnkContactToTicket.ticket_id = Incident.id WHERE lnkContactToTicket.contact_id = '$personId')) AND status != 'closed'";
		} else {
			// Fallback to name-based query if no person_id
			$incidentQuery = "SELECT Incident WHERE caller_id_friendlyname = '$fullName' AND status != 'closed'";
		}
		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => $incidentQuery,
			'output_fields' => '*'
		];

		if ($limit) {
			$incidentParams['limit'] = $limit;
		}

		$incidentResult = $this->request($userId, $incidentParams);
		if (isset($incidentResult['objects'])) {
			foreach ($incidentResult['objects'] as $objectKey => $ticket) {
				// The ticket ID is in the 'key' field of the ticket object
				$ticketId = $ticket['key'] ?? null;
				if (!$ticketId) {
					// Fallback: extract from object key like "Incident::4"
					$ticketId = strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey;
				}
				$allTickets[] = [
					'type' => 'Incident',
					'id' => $ticketId,
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'] ?? '',
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'] ?? 'unknown',
					'operational_status' => $ticket['fields']['operational_status'] ?? '',
					'priority' => $ticket['fields']['priority'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'close_date' => $ticket['fields']['close_date'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'Incident', $ticketId)
				];
			}
		}

		return $allTickets;
	}

	/**
	 * Get tickets created by the current user grouped by status with counts
	 *
	 * @param string $userId
	 * @return array { by_status: {status=>count}, tickets: {status=>tickets[]} }
	 */
	public function getUserTicketsByStatus(string $userId): array {
		$tickets = $this->getUserCreatedTickets($userId, null, 100);
		$groups = [
			'open' => [],
			'escalated' => [],
			'pending' => [],
			'resolved' => [],
			'closed' => [],
			'unknown' => [],
		];

		$rawStatuses = []; // Debug: collect all raw statuses
		foreach (is_array($tickets) ? $tickets : [] as $ticket) {
			$statusRaw = strtolower($ticket['status'] ?? '');
			$rawStatuses[] = $statusRaw; // Debug
			// Map iTop statuses to dashboard categories
			$status = match (true) {
				// Open statuses
				$statusRaw === 'new' || $statusRaw === 'assigned' || $statusRaw === 'dispatched' || $statusRaw === 'open' => 'open',
				// Escalated statuses
				$statusRaw === 'escalated_tto' || $statusRaw === 'escalated_ttr' || str_contains($statusRaw, 'escalated') => 'escalated',
				// Pending statuses
				$statusRaw === 'pending' || $statusRaw === 'waiting_for_approval' || $statusRaw === 'paused' => 'pending',
				// Resolved statuses
				$statusRaw === 'resolved' || $statusRaw === 'solution_approved' => 'resolved',
				// Closed statuses
				$statusRaw === 'closed' => 'closed',
				// Default: treat as open if not recognized
				default => 'open',
			};
			$groups[$status][] = $ticket;
		}

		$counts = [];
		foreach ($groups as $key => $list) {
			$counts[$key] = count($list);
		}

		// Debug: log raw statuses
		\OC::$server->get(\Psr\Log\LoggerInterface::class)->debug(
			'getUserTicketsByStatus: Raw statuses found: ' . json_encode($rawStatuses),
			['app' => Application::APP_ID]
		);

		return [
			'by_status' => $counts,
			'tickets' => $groups,
		];
	}

	/**
	 * Get count of tickets created by the current user (separate counts for UserRequest and Incident)
	 *
	 * @param string $userId
	 * @return array
	 * @throws Exception
	 */
	public function getUserCreatedTicketsCount(string $userId): array {
		// Get current user's details first
		$userInfo = $this->getCurrentUser($userId);
		if (isset($userInfo['error'])) {
			return ['error' => $userInfo['error']];
		}

		// Extract user info - use full name for matching
		$firstName = $userInfo['user']['first_name'] ?? '';
		$lastName = $userInfo['user']['last_name'] ?? '';
		$fullName = trim($firstName . ' ' . $lastName);

		if (empty($fullName)) {
			return ['incidents' => 0, 'requests' => 0, 'status' => 'no_user'];
		}

		$incidentCount = 0;
		$requestCount = 0;

		// Query UserRequest tickets
		$userRequestQuery = "SELECT UserRequest WHERE caller_id_friendlyname = '$fullName' AND status != 'closed'";
		$userRequestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => $userRequestQuery,
			'output_fields' => 'id'
		];

		$userRequestResult = $this->request($userId, $userRequestParams);
		if (isset($userRequestResult['objects'])) {
			$requestCount = count($userRequestResult['objects']);
		}

		// Query Incident tickets
		$incidentQuery = "SELECT Incident WHERE caller_id_friendlyname = '$fullName' AND status != 'closed'";
		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => $incidentQuery,
			'output_fields' => 'id'
		];

		$incidentResult = $this->request($userId, $incidentParams);
		if (isset($incidentResult['objects'])) {
			$incidentCount = count($incidentResult['objects']);
		}

		// Return separate counts
		return [
			'incidents' => $incidentCount,
			'requests' => $requestCount,
			'total' => $incidentCount + $requestCount,
			'status' => 'success'
		];
	}


	/**
	 * Get current user information
	 *
	 * Portal users can access their own Person record but not the User class.
	 * Strategy: Get a UserRequest to find caller_id (Person ID), then query Person directly.
	 *
	 * @param string $userId
	 * @return array
	 * @throws Exception
	 */
	public function getCurrentUser(string $userId): array {
		// Check if user has configured their Person ID manually
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');

		// If no Person ID configured, try to get it from a UserRequest
		if (!$personId) {
			$ticketParams = [
				'operation' => 'core/get',
				'class' => 'UserRequest',
				'key' => 'SELECT UserRequest LIMIT 1',
				'output_fields' => 'caller_id,caller_id_friendlyname'
			];

			$ticketResult = $this->request($userId, $ticketParams);

			// Extract Person ID from the ticket's caller_id
			if (isset($ticketResult['objects']) && !empty($ticketResult['objects'])) {
				$ticketKey = array_keys($ticketResult['objects'])[0];
				$personId = $ticketResult['objects'][$ticketKey]['fields']['caller_id'] ?? null;
			}
		}

		// If still no Person ID, return helpful error with instructions
		if (!$personId) {
			return [
				'code' => 1,
				'message' => 'Portal user detected: Please configure your Person ID in the settings below, or ensure you have created at least one ticket in iTop.',
				'user' => null
			];
		}

		// Step 2: Query the Person record directly by ID
		// Portal users have permission to read their own Person record
		$personParams = [
			'operation' => 'core/get',
			'class' => 'Person',
			'key' => $personId,
			'output_fields' => 'friendlyname,first_name,name,email,phone,org_id_friendlyname'
		];

		$personResult = $this->request($userId, $personParams);

		// If we got the person record, transform to expected format
		if (isset($personResult['objects']) && !empty($personResult['objects'])) {
			$personKey = array_keys($personResult['objects'])[0];
			$person = $personResult['objects'][$personKey];

			return [
				'code' => 0,
				'message' => 'User authenticated successfully',
				'version' => '1.3',
				'user' => [
					'login' => $person['fields']['email'] ?? 'Unknown', // Portal users don't have login field in Person
					'first_name' => $person['fields']['first_name'] ?? '',
					'last_name' => $person['fields']['name'] ?? '',
					'email' => $person['fields']['email'] ?? '',
					'org_name' => $person['fields']['org_id_friendlyname'] ?? '',
					'phone' => $person['fields']['phone'] ?? '',
					'status' => 'active' // Person records don't have status, assume active
				],
				'privileges' => [] // Portal users - privileges not available from Person class
			];
		}

		// If no person found or error, return the result
		return $personResult;
	}

	/**
	 * Search for tickets and CIs
	 *
	 * @param string $userId
	 * @param string $query Search term
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 * @throws Exception
	 */
	public function search(string $userId, string $query, int $offset = 0, int $limit = 10): array {
		// Get current user's details first
		$userInfo = $this->getCurrentUser($userId);
		if (isset($userInfo['error'])) {
			return $userInfo;
		}

		// Extract user info - use full name for matching
		$firstName = $userInfo['user']['first_name'] ?? '';
		$lastName = $userInfo['user']['last_name'] ?? '';
		$fullName = trim($firstName . ' ' . $lastName);

		if (empty($fullName)) {
			return [];
		}

		// Escape single quotes in search query for OQL
		$escapedQuery = str_replace("'", "\\'", $query);

		$searchResults = [];
		$itopUrl = $this->getItopUrl($userId);

		// Search UserRequests - tickets where user is creator OR assigned agent
		// Also search by ref (ticket ID like R-000001)
		// Note: Contact relationship search added separately below
		$userRequestQuery = 'SELECT UserRequest WHERE '
			. "(caller_id_friendlyname = '$fullName' OR agent_id_friendlyname = '$fullName') "
			. "AND (title LIKE '%$escapedQuery%' OR description LIKE '%$escapedQuery%' OR ref LIKE '%$escapedQuery%')";

		$userRequestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => $userRequestQuery,
			'output_fields' => 'id,ref,title,description,status,priority,caller_id_friendlyname,agent_id_friendlyname,start_date,last_update,close_date'
		];

		if ($limit) {
			$userRequestParams['limit'] = $limit + 5; // Get extra to compensate for Incidents
		}

		$userRequests = $this->request($userId, $userRequestParams);
		if (isset($userRequests['objects'])) {
			foreach ($userRequests['objects'] as $key => $ticket) {
				$searchResults[] = [
					'type' => 'UserRequest',
					'id' => $ticket['fields']['id'],
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'],
					'description' => strip_tags($ticket['fields']['description'] ?? ''),
					'status' => $ticket['fields']['status'],
					'priority' => $ticket['fields']['priority'] ?? '',
					'caller' => $ticket['fields']['caller_id_friendlyname'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'close_date' => $ticket['fields']['close_date'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'UserRequest', $ticket['fields']['id'])
				];
			}
		}

		// Search Incidents - tickets where user is creator OR assigned agent
		// Also search by ref (ticket ID like I-000006)
		// Note: Contact relationship search added separately below
		$incidentQuery = 'SELECT Incident WHERE '
			. "(caller_id_friendlyname = '$fullName' OR agent_id_friendlyname = '$fullName') "
			. "AND (title LIKE '%$escapedQuery%' OR description LIKE '%$escapedQuery%' OR ref LIKE '%$escapedQuery%')";

		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => $incidentQuery,
			'output_fields' => 'id,ref,title,description,status,priority,caller_id_friendlyname,agent_id_friendlyname,start_date,last_update,close_date'
		];

		if ($limit) {
			$incidentParams['limit'] = $limit + 5;
		}

		$incidents = $this->request($userId, $incidentParams);
		if (isset($incidents['objects'])) {
			foreach ($incidents['objects'] as $key => $ticket) {
				$searchResults[] = [
					'type' => 'Incident',
					'id' => $ticket['fields']['id'],
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'],
					'description' => strip_tags($ticket['fields']['description'] ?? ''),
					'status' => $ticket['fields']['status'],
					'priority' => $ticket['fields']['priority'] ?? '',
					'caller' => $ticket['fields']['caller_id_friendlyname'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'close_date' => $ticket['fields']['close_date'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'Incident', $ticket['fields']['id'])
				];
			}
		}

		// Additional search: Find tickets where user is listed as a contact (lnkContactToTicket)
		// This handles cases where user is added to the Contacts tab of a ticket
		$personId = $this->getPersonId($userId);
		if ($personId) {
			// Search for UserRequest contacts
			$contactLinksUR = $this->request($userId, [
				'operation' => 'core/get',
				'class' => 'lnkContactToTicket',
				'key' => "SELECT lnkContactToTicket WHERE contact_id = $personId",
				'output_fields' => 'ticket_id,ticket_ref'
			]);

			if (isset($contactLinksUR['objects']) && !empty($contactLinksUR['objects'])) {
				foreach ($contactLinksUR['objects'] as $link) {
					$ticketId = $link['fields']['ticket_id'] ?? null;
					$ticketRef = $link['fields']['ticket_ref'] ?? '';

					// Only fetch if ref matches search query
					if ($ticketId && (empty($escapedQuery) || stripos($ticketRef, $query) !== false)) {
						// Determine ticket class from ref (R- = UserRequest, I- = Incident)
						$ticketClass = (strpos($ticketRef, 'R-') === 0) ? 'UserRequest'
									   : ((strpos($ticketRef, 'I-') === 0) ? 'Incident' : null);

						if ($ticketClass) {
							$ticketData = $this->request($userId, [
								'operation' => 'core/get',
								'class' => $ticketClass,
								'key' => $ticketId,
								'output_fields' => 'id,ref,title,description,status,priority,caller_id_friendlyname,agent_id_friendlyname,start_date,last_update,close_date'
							]);

							if (isset($ticketData['objects']) && !empty($ticketData['objects'])) {
								$ticket = reset($ticketData['objects']);

								// Check if title/description matches search
								$titleMatch = empty($escapedQuery) || stripos($ticket['fields']['title'] ?? '', $query) !== false;
								$descMatch = empty($escapedQuery) || stripos($ticket['fields']['description'] ?? '', $query) !== false;

								if ($titleMatch || $descMatch || stripos($ticketRef, $query) !== false) {
									$searchResults[] = [
										'type' => $ticketClass,
										'id' => $ticket['fields']['id'],
										'ref' => $ticket['fields']['ref'] ?? '',
										'title' => $ticket['fields']['title'],
										'description' => strip_tags($ticket['fields']['description'] ?? ''),
										'status' => $ticket['fields']['status'],
										'priority' => $ticket['fields']['priority'] ?? '',
										'caller' => $ticket['fields']['caller_id_friendlyname'] ?? '',
										'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
										'start_date' => $ticket['fields']['start_date'] ?? '',
										'last_update' => $ticket['fields']['last_update'] ?? '',
										'close_date' => $ticket['fields']['close_date'] ?? '',
										'url' => $this->buildTicketUrl($userId, $ticketClass, $ticket['fields']['id'])
									];
								}
							}
						}
					}
				}
			}
		}

		// Remove duplicates (ticket might be in both searches)
		$seen = [];
		$searchResults = array_filter($searchResults, function ($ticket) use (&$seen) {
			$key = $ticket['type'] . '-' . $ticket['id'];
			if (isset($seen[$key])) {
				return false;
			}
			$seen[$key] = true;
			return true;
		});

		// Sort by last_update descending (most recent first)
		usort($searchResults, function ($a, $b) {
			return strcmp($b['last_update'] ?? '', $a['last_update'] ?? '');
		});

		return array_slice($searchResults, $offset, $limit);
	}

	/**
	 * Get detailed information about a ticket
	 *
	 * @param string $userId
	 * @param int $ticketId
	 * @return array
	 * @throws PreConditionNotMetException|Exception
	 */
	public function getTicketInfo(string $userId, int $ticketId, string $class = 'UserRequest'): array {
		$params = [
			'operation' => 'core/get',
			'class' => $class,
			'key' => $ticketId,
			'output_fields' => '*'
		];

		return $this->request($userId, $params);
	}

	/**
	 * Get CI preview data for a single CI
	 *
	 * Fetches only the fields needed for preview rendering (defined in docs/class-mapping.md).
	 * Uses profile-aware filtering: Portal-only users get CIs from contacts_list,
	 * power users get full CMDB access within ACL.
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $class iTop CI class (PC, Phone, Tablet, etc.)
	 * @param int $id CI ID
	 * @param bool $isPortalOnly Whether user has only Portal user profile
	 * @return array CI data with preview fields or error
	 * @throws Exception
	 */
	public function getCIPreview(string $userId, string $class, int $id, bool $isPortalOnly = false): array {
		// Define preview fields per class (from docs/class-mapping.md)
		$outputFields = $this->getCIPreviewFields($class);

		// Build query with profile-aware filtering
		if ($isPortalOnly) {
			// Portal-only users: Only CIs where they are listed as contact
			$personId = $this->getPersonId($userId);
			if (!$personId) {
				return ['error' => $this->l10n->t('User not configured')];
			}

			// Query via lnkContactToFunctionalCI to get allowed CIs
			$query = "SELECT $class AS ci JOIN lnkContactToFunctionalCI AS lnk ON lnk.functionalci_id = ci.id WHERE lnk.contact_id = $personId AND ci.id = $id";
			$params = [
				'operation' => 'core/get',
				'class' => $class,
				'key' => $query,
				'output_fields' => $outputFields
			];
		} else {
			// Power users: Full CMDB access within ACL
			// For core/get with simple ID lookup, just pass the ID directly
			$params = [
				'operation' => 'core/get',
				'class' => $class,
				'key' => $id,
				'output_fields' => $outputFields
			];
		}

		return $this->request($userId, $params);
	}

	/**
	 * Search CIs with profile-aware filtering
	 *
	 * Portal-only users: Only CIs where they are listed as contact (contacts_list)
	 * Power users: Full CMDB access within ACL
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $term Search term (searches name, serialnumber, asset_number)
	 * @param array $classes CI classes to search (default: all supported classes)
	 * @param bool $isPortalOnly Whether user has only Portal user profile
	 * @param int $limit Maximum results per class
	 * @return array Search results with preview data
	 * @throws Exception
	 */
	public function searchCIs(string $userId, string $term, array $classes = [], bool $isPortalOnly = false, int $limit = 10): array {
		// Default to effective enabled CI classes (admin-enabled minus user-disabled)
		if (empty($classes)) {
			$classes = Application::getEffectiveEnabledCIClasses($this->config, $userId);
		}

		$searchResults = [];
		$itopUrl = $this->getItopUrl($userId);
		$escapedTerm = str_replace("'", "\\'", $term);

		// Get person ID for portal-only filtering
		$personId = null;
		if ($isPortalOnly) {
			$personId = $this->getPersonId($userId);
			if (!$personId) {
				return ['error' => $this->l10n->t('User not configured')];
			}
		}

		foreach ($classes as $class) {
			// Skip Software search for Portal-only users
			if ($class === 'Software' && $isPortalOnly) {
				continue;
			}

			$outputFields = $this->getCIPreviewFields($class);

			// Class-aware joins and term clause
			$joins = '';
			$termClause = '';
			if (in_array($class, ['PCSoftware', 'OtherSoftware'], true)) {
				// Software instances: match without joins using friendlyname fields
				$termClause = "(ci.system_name LIKE '%$escapedTerm%' OR ci.software_id_friendlyname LIKE '%$escapedTerm%' OR ci.path LIKE '%$escapedTerm%' OR ci.friendlyname LIKE '%$escapedTerm%')";
			} elseif ($class === 'WebApplication') {
				// Web applications: match name and URL
				$termClause = "(ci.name LIKE '%$escapedTerm%' OR ci.url LIKE '%$escapedTerm%')";
			} elseif ($class === 'Software') {
				// Software catalog entries: try exact-like on name/vendor first
				$termClause = "(ci.name LIKE '$escapedTerm' OR ci.vendor_name LIKE '$escapedTerm')";
			} else {
				// Hardware-like CIs (FunctionalCI subclasses): include brand/model; add phone specifics
				$termParts = [
					"ci.name LIKE '%$escapedTerm%'",
					"ci.serialnumber LIKE '%$escapedTerm%'",
					"ci.asset_number LIKE '%$escapedTerm%'",
					"ci.brand_id_friendlyname LIKE '%$escapedTerm%'",
					"ci.model_id_friendlyname LIKE '%$escapedTerm%'",
				];
				if (in_array($class, ['Phone','IPPhone','MobilePhone'], true)) {
					$termParts[] = "ci.phonenumber LIKE '%$escapedTerm%'";
				}
				if ($class === 'MobilePhone') {
					$termParts[] = "ci.imei LIKE '%$escapedTerm%'";
				}
				$termClause = '(' . implode(' OR ', $termParts) . ')';
			}

			// Build OQL query with profile-aware filtering
			if ($isPortalOnly) {
				if ($class === 'Software') {
					// Software is not a FunctionalCI; do not filter via lnkContactToFunctionalCI
					$query = "SELECT $class AS ci WHERE $termClause";
				} else {
					// Portal-only: Only CIs where user is contact (FunctionalCI and subclasses)
					$query = "SELECT $class AS ci JOIN lnkContactToFunctionalCI AS lnk ON lnk.functionalci_id = ci.id"
						. $joins
						. " WHERE lnk.contact_id = $personId AND $termClause";
				}
			} else {
				// Power users: Full CMDB search within ACL
				$query = "SELECT $class AS ci"
					. $joins
					. " WHERE $termClause";
			}

			$params = [
				'operation' => 'core/get',
				'class' => $class,
				'key' => $query,
				'output_fields' => $outputFields,
				'limit' => $limit
			];

			// no debug logging

			$result = $this->request($userId, $params, 'POST', $class !== 'Software');

			// If Software exact-like returns empty, retry with wildcards
			if ($class === 'Software') {
				$empty = !isset($result['objects']) || empty($result['objects']);
				if ($empty && $escapedTerm !== '') {
					$termClauseWildcard = "(ci.name LIKE '%$escapedTerm%' OR ci.vendor_name LIKE '%$escapedTerm%')";
					$queryWildcard = "SELECT $class AS ci WHERE $termClauseWildcard";
					$result = $this->request($userId, [
						'operation' => 'core/get',
						'class' => $class,
						'key' => $queryWildcard,
						'output_fields' => $outputFields,
						'limit' => $limit
					], 'POST', false);
				}

			}

			if ($class === 'Software') {
				// no debug logging
			}

			// Fallback for Software: if empty, derive from SoftwareInstance matches
			if (($class === 'Software') && (!isset($result['objects']) || empty($result['objects'])) && $escapedTerm !== '') {
				$si = $this->request($userId, [
					'operation' => 'core/get',
					'class' => 'SoftwareInstance',
					'key' => "SELECT SoftwareInstance AS si WHERE (si.system_name LIKE '%$escapedTerm%' OR si.software_id_friendlyname LIKE '%$escapedTerm%')",
					'output_fields' => 'software_id',
					'limit' => $limit * 2,
				], 'POST', false);
				$ids = [];
				if (isset($si['objects'])) {
					foreach ($si['objects'] as $obj) {
						$ids[] = (int)($obj['fields']['software_id'] ?? 0);
					}
					$ids = array_values(array_unique(array_filter($ids)));
				}
				if (!empty($ids)) {
					$idsCsv = implode(',', $ids);
					$result = $this->request($userId, [
						'operation' => 'core/get',
						'class' => 'Software',
						'key' => "SELECT Software WHERE id IN ($idsCsv)",
						'output_fields' => $outputFields,
						'limit' => $limit
					]);
				}
			}

			if (isset($result['objects'])) {
				foreach ($result['objects'] as $key => $ci) {
					$fields = $ci['fields'] ?? [];
					// Robust title fallback across CI families
					$name = $fields['name']
						?? $fields['friendlyname']
						?? $fields['system_name']
						?? $fields['software_id_friendlyname']
						?? '';
					$entry = [
						'class' => $class,
						'id' => $fields['id'] ?? null,
						'name' => $name,
						'status' => $fields['status'] ?? '',
						'business_criticity' => $fields['business_criticity'] ?? '',
						'org_name' => $fields['org_id_friendlyname'] ?? '',
						'location' => $fields['location_id_friendlyname'] ?? '',
						'serialnumber' => $fields['serialnumber'] ?? '',
						'asset_number' => $fields['asset_number'] ?? '',
						'brand_model' => trim(($fields['brand_id_friendlyname'] ?? '') . ' ' . ($fields['model_id_friendlyname'] ?? '')),
						'description' => strip_tags($fields['description'] ?? ''),
						'url' => $itopUrl . '/pages/UI.php?operation=details&class=' . urlencode($class) . '&id=' . ($fields['id'] ?? '')
					];
					// Class-specific enrichments for subline rendering
					if ($class === 'WebApplication') {
						$entry['web_url'] = $fields['url'] ?? '';
						$entry['webserver_name'] = $fields['webserver_id_friendlyname'] ?? '';
					} elseif ($class === 'PCSoftware' || $class === 'OtherSoftware') {
						$entry['system_name'] = $fields['system_name'] ?? '';
						$entry['software'] = $fields['software_id_friendlyname'] ?? '';
						$entry['license'] = $fields['softwarelicence_id_friendlyname'] ?? '';
						$entry['path'] = $fields['path'] ?? '';
					} elseif ($class === 'Software') {
						$entry['vendor'] = $fields['vendor'] ?? '';
						$entry['version'] = $fields['version'] ?? '';
						$entry['counts'] = [
							'documents' => $this->countFromLinkedSet($fields['documents_list'] ?? null),
							'instances' => $this->countFromLinkedSet($fields['softwareinstance_list'] ?? null),
							'patches' => $this->countFromLinkedSet($fields['softwarepatch_list'] ?? null),
							'licenses' => $this->countFromLinkedSet($fields['softwarelicence_list'] ?? null),
						];
					}
					$searchResults[] = $entry;
				}
			}
		}

		// Sort by name
		usort($searchResults, function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});

		return $searchResults;
	}

	/**
	 * Helper to count linked objects for Software summaries from AttributeLinkedSet
	 */
	private function countFromLinkedSet($linkedSet): int {
		if (!is_array($linkedSet)) {
			return 0;
		}
		// iTop may return ['items' => [id => fields, ...]] or a plain array
		if (isset($linkedSet['items']) && is_array($linkedSet['items'])) {
			return count($linkedSet['items']);
		}
		return count($linkedSet);
	}

	/**
	 * Get preview fields for a CI class
	 *
	 * Returns comma-separated field list for output_fields parameter.
	 * Based on docs/class-mapping.md specifications.
	 *
	 * @param string $class iTop CI class name
	 * @return string Comma-separated field list
	 */
	private function getCIPreviewFields(string $class): string {
		// Use '*' to retrieve all fields from iTop
		// This is simpler, more reliable, and future-proof:
		// - Avoids field name validation issues (brand_name vs brand_id_friendlyname)
		// - Automatically includes all fields defined in the iTop datamodel
		// - Returns both external fields (brand_name, model_name) and friendly names (_friendlyname)
		// - PreviewMapper handles both field formats gracefully
		return '*';
	}

	/**
	 * Build cache key from query parameters
	 *
	 * Cache key is based on the 'key' parameter (the unique OQL query or ID).
	 * Hash is created to keep cache key length reasonable.
	 * Two users requesting the same CI/query will reuse the same cache entry.
	 *
	 * @param array $params API parameters
	 * @return string Cache key (hashed query key)
	 */
	private function buildQueryCacheKey(array $params): string {
		// Hash only the 'key' parameter which contains the unique query
		// Examples of 'key' values:
		// - "32" (for direct ID lookup)
		// - "SELECT PC WHERE name LIKE '%APC0001%'" (for OQL queries)
		$key = $params['key'] ?? '';
		$keyHash = md5($key);
		return 'api:' . $keyHash;
	}

	/**
	 * Make authenticated request to iTop REST API with query-based caching
	 *
	 * API responses are cached based on the 'key' parameter (OQL query or ID).
	 * This means if User A and User B request the same CI or search result,
	 * the cached response is shared (respecting iTop ACL on subsequent validations).
	 *
	 * @param string $userId Nextcloud user ID
	 * @param array $params API parameters (operation, class, key, etc.)
	 * @param string $method HTTP method (always POST for compatibility)
	 * @param bool $useCache Whether to use cache for this request (default: true)
	 * @return array
	 * @throws Exception
	 */
	public function request(string $userId, array $params, string $method = 'POST', bool $useCache = true): array {
		// Build cache key from 'key' parameter
		$cacheKey = $this->buildQueryCacheKey($params);

		// Check cache first if enabled
		if ($useCache) {
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				$cacheData = json_decode($cached, true);
				// Validate timestamp and TTL are present
				if (isset($cacheData['_cache_timestamp']) && isset($cacheData['_cache_ttl'])) {
					$age = time() - $cacheData['_cache_timestamp'];
					if ($age < $cacheData['_cache_ttl']) {
						// Cache is still valid, return the result (remove metadata)
						$result = $cacheData;
						unset($result['_cache_timestamp'], $result['_cache_ttl']);
						$this->logger->debug('API query cache HIT', [
							'app' => Application::APP_ID,
							'cacheKey' => $cacheKey,
							'age' => $age,
							'ttl' => $cacheData['_cache_ttl'],
							'class' => $params['class'] ?? ''
						]);
						return $result;
					}
				}
			}
			$this->logger->debug('API query cache MISS', [
				'app' => Application::APP_ID,
				'cacheKey' => $cacheKey,
				'class' => $params['class'] ?? ''
			]);
		}

		$itopUrl = $this->getItopUrl($userId);
		if (!$itopUrl) {
			return ['error' => $this->l10n->t('iTop URL not configured')];
		}

		// Require application token
		$accessToken = $this->getApplicationToken();
		if (!$accessToken) {
			return [
				'error' => $this->l10n->t('Application token not configured'),
				'error_detail' => 'Administrator must configure the application token in Admin Settings → iTop Integration'
			];
		}

		// Require person_id for user (will be set during personal token validation)
		$personId = $this->getPersonId($userId);
		if (!$personId) {
			return [
				'error' => $this->l10n->t('User not configured'),
				'error_detail' => 'Please configure your personal settings in Personal Settings → iTop Integration'
			];
		}

		try {
			$url = $itopUrl . '/webservices/rest.php?version=1.3';

			// CRITICAL: Use POST with form_params for all requests
			// This is the ONLY format that works reliably with SELECT queries
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud iTop integration',
					'Auth-Token' => $accessToken,
				],
				'form_params' => [
					'json_data' => json_encode($params)
				]
			];

			// Always use POST - GET with URL encoding fails for SELECT queries
			$response = $this->client->post($url, $options);

			$body = $response->getBody();
			$result = json_decode($body, true);

			if ($result === null) {
				return ['error' => $this->l10n->t('Invalid JSON response from iTop')];
			}

			// Cache successful responses with configurable TTL
			if ($useCache && !isset($result['error'])) {
				// Get TTL from config or use default (60 seconds)
				$defaultTTL = 60;
				$ttl = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_api_query', $defaultTTL);
				if ($ttl > 0) {
					// Add timestamp and TTL metadata to cache data for explicit validation
					$cacheData = array_merge($result, [
						'_cache_timestamp' => time(),
						'_cache_ttl' => $ttl
					]);
					$this->cache->set($cacheKey, json_encode($cacheData), $ttl);
					$this->logger->debug('API query CACHED', [
						'app' => Application::APP_ID,
						'cacheKey' => $cacheKey,
						'ttl' => $ttl,
						'class' => $params['class'] ?? ''
					]);
				}
			}

			return $result;

		} catch (ServerException|ClientException $e) {
			$this->logger->warning('iTop API error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			$response = $e->getResponse();
			$statusCode = $response->getStatusCode();
			if ($statusCode === Http::STATUS_UNAUTHORIZED) {
				return ['error' => $this->l10n->t('Bad credentials'), 'error-code' => $statusCode];
			} elseif ($statusCode === Http::STATUS_FORBIDDEN) {
				return ['error' => 'Forbidden'];
			} elseif ($statusCode === Http::STATUS_NOT_FOUND) {
				return ['error' => 'Not found'];
			}
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * ==========================================
	 * AGENT DASHBOARD METHODS
	 * ==========================================
	 * Methods specific to agent workflows for the Agent Dashboard widget
	 */

	/**
	 * Get teams/groups that a user belongs to
	 *
	 * @param string $userId Nextcloud user ID
	 * @return array List of teams with id and friendlyname
	 */
	public function getUserTeams(string $userId): array {
		$personId = $this->getPersonId($userId);

		if (!$personId) {
			$this->logger->warning('getUserTeams: No person_id found', [
				'app' => Application::APP_ID,
				'userId' => $userId
			]);
			return [];
		}

		$params = [
			'operation' => 'core/get',
			'class' => 'Team',
			'key' => "SELECT Team AS t JOIN lnkPersonToTeam AS l ON l.team_id = t.id WHERE l.person_id = $personId",
			'output_fields' => 'id,name,friendlyname'
		];

		$result = $this->request($userId, $params);

		if (isset($result['error'])) {
			$this->logger->error('getUserTeams: API error: ' . $result['error'], [
				'app' => Application::APP_ID,
				'userId' => $userId
			]);
			return [];
		}

		$teams = [];
		if (isset($result['objects'])) {
			foreach ($result['objects'] as $team) {
				$teams[] = [
					'id' => $team['fields']['id'] ?? $team['key'] ?? '',
					'name' => $team['fields']['name'] ?? '',
					'friendlyname' => $team['fields']['friendlyname'] ?? $team['fields']['name'] ?? ''
				];
			}
		}

		return $teams;
	}

	/**
	 * Get tickets assigned to the current user (agent_id = person_id)
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $limit Maximum number of tickets to return
	 * @return array List of tickets assigned to this agent
	 */
	public function getMyAssignedTickets(string $userId, int $limit = 20): array {
		$personId = $this->getPersonId($userId);

		if (!$personId) {
			return [];
		}

		$itopUrl = $this->getItopUrl($userId);
		$allTickets = [];

		// Query UserRequest tickets assigned to this agent
		$userRequestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => "SELECT UserRequest WHERE agent_id = $personId AND status != 'closed'",
			'output_fields' => 'id,ref,title,description,status,operational_status,priority,caller_id_friendlyname,team_id_friendlyname,start_date,last_update',
			'limit' => $limit
		];

		$userRequestResult = $this->request($userId, $userRequestParams);
		if (isset($userRequestResult['objects'])) {
			foreach ($userRequestResult['objects'] as $objectKey => $ticket) {
				$ticketId = $ticket['key'] ?? (strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey);
				$allTickets[] = [
					'type' => 'UserRequest',
					'id' => $ticketId,
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'] ?? '',
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'] ?? 'unknown',
					'operational_status' => $ticket['fields']['operational_status'] ?? '',
					'priority' => $ticket['fields']['priority'] ?? '',
					'caller' => $ticket['fields']['caller_id_friendlyname'] ?? '',
					'team' => $ticket['fields']['team_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'UserRequest', $ticketId)
				];
			}
		}

		// Query Incident tickets assigned to this agent
		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => "SELECT Incident WHERE agent_id = $personId AND status != 'closed'",
			'output_fields' => 'id,ref,title,description,status,operational_status,priority,caller_id_friendlyname,team_id_friendlyname,start_date,last_update',
			'limit' => $limit
		];

		$incidentResult = $this->request($userId, $incidentParams);
		if (isset($incidentResult['objects'])) {
			foreach ($incidentResult['objects'] as $objectKey => $ticket) {
				$ticketId = $ticket['key'] ?? (strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey);
				$allTickets[] = [
					'type' => 'Incident',
					'id' => $ticketId,
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'] ?? '',
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'] ?? 'unknown',
					'operational_status' => $ticket['fields']['operational_status'] ?? '',
					'priority' => $ticket['fields']['priority'] ?? '',
					'caller' => $ticket['fields']['caller_id_friendlyname'] ?? '',
					'team' => $ticket['fields']['team_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'Incident', $ticketId)
				];
			}
		}

		// Sort by last_update descending (most recent first)
		usort($allTickets, function ($a, $b) {
			return strcmp($b['last_update'] ?? '', $a['last_update'] ?? '');
		});

		return array_slice($allTickets, 0, $limit);
	}

	/**
	 * Get tickets assigned to teams the user belongs to (unassigned or assigned to others)
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $limit Maximum number of tickets to return
	 * @return array List of team queue tickets
	 */
	public function getTeamAssignedTickets(string $userId, int $limit = 20): array {
		$teams = $this->getUserTeams($userId);

		if (empty($teams)) {
			return [];
		}

		$teamIds = array_map(fn ($t) => $t['id'], $teams);
		$teamIdList = implode(',', $teamIds);

		$itopUrl = $this->getItopUrl($userId);
		$allTickets = [];

		// Query UserRequest tickets assigned to these teams (open tickets only)
		$userRequestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => "SELECT UserRequest WHERE team_id IN ($teamIdList) AND status != 'closed' AND status != 'resolved'",
			'output_fields' => 'id,ref,title,description,status,operational_status,priority,agent_id_friendlyname,team_id_friendlyname,start_date,last_update',
			'limit' => $limit
		];

		$userRequestResult = $this->request($userId, $userRequestParams);
		if (isset($userRequestResult['objects'])) {
			foreach ($userRequestResult['objects'] as $objectKey => $ticket) {
				$ticketId = $ticket['key'] ?? (strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey);
				$allTickets[] = [
					'type' => 'UserRequest',
					'id' => $ticketId,
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'] ?? '',
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'] ?? 'unknown',
					'operational_status' => $ticket['fields']['operational_status'] ?? '',
					'priority' => $ticket['fields']['priority'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
					'team' => $ticket['fields']['team_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'UserRequest', $ticketId)
				];
			}
		}

		// Query Incident tickets assigned to these teams
		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => "SELECT Incident WHERE team_id IN ($teamIdList) AND status != 'closed' AND status != 'resolved'",
			'output_fields' => 'id,ref,title,description,status,operational_status,priority,agent_id_friendlyname,team_id_friendlyname,start_date,last_update',
			'limit' => $limit
		];

		$incidentResult = $this->request($userId, $incidentParams);
		if (isset($incidentResult['objects'])) {
			foreach ($incidentResult['objects'] as $objectKey => $ticket) {
				$ticketId = $ticket['key'] ?? (strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey);
				$allTickets[] = [
					'type' => 'Incident',
					'id' => $ticketId,
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'] ?? '',
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'] ?? 'unknown',
					'operational_status' => $ticket['fields']['operational_status'] ?? '',
					'priority' => $ticket['fields']['priority'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
					'team' => $ticket['fields']['team_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'Incident', $ticketId)
				];
			}
		}

		// Sort by priority (critical first) then by last_update
		usort($allTickets, function ($a, $b) {
			$priorityOrder = ['1' => 3, '2' => 2, '3' => 1]; // low, medium, high
			$aPriority = $priorityOrder[$a['priority'] ?? ''] ?? 0;
			$bPriority = $priorityOrder[$b['priority'] ?? ''] ?? 0;

			if ($bPriority !== $aPriority) {
				return $bPriority - $aPriority;
			}

			return strcmp($b['last_update'] ?? '', $a['last_update'] ?? '');
		});

		return array_slice($allTickets, 0, $limit);
	}

	/**
	 * Get escalated tickets in teams the user belongs to
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $limit Maximum number of tickets to return
	 * @return array List of escalated tickets
	 */
	public function getEscalatedTicketsForMyTeams(string $userId, int $limit = 20): array {
		$teams = $this->getUserTeams($userId);

		if (empty($teams)) {
			return [];
		}

		$teamIds = array_map(fn ($t) => $t['id'], $teams);
		$teamIdList = implode(',', $teamIds);

		$itopUrl = $this->getItopUrl($userId);
		$allTickets = [];

		// Query UserRequest tickets that are escalated
		$userRequestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => "SELECT UserRequest WHERE team_id IN ($teamIdList) AND (status LIKE '%escalated%' OR operational_status = 'escalated')",
			'output_fields' => 'id,ref,title,description,status,operational_status,priority,agent_id_friendlyname,team_id_friendlyname,start_date,last_update',
			'limit' => $limit
		];

		$userRequestResult = $this->request($userId, $userRequestParams);
		if (isset($userRequestResult['objects'])) {
			foreach ($userRequestResult['objects'] as $objectKey => $ticket) {
				$ticketId = $ticket['key'] ?? (strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey);
				$allTickets[] = [
					'type' => 'UserRequest',
					'id' => $ticketId,
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'] ?? '',
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'] ?? 'unknown',
					'operational_status' => $ticket['fields']['operational_status'] ?? '',
					'priority' => $ticket['fields']['priority'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
					'team' => $ticket['fields']['team_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'UserRequest', $ticketId)
				];
			}
		}

		// Query Incident tickets that are escalated
		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => "SELECT Incident WHERE team_id IN ($teamIdList) AND (status LIKE '%escalated%' OR operational_status = 'escalated')",
			'output_fields' => 'id,ref,title,description,status,operational_status,priority,agent_id_friendlyname,team_id_friendlyname,start_date,last_update',
			'limit' => $limit
		];

		$incidentResult = $this->request($userId, $incidentParams);
		if (isset($incidentResult['objects'])) {
			foreach ($incidentResult['objects'] as $objectKey => $ticket) {
				$ticketId = $ticket['key'] ?? (strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey);
				$allTickets[] = [
					'type' => 'Incident',
					'id' => $ticketId,
					'ref' => $ticket['fields']['ref'] ?? '',
					'title' => $ticket['fields']['title'] ?? '',
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'] ?? 'unknown',
					'operational_status' => $ticket['fields']['operational_status'] ?? '',
					'priority' => $ticket['fields']['priority'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? '',
					'team' => $ticket['fields']['team_id_friendlyname'] ?? '',
					'start_date' => $ticket['fields']['start_date'] ?? '',
					'last_update' => $ticket['fields']['last_update'] ?? '',
					'url' => $this->buildTicketUrl($userId, 'Incident', $ticketId)
				];
			}
		}

		// Sort by last_update descending (most recent escalations first)
		usort($allTickets, function ($a, $b) {
			return strcmp($b['last_update'] ?? '', $a['last_update'] ?? '');
		});

		return array_slice($allTickets, 0, $limit);
	}

	/**
	 * Get upcoming changes (approved/planned changes with near-future start dates)
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $limit Maximum number of changes to return
	 * @return array List of upcoming changes
	 */
	public function getUpcomingChanges(string $userId, int $limit = 10): array {
		$itopUrl = $this->getItopUrl($userId);
		$allChanges = [];

		// Query all relevant Change tickets: current (ongoing) and planned (upcoming within 30 days)
		// Current: start_date <= NOW() AND end_date >= NOW()
		// Planned: start_date > NOW() AND start_date < DATE_ADD(NOW(), INTERVAL 30 DAY)
		// Exclude closed changes: status != 'closed'
		$params = [
			'operation' => 'core/get',
			'class' => 'Change',
			'key' => "SELECT Change WHERE ((start_date <= NOW() AND end_date >= NOW()) OR (start_date > NOW() AND start_date < DATE_ADD(NOW(), INTERVAL 30 DAY))) AND status != 'closed'",
			'output_fields' => 'id,ref,title,description,status,start_date,end_date,impact,last_update,operational_status,finalclass',
			'limit' => $limit * 2  // Fetch more to account for both current and planned
		];

		$this->logger->info('getUpcomingChanges query params', ['app' => Application::APP_ID, 'params' => json_encode($params)]);
		$result = $this->request($userId, $params, 'POST', false); // DISABLE CACHE FOR TESTING
		$this->logger->info('getUpcomingChanges FULL RESULT', ['app' => Application::APP_ID, 'result' => json_encode($result)]);
		if (isset($result['objects'])) {
			foreach ($result['objects'] as $objectKey => $change) {
				$changeId = $change['key'] ?? (strpos($objectKey, '::') !== false ? explode('::', $objectKey)[1] : $objectKey);
				$finalclass = $change['fields']['finalclass'] ?? 'Change';
				$allChanges[] = [
					'type' => 'Change',
					'id' => $changeId,
					'ref' => $change['fields']['ref'] ?? '',
					'title' => $change['fields']['title'] ?? '',
					'description' => $change['fields']['description'] ?? '',
					'status' => $change['fields']['status'] ?? 'unknown',
					'impact' => $change['fields']['impact'] ?? '',
					'start_date' => $change['fields']['start_date'] ?? '',
					'end_date' => $change['fields']['end_date'] ?? '',
					'last_update' => $change['fields']['last_update'] ?? '',
					'operational_status' => $change['fields']['operational_status'] ?? '',
					'finalclass' => $finalclass,
					'url' => $this->buildTicketUrl($userId, $finalclass, $changeId)
				];
			}
		}

		// Sort by priority: current (ongoing) first, then planned (soonest first)
		usort($allChanges, function ($a, $b) {
			$now = time();
			$aStart = strtotime($a['start_date'] ?? '');
			$aEnd = strtotime($a['end_date'] ?? '');
			$bStart = strtotime($b['start_date'] ?? '');
			$bEnd = strtotime($b['end_date'] ?? '');

			// Check if current (ongoing)
			$aIsCurrent = $aStart <= $now && $aEnd >= $now;
			$bIsCurrent = $bStart <= $now && $bEnd >= $now;

			// Prioritize current changes
			if ($aIsCurrent && !$bIsCurrent) {
				return -1;
			}
			if (!$aIsCurrent && $bIsCurrent) {
				return 1;
			}

			// Both current or both planned - sort by start_date
			return strcmp($a['start_date'] ?? '', $b['start_date'] ?? '');
		});

		return $allChanges;
	}

	/**
	 * Get SLA Warning tickets (approaching deadline within 24h)
	 * Returns separate counts for TTO and TTR warnings
	 *
	 * @param string $userId Nextcloud user ID
	 * @return array ['tto' => count, 'ttr' => count]
	 */
	public function getSLAWarningCounts(string $userId): array {
		$teams = $this->getUserTeams($userId);
		if (empty($teams)) {
			return ['tto' => 0, 'ttr' => 0];
		}

		$teamIds = array_column($teams, 'id');
		$teamFilter = implode(',', $teamIds);

		$ttoCount = 0;
		$ttrCount = 0;

		// Fetch all tickets in team with SLA deadline fields (filter in PHP)
		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => "SELECT Incident WHERE team_id IN ($teamFilter)",
			'output_fields' => 'id,tto_escalation_deadline,ttr_escalation_deadline,sla_tto_passed,sla_ttr_passed'
		];

		$requestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => "SELECT UserRequest WHERE team_id IN ($teamFilter)",
			'output_fields' => 'id,tto_escalation_deadline,ttr_escalation_deadline,sla_tto_passed,sla_ttr_passed'
		];

		$now = time();
		// Weekend-aware warning window: Friday=72h, Saturday=48h, other days=24h
		$dayOfWeek = (int)date('N', $now); // 1 (Monday) to 7 (Sunday)
		if ($dayOfWeek === 5) {
			// Friday: 72h to catch Mon/Tue breaches
			$warningWindow = 72 * 60 * 60;
		} elseif ($dayOfWeek === 6) {
			// Saturday: 48h to catch Sun/Mon breaches
			$warningWindow = 48 * 60 * 60;
		} else {
			// Other days: standard 24h window
			$warningWindow = 24 * 60 * 60;
		}

		// Count Incident warnings by filtering in PHP
		$incidentResult = $this->request($userId, $incidentParams);
		if (isset($incidentResult['objects'])) {
			foreach ($incidentResult['objects'] as $incident) {
				$fields = $incident['fields'];

				// TTO warning: deadline within warning window and not yet passed
				if (!empty($fields['tto_escalation_deadline']) && ($fields['sla_tto_passed'] ?? 'no') !== 'yes') {
					$deadline = strtotime($fields['tto_escalation_deadline']);
					if ($deadline > $now && $deadline <= ($now + $warningWindow)) {
						$ttoCount++;
					}
				}

				// TTR warning: deadline within warning window and not yet passed
				if (!empty($fields['ttr_escalation_deadline']) && ($fields['sla_ttr_passed'] ?? 'no') !== 'yes') {
					$deadline = strtotime($fields['ttr_escalation_deadline']);
					if ($deadline > $now && $deadline <= ($now + $warningWindow)) {
						$ttrCount++;
					}
				}
			}
		}

		// Count UserRequest warnings by filtering in PHP
		$requestResult = $this->request($userId, $requestParams);
		if (isset($requestResult['objects'])) {
			foreach ($requestResult['objects'] as $request) {
				$fields = $request['fields'];

				// TTO warning: deadline within warning window and not yet passed
				if (!empty($fields['tto_escalation_deadline']) && ($fields['sla_tto_passed'] ?? 'no') !== 'yes') {
					$deadline = strtotime($fields['tto_escalation_deadline']);
					if ($deadline > $now && $deadline <= ($now + $warningWindow)) {
						$ttoCount++;
					}
				}

				// TTR warning: deadline within warning window and not yet passed
				if (!empty($fields['ttr_escalation_deadline']) && ($fields['sla_ttr_passed'] ?? 'no') !== 'yes') {
					$deadline = strtotime($fields['ttr_escalation_deadline']);
					if ($deadline > $now && $deadline <= ($now + $warningWindow)) {
						$ttrCount++;
					}
				}
			}
		}

		return ['tto' => $ttoCount, 'ttr' => $ttrCount];
	}

	/**
	 * Get SLA Breach counts (already escalated tickets)
	 * Returns separate counts for TTO and TTR breaches
	 *
	 * @param string $userId Nextcloud user ID
	 * @return array ['tto' => count, 'ttr' => count]
	 */
	public function getSLABreachCounts(string $userId): array {
		$teams = $this->getUserTeams($userId);
		if (empty($teams)) {
			return ['tto' => 0, 'ttr' => 0];
		}

		$teamIds = array_column($teams, 'id');
		$teamFilter = implode(',', $teamIds);

		$ttoCount = 0;
		$ttrCount = 0;

		// Fetch all Incidents in team with SLA fields (OQL string filtering doesn't work reliably)
		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => "SELECT Incident WHERE team_id IN ($teamFilter)",
			'output_fields' => 'id,sla_tto_passed,sla_ttr_passed'
		];

		$requestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => "SELECT UserRequest WHERE team_id IN ($teamFilter)",
			'output_fields' => 'id,sla_tto_passed,sla_ttr_passed'
		];

		// Count Incident breaches by filtering in PHP
		$incidentResult = $this->request($userId, $incidentParams);
		if (isset($incidentResult['objects'])) {
			foreach ($incidentResult['objects'] as $incident) {
				if (($incident['fields']['sla_tto_passed'] ?? 'no') === 'yes') {
					$ttoCount++;
				}
				if (($incident['fields']['sla_ttr_passed'] ?? 'no') === 'yes') {
					$ttrCount++;
				}
			}
		}

		// Count UserRequest breaches by filtering in PHP
		$requestResult = $this->request($userId, $requestParams);
		if (isset($requestResult['objects'])) {
			foreach ($requestResult['objects'] as $request) {
				if (($request['fields']['sla_tto_passed'] ?? 'no') === 'yes') {
					$ttoCount++;
				}
				if (($request['fields']['sla_ttr_passed'] ?? 'no') === 'yes') {
					$ttrCount++;
				}
			}
		}

		return ['tto' => $ttoCount, 'ttr' => $ttrCount];
	}

}
