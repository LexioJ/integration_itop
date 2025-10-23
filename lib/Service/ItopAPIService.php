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

	private function getItopUrl(string $userId): string {
		$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		return $this->config->getUserValue($userId, Application::APP_ID, 'url') ?: $adminItopUrl;
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
		
		// Query UserRequest tickets
		$userRequestQuery = "SELECT UserRequest WHERE caller_id_friendlyname = '$fullName' AND status != 'closed'";
		$userRequestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => $userRequestQuery,
			'output_fields' => 'id,title,description,status,priority,agent_id_friendlyname'
		];

		if ($limit) {
			$userRequestParams['limit'] = $limit;
		}

		$userRequestResult = $this->request($userId, $userRequestParams);
		if (isset($userRequestResult['objects'])) {
			foreach ($userRequestResult['objects'] as $key => $ticket) {
				$allTickets[] = [
					'type' => 'UserRequest',
					'id' => $ticket['fields']['id'],
					'title' => $ticket['fields']['title'],
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'],
					'priority' => $ticket['fields']['priority'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? ''
				];
			}
		}
		
		// Query Incident tickets
		$incidentQuery = "SELECT Incident WHERE caller_id_friendlyname = '$fullName' AND status != 'closed'";
		$incidentParams = [
			'operation' => 'core/get',
			'class' => 'Incident',
			'key' => $incidentQuery,
			'output_fields' => 'id,title,description,status,priority,agent_id_friendlyname'
		];

		if ($limit) {
			$incidentParams['limit'] = $limit;
		}

		$incidentResult = $this->request($userId, $incidentParams);
		if (isset($incidentResult['objects'])) {
			foreach ($incidentResult['objects'] as $key => $ticket) {
				$allTickets[] = [
					'type' => 'Incident',
					'id' => $ticket['fields']['id'],
					'title' => $ticket['fields']['title'],
					'description' => $ticket['fields']['description'] ?? '',
					'status' => $ticket['fields']['status'],
					'priority' => $ticket['fields']['priority'] ?? '',
					'agent' => $ticket['fields']['agent_id_friendlyname'] ?? ''
				];
			}
		}
		
		return $allTickets;
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
		$userRequestQuery = "SELECT UserRequest WHERE "
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
					'url' => $itopUrl . '/pages/UI.php?operation=details&class=UserRequest&id=' . $ticket['fields']['id']
				];
			}
		}

		// Search Incidents - tickets where user is creator OR assigned agent
		// Also search by ref (ticket ID like I-000006)
		// Note: Contact relationship search added separately below
		$incidentQuery = "SELECT Incident WHERE "
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
					'url' => $itopUrl . '/pages/UI.php?operation=details&class=Incident&id=' . $ticket['fields']['id']
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
						$ticketClass = (strpos($ticketRef, 'R-') === 0) ? 'UserRequest' :
									   ((strpos($ticketRef, 'I-') === 0) ? 'Incident' : null);

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
										'url' => $itopUrl . '/pages/UI.php?operation=details&class=' . $ticketClass . '&id=' . $ticket['fields']['id']
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
		$searchResults = array_filter($searchResults, function($ticket) use (&$seen) {
			$key = $ticket['type'] . '-' . $ticket['id'];
			if (isset($seen[$key])) {
				return false;
			}
			$seen[$key] = true;
			return true;
		});

		// Sort by last_update descending (most recent first)
		usort($searchResults, function($a, $b) {
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
		// Default to all supported CI classes
			if (empty($classes)) {
				$classes = ['PC', 'Phone', 'IPPhone', 'MobilePhone', 'Tablet', 'Printer', 'Peripheral', 'Software', 'WebApplication'];
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
		usort($searchResults, function($a, $b) {
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
		// Base fields from FunctionalCI (all CI classes inherit these)
		// Source: itop/datamodels/2.x/itop-config-mgmt/datamodel.itop-config-mgmt.xml
		$baseFunctionalCIFields = [
			'id',
			'name',
			'friendlyname',
			'org_id_friendlyname',
			'description',
			'business_criticity',
			'move2production'
		];

		// Fields added by PhysicalDevice extension
		// Applies to: PC, Phone, IPPhone, MobilePhone, Tablet, Printer, Peripheral
		$physicalDeviceFields = [
			'status',
			'serialnumber',
			'location_id_friendlyname',
			'brand_id_friendlyname',
			'model_id_friendlyname',
			'asset_number'
		];

		// Fields added by SoftwareInstance extension
		// Applies to: PCSoftware, OtherSoftware
		$softwareInstanceFields = [
			'status',
			'system_name',
			'software_id_friendlyname',
			'softwarelicence_id_friendlyname',
			'path'
		];

		// Determine which extension applies
		$physicalDeviceClasses = ['PC', 'Phone', 'IPPhone', 'MobilePhone', 'Tablet', 'Printer', 'Peripheral'];
		$softwareInstanceClasses = ['PCSoftware', 'OtherSoftware'];

		if (in_array($class, $physicalDeviceClasses, true)) {
			// PhysicalDevice subclasses
			$fields = array_merge($baseFunctionalCIFields, $physicalDeviceFields);
		} elseif (in_array($class, $softwareInstanceClasses, true)) {
			// SoftwareInstance subclasses
			$fields = array_merge($baseFunctionalCIFields, $softwareInstanceFields);
			} else {
				// Direct FunctionalCI subclasses (e.g., WebApplication)
				// WebApplication extends FunctionalCI directly, not PhysicalDevice or SoftwareInstance
				$fields = $baseFunctionalCIFields;
			}

				// Software catalog (not a FunctionalCI) fields for search rows with summary counts
				if ($class === 'Software') {
					return implode(',', [
						'id', 'name', 'version', 'vendor', 'type',
						// linked sets used to compute subline counts without extra queries
						'documents_list', 'softwareinstance_list', 'softwarepatch_list', 'softwarelicence_list'
					]);
				}

			// Class-specific additional fields
			$classSpecificFields = [
				'PC' => ['type', 'osfamily_id_friendlyname', 'osversion_id_friendlyname', 'cpu', 'ram'],
				'Phone' => ['phonenumber'],
				'IPPhone' => ['phonenumber'],
				'MobilePhone' => ['phonenumber', 'imei'],
				'WebApplication' => ['url', 'webserver_id_friendlyname'],
			];

		if (isset($classSpecificFields[$class])) {
			$fields = array_merge($fields, $classSpecificFields[$class]);
		}

		return implode(',', $fields);
	}

	/**
	 * Make authenticated request to iTop REST API
	 *
	 * @param string $userId Nextcloud user ID
	 * @param array $params API parameters (operation, class, key, etc.)
	 * @param string $method HTTP method (always POST for compatibility)
	 * @return array
	 * @throws Exception
	 */
	public function request(string $userId, array $params, string $method = 'POST'): array {
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

}
