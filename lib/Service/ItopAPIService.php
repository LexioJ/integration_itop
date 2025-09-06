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
	 * @param string $userId
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	private function checkOpenTicketsForUser(string $userId): void {
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$notificationEnabled = ($this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1');
		if ($accessToken && $notificationEnabled) {
			$itopUrl = $this->getItopUrl($userId);
			if ($itopUrl) {
				$lastNotificationCheck = $this->config->getUserValue($userId, Application::APP_ID, 'last_open_check');
				$lastNotificationCheck = $lastNotificationCheck === '' ? null : $lastNotificationCheck;
				
				// Get assigned tickets that are open
				$tickets = $this->getAssignedTickets($userId, $lastNotificationCheck);
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
					// Auth token seems to no longer be valid, wipe it and don't retry
					$this->config->deleteUserValue($userId, Application::APP_ID, 'token');
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
	 * Get tickets assigned to the current user
	 *
	 * @param string $userId
	 * @param ?string $since
	 * @param ?int $limit
	 * @return array
	 * @throws PreConditionNotMetException|Exception
	 */
	public function getAssignedTickets(string $userId, ?string $since = null, ?int $limit = null): array {
		// Get current user's details first
		$userInfo = $this->getCurrentUser($userId);
		if (isset($userInfo['error'])) {
			return $userInfo;
		}

		$userLogin = $userInfo['login'] ?? '';
		
		// Query for tickets assigned to this user
		$query = "SELECT UserRequest WHERE agent_id_friendlyname = '$userLogin' AND status != 'closed'";
		$params = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => $query,
			'output_fields' => 'id,title,description,status,priority,caller_id_friendlyname,creation_date'
		];

		if ($limit) {
			$params['limit'] = $limit;
		}

		$result = $this->request($userId, $params);
		
		if (isset($result['objects'])) {
			$tickets = [];
			foreach ($result['objects'] as $key => $ticket) {
				$tickets[] = [
					'id' => $ticket['fields']['id'],
					'title' => $ticket['fields']['title'],
					'description' => $ticket['fields']['description'],
					'status' => $ticket['fields']['status'],
					'priority' => $ticket['fields']['priority'],
					'caller' => $ticket['fields']['caller_id_friendlyname'],
					'creation_date' => $ticket['fields']['creation_date']
				];
			}
			return $tickets;
		}

		return $result;
	}

	/**
	 * Get current user information
	 *
	 * @param string $userId
	 * @return array
	 * @throws Exception
	 */
	public function getCurrentUser(string $userId): array {
		$params = [
			'operation' => 'core/check_credentials'
		];
		
		return $this->request($userId, $params);
	}

	/**
	 * Search for tickets, CIs, and other objects
	 *
	 * @param string $userId
	 * @param string $query
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 * @throws Exception
	 */
	public function search(string $userId, string $query, int $offset = 0, int $limit = 10): array {
		// Search in multiple classes
		$searchResults = [];
		
		// Search UserRequests
		$userRequestParams = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => "SELECT UserRequest WHERE title LIKE '%$query%' OR description LIKE '%$query%'",
			'output_fields' => 'id,title,description,status,priority,caller_id_friendlyname,creation_date',
			'limit' => $limit
		];
		
		$userRequests = $this->request($userId, $userRequestParams);
		if (isset($userRequests['objects'])) {
			foreach ($userRequests['objects'] as $key => $ticket) {
				$searchResults[] = [
					'type' => 'UserRequest',
					'id' => $ticket['fields']['id'],
					'title' => $ticket['fields']['title'],
					'description' => strip_tags($ticket['fields']['description']),
					'status' => $ticket['fields']['status'],
					'url' => $this->getItopUrl($userId) . '/pages/UI.php?operation=details&class=UserRequest&id=' . $ticket['fields']['id']
				];
			}
		}
		
		// Search FunctionalCIs
		$ciParams = [
			'operation' => 'core/get',
			'class' => 'FunctionalCI',
			'key' => "SELECT FunctionalCI WHERE name LIKE '%$query%'",
			'output_fields' => 'id,name,description,status,business_criticity',
			'limit' => $limit
		];
		
		$cis = $this->request($userId, $ciParams);
		if (isset($cis['objects'])) {
			foreach ($cis['objects'] as $key => $ci) {
				$searchResults[] = [
					'type' => 'FunctionalCI',
					'id' => $ci['fields']['id'],
					'title' => $ci['fields']['name'],
					'description' => strip_tags($ci['fields']['description'] ?? ''),
					'status' => $ci['fields']['status'] ?? '',
					'url' => $this->getItopUrl($userId) . '/pages/UI.php?operation=details&class=FunctionalCI&id=' . $ci['fields']['id']
				];
			}
		}
		
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
	public function getTicketInfo(string $userId, int $ticketId): array {
		$params = [
			'operation' => 'core/get',
			'class' => 'UserRequest',
			'key' => $ticketId,
			'output_fields' => '*'
		];
		
		return $this->request($userId, $params);
	}

	/**
	 * Make authenticated request to iTop REST API
	 *
	 * @param string $userId
	 * @param array $params
	 * @param string $method
	 * @return array
	 * @throws Exception
	 */
	public function request(string $userId, array $params, string $method = 'POST'): array {
		$itopUrl = $this->getItopUrl($userId);
		if (!$itopUrl) {
			return ['error' => $this->l10n->t('iTop URL not configured')];
		}
		
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$accessToken = $accessToken === '' ? '' : $this->crypto->decrypt($accessToken);
		
		if (!$accessToken) {
			return ['error' => $this->l10n->t('iTop API token not configured')];
		}

		try {
			$url = $itopUrl . '/webservices/rest.php?version=1.3';
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud iTop integration',
				],
				'form_params' => [
					'auth_token' => $accessToken,
					'json_data' => json_encode($params)
				]
			];

			if ($method === 'GET') {
				$options['query'] = [
					'auth_token' => $accessToken,
					'json_data' => json_encode($params)
				];
				unset($options['form_params']);
				$response = $this->client->get($url, $options);
			} else {
				$response = $this->client->post($url, $options);
			}
			
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
