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

namespace OCA\Itop\Controller;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\CacheService;
use OCA\Itop\Service\ItopAPIService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

use Psr\Log\LoggerInterface;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ICrypto $crypto,
		private IL10N $l10n,
		private ItopAPIService $itopAPIService,
		private CacheService $cacheService,
		private LoggerInterface $logger,
		private IAppManager $appManager,
		private IClientService $clientService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Set user configuration values (Phase 2: Personal Token Validation)
	 *
	 * WORKFLOW:
	 * 1. Receive personal token from user (NOT stored)
	 * 2. Validate token using :current_contact_id → extracts Person ID directly
	 * 3. Store ONLY person_id (NOT the token)
	 * 4. Discard personal token immediately (security enhancement)
	 *
	 * WHY DUAL-TOKEN ARCHITECTURE?
	 * ============================
	 * Portal users are HARD-BLOCKED from REST API access by iTop core:
	 * - webservices/rest.php line 103: $bIsAllowedToPortalUsers = false (hardcoded)
	 * - Even valid personal tokens fail with: {"code":1,"message":"Error: Portal user is not allowed"}
	 *
	 * SOLUTION:
	 * - Personal token: Identity verification ONLY (proves user is authorized)
	 * - Application token: All subsequent queries (admin-level, bypasses Portal user block)
	 * - Person ID filtering: Ensures data isolation between users
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	public function setConfig(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		// Get JSON data from request body
		$input = json_decode(file_get_contents('php://input'), true);
		if (!is_array($input)) {
			return new DataResponse(['message' => $this->l10n->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
		}

		$this->logger->info('iTop setConfig called for user: ' . $this->userId, ['app' => Application::APP_ID]);

		$values = $input;

		// Save non-token settings first
		$allowedKeys = [
			'navigation_enabled',
			'notification_enabled',
			'search_enabled',
			'notify_ticket_status_changed',
			'notify_agent_responded',
			'notify_ticket_resolved'
		];

		foreach ($values as $key => $value) {
			if (in_array($key, $allowedKeys)) {
				// Boolean values should be '0' or '1'
				$boolValue = $value ? '1' : '0';
				$this->config->setUserValue($this->userId, Application::APP_ID, $key, $boolValue);
			}
		}

		// Handle disabled CI classes (user preferences)
		if (isset($values['disabled_ci_classes']) && is_array($values['disabled_ci_classes'])) {
			$disabledClasses = array_values(array_unique($values['disabled_ci_classes']));
			// Validate classes
			$validDisabled = array_intersect($disabledClasses, Application::SUPPORTED_CI_CLASSES);
			if (empty($validDisabled)) {
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'disabled_ci_classes');
			} else {
				$this->config->setUserValue($this->userId, Application::APP_ID, 'disabled_ci_classes', json_encode($validDisabled));
			}
		}

		// Handle disabled portal notifications (3-state system)
		if (isset($values['disabled_portal_notifications'])) {
			if ($values['disabled_portal_notifications'] === 'all') {
				// Master toggle: disable all portal notifications
				$this->config->setUserValue($this->userId, Application::APP_ID, 'disabled_portal_notifications', 'all');
			} elseif (is_array($values['disabled_portal_notifications'])) {
				$disabledPortal = array_values(array_unique($values['disabled_portal_notifications']));
				// Validate against PORTAL_NOTIFICATION_TYPES
				$validDisabledPortal = array_intersect($disabledPortal, Application::PORTAL_NOTIFICATION_TYPES);
				if (empty($validDisabledPortal)) {
					$this->config->deleteUserValue($this->userId, Application::APP_ID, 'disabled_portal_notifications');
				} else {
					$this->config->setUserValue($this->userId, Application::APP_ID, 'disabled_portal_notifications', json_encode($validDisabledPortal));
				}
			} else {
				// Empty or invalid: clear disabled array (enable all)
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'disabled_portal_notifications');
			}
		}

		// Handle disabled agent notifications (3-state system)
		if (isset($values['disabled_agent_notifications'])) {
			if ($values['disabled_agent_notifications'] === 'all') {
				// Master toggle: disable all agent notifications
				$this->config->setUserValue($this->userId, Application::APP_ID, 'disabled_agent_notifications', 'all');
			} elseif (is_array($values['disabled_agent_notifications'])) {
				$disabledAgent = array_values(array_unique($values['disabled_agent_notifications']));
				// Validate against AGENT_NOTIFICATION_TYPES
				$validDisabledAgent = array_intersect($disabledAgent, Application::AGENT_NOTIFICATION_TYPES);
				if (empty($validDisabledAgent)) {
					$this->config->deleteUserValue($this->userId, Application::APP_ID, 'disabled_agent_notifications');
				} else {
					$this->config->setUserValue($this->userId, Application::APP_ID, 'disabled_agent_notifications', json_encode($validDisabledAgent));
				}
			} else {
				// Empty or invalid: clear disabled array (enable all)
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'disabled_agent_notifications');
			}
		}

		// Handle notification check interval
		if (isset($values['notification_check_interval'])) {
			$interval = (int)$values['notification_check_interval'];
			// Validate range: 5-1440 minutes
			if ($interval >= 5 && $interval <= 1440) {
				$this->config->setUserValue($this->userId, Application::APP_ID, 'notification_check_interval', (string)$interval);
			}
		}

		// Phase 2: Handle personal token validation
		$personalToken = $values['personal_token'] ?? $values['token'] ?? null;

		// Handle token deletion
		if ($personalToken !== null && $personalToken === '') {
			// Remove person_id and user_id
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'person_id');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
			// Also clean up any old token storage (Phase 1 leftover)
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
			$this->logger->info('iTop: Person ID and User ID removed for user', ['app' => Application::APP_ID]);
			return new DataResponse([
				'message' => $this->l10n->t('Configuration removed successfully'),
				'person_id_configured' => false
			]);
		}

		// If no token provided, just return current status
		if ($personalToken === null) {
			$hasPersonId = $this->config->getUserValue($this->userId, Application::APP_ID, 'person_id', '') !== '';
			return new DataResponse([
				'message' => $this->l10n->t('Settings saved successfully'),
				'person_id_configured' => $hasPersonId
			]);
		}

		// Phase 2: Validate personal token and extract Person ID using :current_contact_id
		$validation = $this->validatePersonalTokenAndExtractPersonId($personalToken);

		if (!$validation['success']) {
			return new DataResponse([
				'message' => $this->l10n->t('Token validation failed'),
				'error' => $validation['error'],
				'person_id_configured' => false
			], Http::STATUS_BAD_REQUEST);
		}

		// Success! Store Person ID and User ID (NOT the token)
		$personId = $validation['person_id'];
		$userId = $validation['user_id'];
		$this->config->setUserValue($this->userId, Application::APP_ID, 'person_id', $personId);
		$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $userId);
		// Clean up any old token storage (Phase 1 leftover)
		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
		$this->logger->info('iTop: Person ID ' . $personId . ' and User ID ' . $userId . ' configured for user ' . $this->userId, ['app' => Application::APP_ID]);

		// Personal token is now discarded (never stored)
		$userInfo = $validation['user_info'];
		$userName = trim(($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? ''));

		return new DataResponse([
			'message' => $this->l10n->t('Configuration successful! You are now connected.'),
			'person_id_configured' => true,
			'user_info' => [
				'name' => $userName ?: $userInfo['login'],
				'email' => $userInfo['email'] ?? '',
				'organization' => $userInfo['org_name'] ?? '',
				'person_id' => $personId
			]
		]);
	}

	/**
	 * Get current user information from iTop
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getUserInfo(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => $this->l10n->t('User not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$personId = $this->config->getUserValue($this->userId, Application::APP_ID, 'person_id', '');

		if (empty($personId)) {
			return new DataResponse(['error' => $this->l10n->t('User not configured')], Http::STATUS_NOT_FOUND);
		}

		// Fetch person details from iTop using application token
		$encryptedAppToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '');

		if (empty($encryptedAppToken)) {
			return new DataResponse(['error' => $this->l10n->t('Application token not configured')], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$applicationToken = $this->crypto->decrypt($encryptedAppToken);
			$adminInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');

			if (empty($adminInstanceUrl)) {
				return new DataResponse(['error' => $this->l10n->t('Server URL not configured')], Http::STATUS_SERVICE_UNAVAILABLE);
			}

			$apiUrl = rtrim($adminInstanceUrl, '/') . '/webservices/rest.php?version=1.3';

			$postData = [
				'json_data' => json_encode([
					'operation' => 'core/get',
					'class' => 'Person',
					'key' => $personId,
					'output_fields' => 'id,first_name,name,email,org_id_friendlyname'
				])
			];

			try {
				$client = $this->clientService->newClient();
				$response = $client->post($apiUrl, [
					'body' => http_build_query($postData),
					'headers' => [
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Auth-Token' => $applicationToken,
						'User-Agent' => 'Nextcloud-iTop-Integration/1.0'
					],
					'timeout' => 15,
				]);
				$result = $response->getBody();
			} catch (\Exception $e) {
				return new DataResponse(['error' => $this->l10n->t('Connection failed: %s', [$e->getMessage()])], Http::STATUS_SERVICE_UNAVAILABLE);
			}

			$responseData = json_decode($result, true);

			if ($responseData === null || !isset($responseData['code']) || $responseData['code'] !== 0) {
				$errorMsg = $responseData['message'] ?? $this->l10n->t('Failed to fetch user information');
				return new DataResponse(['error' => $errorMsg], Http::STATUS_BAD_REQUEST);
			}

			if (!isset($responseData['objects']) || empty($responseData['objects'])) {
				return new DataResponse(['error' => $this->l10n->t('Person not found')], Http::STATUS_NOT_FOUND);
			}

			$personObject = reset($responseData['objects']);
			$personFields = $personObject['fields'] ?? [];

			$userName = trim(($personFields['first_name'] ?? '') . ' ' . ($personFields['name'] ?? ''));

			return new DataResponse([
				'name' => $userName ?: $this->l10n->t('Unknown User'),
				'email' => $personFields['email'] ?? '',
				'organization' => $personFields['org_id_friendlyname'] ?? '',
				'person_id' => $personId
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to fetch user info: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse(['error' => $this->l10n->t('Failed to fetch user information')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get admin configuration values
	 *
	 * @return DataResponse
	 */
	public function getAdminConfig(): DataResponse {
		$adminInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
		$userFacingName = $this->config->getAppValue(Application::APP_ID, 'user_facing_name', 'iTop');
		$hasApplicationToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '') !== '';

		// Count users with configured tokens
		$connectedUsers = $this->getConnectedUsersCount();

		// Get cache TTL values (with defaults matching CacheService)
		$cacheTtlCiPreview = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_ci_preview', '60');
		$cacheTtlTicketInfo = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_ticket_info', '60');
		$cacheTtlSearch = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_search', '30');
		$cacheTtlPicker = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_picker', '60');
		$cacheTtlProfile = (int)$this->config->getAppValue(Application::APP_ID, 'cache_ttl_profile', '1800');

		// Get 3-state CI class configuration
		$ciClassConfig = Application::getCIClassConfig($this->config);

		$adminConfig = [
			'admin_instance_url' => $adminInstanceUrl,
			'user_facing_name' => $userFacingName,
			'has_application_token' => $hasApplicationToken,
			'connected_users' => $connectedUsers,
			'last_updated' => date('Y-m-d H:i:s'),
			'version' => Application::getVersion($this->appManager),
			'cache_ttl_ci_preview' => $cacheTtlCiPreview,
			'cache_ttl_ticket_info' => $cacheTtlTicketInfo,
			'cache_ttl_search' => $cacheTtlSearch,
			'cache_ttl_picker' => $cacheTtlPicker,
			'cache_ttl_profile' => $cacheTtlProfile,
			'ci_class_config' => $ciClassConfig,
			'supported_ci_classes' => Application::SUPPORTED_CI_CLASSES,
		];

		return new DataResponse($adminConfig);
	}

	/**
	 * Test application token connection
	 *
	 * @param string $token Optional token to test (if not provided, uses saved token)
	 * @return DataResponse
	 */
	public function testApplicationToken(string $token = ''): DataResponse {
		$adminInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');

		if (empty($adminInstanceUrl)) {
			return new DataResponse([
				'status' => 'error',
				'message' => $this->l10n->t('Server URL not configured')
			], Http::STATUS_BAD_REQUEST);
		}

		// If token not provided, try to get from saved config
		if (empty($token)) {
			$encryptedToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '');

			if (empty($encryptedToken)) {
				return new DataResponse([
					'status' => 'error',
					'message' => $this->l10n->t('Application token not configured')
				], Http::STATUS_BAD_REQUEST);
			}

			try {
				// Decrypt the token
				$token = $this->crypto->decrypt($encryptedToken);
			} catch (\Exception $e) {
				return new DataResponse([
					'status' => 'error',
					'message' => $this->l10n->t('Failed to decrypt saved token')
				], Http::STATUS_BAD_REQUEST);
			}
		}

		try {

			// Test the token with a simple API call
			$apiUrl = rtrim($adminInstanceUrl, '/') . '/webservices/rest.php?version=1.3';

			// Use list_operations to validate the token (works for both Application and Personal tokens)
			$postData = [
				'json_data' => json_encode([
					'operation' => 'list_operations'
				])
			];

			try {
				$client = $this->clientService->newClient();
				$response = $client->post($apiUrl, [
					'body' => http_build_query($postData),
					'headers' => [
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Auth-Token' => $token,
						'User-Agent' => 'Nextcloud-iTop-Integration/1.0'
					],
					'timeout' => 15,
				]);
				$result = $response->getBody();

				$this->logger->info('iTop application token test response: ' . $result, ['app' => Application::APP_ID]);
			} catch (\Exception $e) {
				return new DataResponse([
					'status' => 'error',
					'message' => $this->l10n->t('Connection failed: %s', [$e->getMessage()])
				]);
			}

			$responseData = json_decode($result, true);

			if ($responseData === null) {
				return new DataResponse([
					'status' => 'error',
					'message' => $this->l10n->t('Invalid response from server')
				]);
			}

			// Check response code
			if (isset($responseData['code'])) {
				if ($responseData['code'] == 0) {
					// Success - token is valid
					$operationCount = count($responseData['operations'] ?? []);
					return new DataResponse([
						'status' => 'success',
						'message' => $this->l10n->t('Application token is valid and working'),
						'details' => [
							'api_version' => $responseData['version'] ?? 'Unknown',
							'available_operations' => $operationCount,
							'token_type' => 'Application Token'
						]
					]);
				} elseif ($responseData['code'] == 1) {
					// Unauthorized - provide detailed debugging info
					$errorMsg = $responseData['message'] ?? 'Unauthorized';
					return new DataResponse([
						'status' => 'error',
						'message' => $this->l10n->t('Application token authentication failed'),
						'details' => [
							'error' => $errorMsg,
							'hint' => $this->l10n->t('Application tokens in iTop must have "Administrator" + "REST Services User" profiles. Token may be invalid or expired.'),
							'token_length' => strlen($token),
							'response_code' => $responseData['code']
						]
					]);
				} else {
					return new DataResponse([
						'status' => 'error',
						'message' => $this->l10n->t('API error: %s', [$responseData['message'] ?? $this->l10n->t('Unknown error')]),
						'details' => [
							'code' => $responseData['code'],
							'full_response' => $responseData
						]
					]);
				}
			}

			return new DataResponse([
				'status' => 'error',
				'message' => $this->l10n->t('Unexpected response format'),
				'details' => [
					'response' => $responseData
				]
			]);

		} catch (\Exception $e) {
			$this->logger->error('iTop application token test failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse([
				'status' => 'error',
				'message' => $this->l10n->t('Test failed: %s', [$e->getMessage()])
			]);
		}
	}

	/**
	 * Test connection to iTop server
	 *
	 * @param string $url Optional URL to test (if not provided, uses saved config)
	 * @return DataResponse
	 */
	public function testAdminConnection(string $url = ''): DataResponse {
		// Use provided URL or fall back to saved configuration
		$testUrl = !empty($url) ? trim($url) : $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');

		if (empty($testUrl)) {
			return new DataResponse(['status' => 'error', 'message' => $this->l10n->t('No server URL provided for testing')], Http::STATUS_BAD_REQUEST);
		}

		$this->logger->info('iTop testing connection to URL: ' . $testUrl, ['app' => Application::APP_ID]);

		// Test iTop API endpoint specifically
		try {
			// Construct the iTop REST API URL
			$apiUrl = rtrim($testUrl, '/') . '/webservices/rest.php?version=1.3';
			$this->logger->info('iTop testing API endpoint: ' . $apiUrl, ['app' => Application::APP_ID]);

			// Prepare a basic API request (without credentials to test for proper iTop error response)
			$postData = [
				'json_data' => json_encode([
					'operation' => 'core/check_credentials'
				])
			];

			try {
				$client = $this->clientService->newClient();
				$response = $client->post($apiUrl, [
					'body' => http_build_query($postData),
					'headers' => [
						'Content-Type' => 'application/x-www-form-urlencoded',
						'User-Agent' => 'Nextcloud-iTop-Integration/1.0'
					],
					'timeout' => 15,
				]);
				$result = $response->getBody();
			} catch (\Exception $e) {
				return new DataResponse([
					'status' => 'error',
					'message' => $this->l10n->t('Connection failed: %s', [$e->getMessage()]),
					'details' => ['url' => $testUrl, 'api_url' => $apiUrl]
				]);
			}

			// Parse the JSON response
			$responseData = json_decode($result, true);
			if ($responseData === null) {
				return new DataResponse([
					'status' => 'error',
					'message' => $this->l10n->t('Server did not return valid JSON - not an iTop instance'),
					'details' => ['url' => $testUrl, 'response' => substr($result, 0, 200)]
				]);
			}

			$this->logger->info('iTop API response: ' . json_encode($responseData), ['app' => Application::APP_ID]);

			// Check for proper iTop response structure
			if (isset($responseData['code'])) {
				// iTop returns status codes: 0 = OK, 1 = UNAUTHORIZED, 2 = MISSING_VERSION, etc.
				if ($responseData['code'] == 1) {
					// UNAUTHORIZED - this is expected and proves it's an iTop instance
					return new DataResponse([
						'status' => 'success',
						'message' => $this->l10n->t('iTop instance detected (authentication required)'),
						'details' => [
							'url' => $testUrl,
							'api_url' => $apiUrl,
							'itop_code' => $responseData['code'],
							'itop_message' => $responseData['message'] ?? 'Unauthorized'
						]
					]);
				} elseif ($responseData['code'] == 0) {
					// Successful response (shouldn't happen without credentials, but still valid iTop)
					return new DataResponse([
						'status' => 'success',
						'message' => $this->l10n->t('iTop instance detected and accessible'),
						'details' => [
							'url' => $testUrl,
							'api_url' => $apiUrl,
							'itop_code' => $responseData['code']
						]
					]);
				} else {
					// Other iTop error codes
					return new DataResponse([
						'status' => 'warning',
						'message' => $this->l10n->t('iTop instance detected with error: %s', [$responseData['message'] ?? $this->l10n->t('Unknown error')]),
						'details' => [
							'url' => $testUrl,
							'api_url' => $apiUrl,
							'itop_code' => $responseData['code'],
							'itop_message' => $responseData['message'] ?? ''
						]
					]);
				}
			} else {
				return new DataResponse([
					'status' => 'error',
					'message' => $this->l10n->t('Server response does not match iTop API format'),
					'details' => ['url' => $testUrl, 'response' => $responseData]
				]);
			}

		} catch (\Exception $e) {
			$this->logger->error('iTop connection test failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse([
				'status' => 'error',
				'message' => $this->l10n->t('Connection test failed: %s', [$e->getMessage()]),
				'details' => ['url' => $testUrl]
			]);
		}
	}

	/**
	 * Set admin configuration values
	 *
	 * @param array $values key/value pairs to store in admin preferences
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		// Debug logging
		$this->logger->info('iTop setAdminConfig called with values: ' . json_encode(array_keys($values)), ['app' => Application::APP_ID]);

		$result = [];
		$allowedKeys = ['admin_instance_url', 'user_facing_name', 'application_token'];

		foreach ($values as $key => $value) {
			// Only process allowed configuration keys
			if (!in_array($key, $allowedKeys)) {
				continue;
			}

			$this->logger->info('iTop processing key: ' . $key, ['app' => Application::APP_ID]);

			if ($key === 'admin_instance_url') {
				// Validate URL format
				if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
					$this->logger->error('iTop Invalid URL format: ' . $value, ['app' => Application::APP_ID]);
					return new DataResponse(['message' => $this->l10n->t('Invalid URL format')], Http::STATUS_BAD_REQUEST);
				}
				$this->config->setAppValue(Application::APP_ID, $key, $value);
				$result[$key] = $value;
			} elseif ($key === 'user_facing_name') {
				// Validate user facing name
				$value = trim($value);
				if (strlen($value) > 100) {
					return new DataResponse(['message' => $this->l10n->t('User facing name is too long (max 100 characters)')], Http::STATUS_BAD_REQUEST);
				}
				if ($value === '') {
					$value = 'iTop'; // Default fallback
				}
				$this->config->setAppValue(Application::APP_ID, $key, $value);
				$result[$key] = $value;
			} elseif ($key === 'application_token') {
				// Handle application token with encryption
				if ($value === '') {
					// Delete token if empty
					$this->config->deleteAppValue(Application::APP_ID, 'application_token');
					$this->logger->info('iTop application token deleted', ['app' => Application::APP_ID]);
					$result['has_application_token'] = false;
				} else {
					// Encrypt and store the token
					$encryptedToken = $this->crypto->encrypt($value);
					$this->config->setAppValue(Application::APP_ID, 'application_token', $encryptedToken);
					$this->logger->info('iTop application token saved (encrypted)', ['app' => Application::APP_ID]);
					$result['has_application_token'] = true;
				}
			}

			$this->logger->info('iTop saved config key: ' . $key, ['app' => Application::APP_ID]);
		}

		$this->logger->info('iTop Admin configuration saved successfully', ['app' => Application::APP_ID]);
		$result['message'] = $this->l10n->t('Admin configuration saved');

		return new DataResponse($result);
	}

	/**
	 * Save notification interval settings with validation
	 *
	 * @param int $portalInterval Portal notification check interval in minutes (5-1440)
	 * @return DataResponse
	 */
	public function saveNotificationSettings(int $portalInterval): DataResponse {
		// Validation: 5 minutes to 24 hours
		$minInterval = 5;
		$maxInterval = 1440;

		if ($portalInterval < $minInterval || $portalInterval > $maxInterval) {
			return new DataResponse([
				'message' => $this->l10n->t('Portal notification interval must be between %d and %d minutes', [$minInterval, $maxInterval])
			], Http::STATUS_BAD_REQUEST);
		}

		// Save validated value
		$this->config->setAppValue(Application::APP_ID, 'portal_notification_interval', (string)$portalInterval);

		$this->logger->info('Notification interval settings updated', [
			'app' => Application::APP_ID,
			'portal_interval' => $portalInterval
		]);

		return new DataResponse([
			'message' => $this->l10n->t('Notification settings saved successfully'),
			'portal_notification_interval' => $portalInterval
		]);
	}

	/**
	 * Save 3-state notification configuration
	 *
	 * @param int $defaultInterval Default notification check interval in minutes (5-1440)
	 * @param string $portalConfig JSON-encoded portal notification configuration
	 * @param string $agentConfig JSON-encoded agent notification configuration
	 * @return DataResponse
	 */
	public function saveNotificationConfig(int $defaultInterval, string $portalConfig, string $agentConfig): DataResponse {
		// Validate interval
		$minInterval = 5;
		$maxInterval = 1440;

		if ($defaultInterval < $minInterval || $defaultInterval > $maxInterval) {
			return new DataResponse([
				'message' => $this->l10n->t('Default notification interval must be between %d and %d minutes', [$minInterval, $maxInterval])
			], Http::STATUS_BAD_REQUEST);
		}

		// Decode and validate portal config
		$portalConfigArray = json_decode($portalConfig, true);
		if (!is_array($portalConfigArray)) {
			return new DataResponse([
				'message' => $this->l10n->t('Invalid portal notification configuration format')
			], Http::STATUS_BAD_REQUEST);
		}

		// Decode and validate agent config
		$agentConfigArray = json_decode($agentConfig, true);
		if (!is_array($agentConfigArray)) {
			return new DataResponse([
				'message' => $this->l10n->t('Invalid agent notification configuration format')
			], Http::STATUS_BAD_REQUEST);
		}

		// Validate portal notification types and states
		$validStates = [
			Application::NOTIFICATION_STATE_DISABLED,
			Application::NOTIFICATION_STATE_FORCED,
			Application::NOTIFICATION_STATE_USER_CHOICE
		];

		foreach (Application::PORTAL_NOTIFICATION_TYPES as $type) {
			if (!isset($portalConfigArray[$type]) || !in_array($portalConfigArray[$type], $validStates)) {
				return new DataResponse([
					'message' => $this->l10n->t('Invalid portal notification state for type: %s', [$type])
				], Http::STATUS_BAD_REQUEST);
			}
		}

		// Validate agent notification types and states
		foreach (Application::AGENT_NOTIFICATION_TYPES as $type) {
			if (!isset($agentConfigArray[$type]) || !in_array($agentConfigArray[$type], $validStates)) {
				return new DataResponse([
					'message' => $this->l10n->t('Invalid agent notification state for type: %s', [$type])
				], Http::STATUS_BAD_REQUEST);
			}
		}

		// Save all validated values
		$this->config->setAppValue(Application::APP_ID, 'default_notification_interval', (string)$defaultInterval);
		$this->config->setAppValue(Application::APP_ID, 'portal_notification_config', $portalConfig);
		$this->config->setAppValue(Application::APP_ID, 'agent_notification_config', $agentConfig);

		$this->logger->info('Notification configuration updated', [
			'app' => Application::APP_ID,
			'default_interval' => $defaultInterval,
			'portal_config_keys' => array_keys($portalConfigArray),
			'agent_config_keys' => array_keys($agentConfigArray)
		]);

		return new DataResponse([
			'message' => $this->l10n->t('Notification configuration saved successfully'),
			'default_notification_interval' => $defaultInterval,
			'portal_notification_config' => $portalConfigArray,
			'agent_notification_config' => $agentConfigArray
		]);
	}

	/**
	 * Save cache TTL settings with validation
	 *
	 * @param int $ciPreviewTTL CI preview cache TTL in seconds
	 * @param int $ticketInfoTTL Ticket info cache TTL in seconds
	 * @param int $searchTTL Search results cache TTL in seconds
	 * @param int $pickerTTL Picker suggestions cache TTL in seconds
	 * @param int $profileTTL Profile cache TTL in seconds
	 * @return DataResponse
	 */
	public function saveCacheSettings(int $ciPreviewTTL, int $ticketInfoTTL, int $searchTTL, int $pickerTTL, int $profileTTL): DataResponse {
		// Validation ranges
		$minTTL = 10;  // 10 seconds minimum
		$maxTTLPreview = 3600;  // 1 hour maximum for previews
		$maxTTLOther = 300;  // 5 minutes maximum for search/picker
		$maxTTLProfile = 3600;  // 1 hour maximum for profile cache

		// Validate CI Preview TTL
		if ($ciPreviewTTL < $minTTL || $ciPreviewTTL > $maxTTLPreview) {
			return new DataResponse([
				'message' => $this->l10n->t('CI Preview cache TTL must be between %d and %d seconds', [$minTTL, $maxTTLPreview])
			], Http::STATUS_BAD_REQUEST);
		}

		// Validate Ticket Info TTL
		if ($ticketInfoTTL < $minTTL || $ticketInfoTTL > $maxTTLPreview) {
			return new DataResponse([
				'message' => $this->l10n->t('Ticket Info cache TTL must be between %d and %d seconds', [$minTTL, $maxTTLPreview])
			], Http::STATUS_BAD_REQUEST);
		}

		// Validate Search TTL
		if ($searchTTL < $minTTL || $searchTTL > $maxTTLOther) {
			return new DataResponse([
				'message' => $this->l10n->t('Search cache TTL must be between %d and %d seconds', [$minTTL, $maxTTLOther])
			], Http::STATUS_BAD_REQUEST);
		}

		// Validate Picker TTL
		if ($pickerTTL < $minTTL || $pickerTTL > $maxTTLOther) {
			return new DataResponse([
				'message' => $this->l10n->t('Picker cache TTL must be between %d and %d seconds', [$minTTL, $maxTTLOther])
			], Http::STATUS_BAD_REQUEST);
		}

		// Validate Profile TTL
		if ($profileTTL < $minTTL || $profileTTL > $maxTTLProfile) {
			return new DataResponse([
				'message' => $this->l10n->t('Profile cache TTL must be between %d and %d seconds', [$minTTL, $maxTTLProfile])
			], Http::STATUS_BAD_REQUEST);
		}

		// Save validated values
		$this->config->setAppValue(Application::APP_ID, 'cache_ttl_ci_preview', (string)$ciPreviewTTL);
		$this->config->setAppValue(Application::APP_ID, 'cache_ttl_ticket_info', (string)$ticketInfoTTL);
		$this->config->setAppValue(Application::APP_ID, 'cache_ttl_search', (string)$searchTTL);
		$this->config->setAppValue(Application::APP_ID, 'cache_ttl_picker', (string)$pickerTTL);
		$this->config->setAppValue(Application::APP_ID, 'cache_ttl_profile', (string)$profileTTL);

		$this->logger->info('Cache TTL settings updated', [
			'app' => Application::APP_ID,
			'ci_preview' => $ciPreviewTTL,
			'ticket_info' => $ticketInfoTTL,
			'search' => $searchTTL,
			'picker' => $pickerTTL,
			'profile' => $profileTTL
		]);

		return new DataResponse([
			'message' => $this->l10n->t('Cache settings saved successfully'),
			'cache_ttl_ci_preview' => $ciPreviewTTL,
			'cache_ttl_ticket_info' => $ticketInfoTTL,
			'cache_ttl_search' => $searchTTL,
			'cache_ttl_picker' => $pickerTTL,
			'cache_ttl_profile' => $profileTTL
		]);
	}

	/**
	 * Get enabled CI classes from configuration
	 *
	 * @return array List of enabled CI class names
	 */
	private function getEnabledCIClasses(): array {
		$enabledClassesJson = $this->config->getAppValue(Application::APP_ID, 'enabled_ci_classes', '');

		if ($enabledClassesJson === '') {
			// Default: no classes enabled (opt-in model)
			return [];
		}

		$enabledClasses = json_decode($enabledClassesJson, true);
		if (!is_array($enabledClasses)) {
			// Fallback on invalid JSON: no classes enabled
			return [];
		}

		// Filter to only valid classes
		return array_values(array_intersect($enabledClasses, Application::SUPPORTED_CI_CLASSES));
	}

	/**
	 * Get user's disabled CI classes
	 *
	 * @NoAdminRequired
	 * @return DataResponse
	 */
	public function getUserDisabledCIClasses(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => $this->l10n->t('User not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$userDisabledJson = $this->config->getUserValue($this->userId, Application::APP_ID, 'disabled_ci_classes', '');
		$userDisabled = [];

		if ($userDisabledJson !== '') {
			$userDisabled = json_decode($userDisabledJson, true);
			if (!is_array($userDisabled)) {
				$userDisabled = [];
			}
		}

		// Also get admin-enabled classes for reference
		$adminEnabled = Application::getEnabledCIClasses($this->config);

		return new DataResponse([
			'admin_enabled_classes' => $adminEnabled,
			'user_disabled_classes' => $userDisabled,
			'effective_enabled_classes' => Application::getEffectiveEnabledCIClasses($this->config, $this->userId),
			'supported_ci_classes' => Application::SUPPORTED_CI_CLASSES
		]);
	}

	/**
	 * Save user's disabled CI classes
	 *
	 * @NoAdminRequired
	 * @param array $disabledClasses Array of CI class names user wants to disable
	 * @return DataResponse
	 */
	public function saveUserDisabledCIClasses(array $disabledClasses): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => $this->l10n->t('User not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		// Validate that all provided classes are supported
		$validClasses = array_intersect($disabledClasses, Application::SUPPORTED_CI_CLASSES);

		// Remove duplicates and re-index
		$validClasses = array_values(array_unique($validClasses));

		// Save to user config
		if (empty($validClasses)) {
			// Remove config if no classes disabled
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'disabled_ci_classes');
		} else {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'disabled_ci_classes', json_encode($validClasses));
		}

		$this->logger->info('User disabled CI classes updated', [
			'app' => Application::APP_ID,
			'userId' => $this->userId,
			'disabled_classes' => $validClasses
		]);

		return new DataResponse([
			'message' => $this->l10n->t('CI class preferences saved successfully'),
			'user_disabled_classes' => $validClasses,
			'effective_enabled_classes' => Application::getEffectiveEnabledCIClasses($this->config, $this->userId)
		]);
	}

	/**
	 * Save CI class configuration (admin only) - 3-state model
	 *
	 * @param array $classConfig Map of class name => state (disabled/forced/user_choice)
	 * @return DataResponse
	 */
	public function saveCIClassConfig(array $classConfig): DataResponse {
		// Validate format and values
		$validConfig = [];
		foreach ($classConfig as $className => $state) {
			// Only process supported classes
			if (!in_array($className, Application::SUPPORTED_CI_CLASSES, true)) {
				continue;
			}

			// Validate state
			if (!in_array($state, [
				Application::CI_CLASS_STATE_DISABLED,
				Application::CI_CLASS_STATE_FORCED,
				Application::CI_CLASS_STATE_USER_CHOICE
			], true)) {
				return new DataResponse([
					'message' => $this->l10n->t('Invalid state for class %s: %s', [$className, $state])
				], Http::STATUS_BAD_REQUEST);
			}

			$validConfig[$className] = $state;
		}

		// Save to config
		$this->config->setAppValue(Application::APP_ID, 'ci_class_config', json_encode($validConfig));

		$this->logger->info('CI class configuration updated', [
			'app' => Application::APP_ID,
			'config' => $validConfig
		]);

		return new DataResponse([
			'message' => $this->l10n->t('CI class configuration saved successfully'),
			'ci_class_config' => $validConfig
		]);
	}

	/**
	 * Save enabled CI classes configuration (admin only)
	 * DEPRECATED: Use saveCIClassConfig() instead
	 *
	 * @param array $enabledClasses Array of CI class names to enable
	 * @return DataResponse
	 */
	public function saveEnabledCIClasses(array $enabledClasses): DataResponse {
		// Validate that all provided classes are supported
		$validClasses = array_intersect($enabledClasses, Application::SUPPORTED_CI_CLASSES);

		if (count($validClasses) === 0) {
			return new DataResponse([
				'message' => $this->l10n->t('At least one CI class must be enabled')
			], Http::STATUS_BAD_REQUEST);
		}

		// Remove duplicates and re-index
		$validClasses = array_values(array_unique($validClasses));

		// Save to config
		$this->config->setAppValue(Application::APP_ID, 'enabled_ci_classes', json_encode($validClasses));

		$this->logger->info('Enabled CI classes updated', [
			'app' => Application::APP_ID,
			'enabled_classes' => $validClasses
		]);

		return new DataResponse([
			'message' => $this->l10n->t('CI class configuration saved successfully'),
			'enabled_ci_classes' => $validClasses
		]);
	}

	/**
	 * Clear all cache entries
	 *
	 * @return DataResponse
	 */
	public function clearAllCache(): DataResponse {
		try {
			$this->cacheService->clearAll();

			$this->logger->info('All cache entries cleared by admin', [
				'app' => Application::APP_ID
			]);

			return new DataResponse([
				'message' => $this->l10n->t('All cache entries cleared successfully')
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to clear cache: ' . $e->getMessage(), [
				'app' => Application::APP_ID
			]);

			return new DataResponse([
				'message' => $this->l10n->t('Failed to clear cache: %s', [$e->getMessage()])
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Check for app version updates on GitHub
	 *
	 * @return DataResponse
	 */
	public function checkVersion(): DataResponse {
		try {
			$currentVersion = Application::getVersion($this->appManager);
			$githubApiUrl = 'https://api.github.com/repos/LexioJ/integration_itop/releases/latest';

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $githubApiUrl);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'User-Agent: Nextcloud-iTop-Integration',
				'Accept: application/vnd.github.v3+json'
			]);

			$result = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($result === false || $httpCode !== 200) {
				return new DataResponse([
					'has_update' => false,
					'error' => 'Failed to fetch version information'
				]);
			}

			$releaseData = json_decode($result, true);
			if (!isset($releaseData['tag_name'])) {
				return new DataResponse([
					'has_update' => false,
					'error' => 'Invalid response from GitHub'
				]);
			}

			$latestVersion = ltrim($releaseData['tag_name'], 'v');
			$hasUpdate = version_compare($latestVersion, $currentVersion, '>');

			return new DataResponse([
				'has_update' => $hasUpdate,
				'current_version' => $currentVersion,
				'latest_version' => $latestVersion,
				'release_url' => $releaseData['html_url'] ?? 'https://github.com/LexioJ/integration_itop/releases',
				'release_date' => $releaseData['published_at'] ?? null
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to check version: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse([
				'has_update' => false,
				'error' => 'Version check failed'
			]);
		}
	}

	/**
	 * Count users who have configured iTop
	 *
	 * @return int
	 */
	private function getConnectedUsersCount(): int {
		try {
			// Count users who have person_id configured (indicates completed setup)
			$query = \OC::$server->getDatabaseConnection()->getQueryBuilder();
			$result = $query->select($query->func()->count('*', 'count'))
				->from('preferences')
				->where($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
				->andWhere($query->expr()->eq('configkey', $query->createNamedParameter('person_id')))
				->andWhere($query->expr()->neq('configvalue', $query->createNamedParameter('')))
				->executeQuery();

			$row = $result->fetch();
			$result->closeCursor();
			// Database may return the count as 'count' or 'COUNT(*)' depending on driver
			return (int)($row['count'] ?? $row['COUNT(*)'] ?? 0);
		} catch (\Exception $e) {
			$this->logger->error('Error counting connected users: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return 0;
		}
	}


	/**
	 * Validate personal token and extract Person ID using iTop's :current_contact_id placeholder
	 *
	 * Uses a single API call to validate the personal token and retrieve the user's Person ID.
	 * This method works for all user types (Portal, SAML, Service Desk, etc.) by leveraging
	 * iTop's magic placeholder that automatically resolves to the authenticated user's Person ID.
	 *
	 * @param string $personalToken User's personal token from iTop
	 * @return array ['success' => bool, 'person_id' => string|null, 'user_info' => array|null, 'error' => string|null]
	 */
	private function validatePersonalTokenAndExtractPersonId(string $personalToken): array {
		$adminInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');

		if (empty($adminInstanceUrl)) {
			return [
				'success' => false,
				'person_id' => null,
				'user_info' => null,
				'error' => $this->l10n->t('Server URL not configured by administrator')
			];
		}

		try {
			$apiUrl = rtrim($adminInstanceUrl, '/') . '/webservices/rest.php?version=1.3';

			// SIMPLIFIED: Single API call using personal token + :current_contact_id
			// This validates the token AND gets the Person ID in one request
			// We also get the User ID by querying User WHERE contactid = :current_contact_id
			$postData = [
				'json_data' => json_encode([
					'operation' => 'core/get',
					'class' => 'Person',
					'key' => 'SELECT Person WHERE id = :current_contact_id',
					'output_fields' => 'id,first_name,name,email,org_id_friendlyname'
				])
			];

			try {
				$client = $this->clientService->newClient();
				$response = $client->post($apiUrl, [
					'body' => http_build_query($postData),
					'headers' => [
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Auth-Token' => $personalToken,
						'User-Agent' => 'Nextcloud-iTop-Integration/1.0'
					],
					'timeout' => 15,
				]);
				$result = $response->getBody();
			} catch (\Exception $e) {
				return [
					'success' => false,
					'person_id' => null,
					'user_info' => null,
					'error' => $this->l10n->t('Connection failed: %s', [$e->getMessage()])
				];
			}

			$responseData = json_decode($result, true);

			if ($responseData === null) {
				return [
					'success' => false,
					'person_id' => null,
					'user_info' => null,
					'error' => $this->l10n->t('Invalid response from server')
				];
			}

			// Check for authentication errors (invalid/expired token)
			if (!isset($responseData['code']) || $responseData['code'] !== 0) {
				$errorMsg = $responseData['message'] ?? 'Invalid or expired token';

				// Special handling for Portal user block
				if (strpos($errorMsg, 'Portal user is not allowed') !== false) {
					$errorMsg = $this->l10n->t('Portal users cannot use REST API directly. This is expected - the application token will handle all queries.');
				}

				return [
					'success' => false,
					'person_id' => null,
					'user_info' => null,
					'error' => $this->l10n->t('Personal token validation failed: %s', [$errorMsg])
				];
			}

			// Extract Person data from response
			if (!isset($responseData['objects']) || empty($responseData['objects'])) {
				return [
					'success' => false,
					'person_id' => null,
					'user_info' => null,
					'error' => $this->l10n->t('No Person found for this user. The user may not have a linked contact in iTop.')
				];
			}

			// Get first (and only) Person object from response
			$personObject = reset($responseData['objects']);
			$personFields = $personObject['fields'] ?? [];

			$personId = $personFields['id'] ?? null;

			if (!$personId) {
				return [
					'success' => false,
					'person_id' => null,
					'user_info' => null,
					'error' => $this->l10n->t('Could not extract Person ID from iTop response')
				];
			}

			// Step 2: Get User ID using application token
			// Personal tokens can't query User class, so we use the application token
			$encryptedAppToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '');
			$userIdValue = null;

			if (!empty($encryptedAppToken)) {
				try {
					$applicationToken = $this->crypto->decrypt($encryptedAppToken);

					// Query User class using application token
					// Validate personId to prevent OQL injection
					if (!is_numeric($personId) || $personId < 0) {
						throw new \InvalidArgumentException('Invalid person ID');
					}
					$personId = (int)$personId;
					$getUserData = [
						'json_data' => json_encode([
							'operation' => 'core/get',
							'class' => 'User',
							'key' => "SELECT User WHERE contactid = $personId",
							'output_fields' => 'id,login,finalclass'
						])
					];

					$client = $this->clientService->newClient();
					$response = $client->post($apiUrl, [
						'body' => http_build_query($getUserData),
						'headers' => [
							'Content-Type' => 'application/x-www-form-urlencoded',
							'Auth-Token' => $applicationToken,
							'User-Agent' => 'Nextcloud-iTop-Integration/1.0'
						],
						'timeout' => 15,
					]);
					$userResult = $response->getBody();

					$userData = json_decode($userResult, true);
					if (isset($userData['objects']) && !empty($userData['objects'])) {
						$userObject = reset($userData['objects']);
						$userFields = $userObject['fields'] ?? [];
						$userIdValue = $userFields['id'] ?? null;
					}
				} catch (\Exception $e) {
					$this->logger->warning('Could not fetch User ID: ' . $e->getMessage(), ['app' => Application::APP_ID]);
					// Not critical - we can continue without user_id
				}
			}

			// Success! Return Person ID, User ID, and user info
			return [
				'success' => true,
				'person_id' => (string)$personId,
				'user_id' => $userIdValue ? (string)$userIdValue : null,
				'user_info' => [
					'first_name' => $personFields['first_name'] ?? '',
					'last_name' => $personFields['name'] ?? '',
					'email' => $personFields['email'] ?? '',
					'org_name' => $personFields['org_id_friendlyname'] ?? ''
				],
				'error' => null
			];

		} catch (\Exception $e) {
			$this->logger->error('iTop personal token validation failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return [
				'success' => false,
				'person_id' => null,
				'user_info' => null,
				'error' => $this->l10n->t('Validation failed: %s', [$e->getMessage()])
			];
		}
	}
}
