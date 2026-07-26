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
use OCA\Itop\Service\ItopAPIService;
use OCA\Itop\Service\CacheService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

use Psr\Log\LoggerInterface;

class ConfigController extends Controller {
	/**
	 * Request-local cache of parsed iTop class definitions, keyed by iTop base URL.
	 *
	 * @var array<string, array<string, array{parent:?string, icon:?string}>>
	 */
	/** TTL for persisted icon discovery caches (datamodel map + negative cache), in seconds */
	private const ICON_CACHE_TTL = 86400;

	private array $itopClassDefinitionsCache = [];

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
		private IAppDataFactory $appDataFactory,
		private IURLGenerator $urlGenerator,
		private ?string $userId
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
			'notify_ticket_resolved',
			'newsroom_mirroring_enabled',
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
	 * Save ticket system type configuration
	 *
	 * @param string $ticketSystemType 'itil', 'simple', or 'auto'
	 * @param string $simpleTypeField  Optional enum field name (may be empty)
	 * @param string $simpleIncidentValue Enum value meaning "incident"
	 * @param string $simpleRequestValue  Enum value meaning "service request"
	 * @return DataResponse
	 */
	public function saveTicketSystemType(
		string $ticketSystemType,
		string $simpleTypeField = '',
		string $simpleIncidentValue = 'incident',
		string $simpleRequestValue = 'service_request',
	): DataResponse {
		$validTypes = [
			Application::TICKET_SYSTEM_TYPE_ITIL,
			Application::TICKET_SYSTEM_TYPE_SIMPLE,
			Application::TICKET_SYSTEM_TYPE_AUTO,
		];

		if (!in_array($ticketSystemType, $validTypes, true)) {
			return new DataResponse([
				'message' => $this->l10n->t('Invalid ticket system type'),
			], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setAppValue(Application::APP_ID, 'ticket_system_type', $ticketSystemType);

		// Store optional simple-mode enum configuration
		$this->config->setAppValue(Application::APP_ID, 'simple_ticket_type_field', trim($simpleTypeField));
		$this->config->setAppValue(Application::APP_ID, 'simple_ticket_incident_value', trim($simpleIncidentValue) ?: 'incident');
		$this->config->setAppValue(Application::APP_ID, 'simple_ticket_request_value', trim($simpleRequestValue) ?: 'service_request');

		// Clear the cached auto-detection result so the next request re-probes if needed
		$this->config->deleteAppValue(Application::APP_ID, 'ticket_system_type_detected');

		$this->logger->info('Ticket system type configuration saved: ' . $ticketSystemType, [
			'app' => Application::APP_ID,
		]);

		return new DataResponse([
			'message' => $this->l10n->t('Ticket system type configuration saved'),
			'ticket_system_type' => $ticketSystemType,
		]);
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
	 * Accepts both standard SUPPORTED_CI_CLASSES and admin-added custom classes.
	 *
	 * @param array $classConfig Map of class name => state (disabled/forced/user_choice)
	 * @return DataResponse
	 */
	public function saveCIClassConfig(array $classConfig): DataResponse {
		// All known classes = standard + custom
		$allKnownClasses = Application::getAllCIClasses($this->config);

		// Validate format and values
		$validConfig = [];
		foreach ($classConfig as $className => $state) {
			// Only process known classes (standard or custom)
			if (!in_array($className, $allKnownClasses, true)) {
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
	 * Get available iTop CI classes by querying the iTop CMDB.
	 *
	 * Queries FunctionalCI for distinct finalclass values to discover all
	 * CI subclasses present in the iTop instance. Returns classes not already
	 * in SUPPORTED_CI_CLASSES so the admin can add them as custom classes.
	 * Also returns the currently configured custom classes.
	 *
	 * @return DataResponse
	 */
	public function getAvailableItopClasses(): DataResponse {
		$adminInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
		if (empty($adminInstanceUrl)) {
			return new DataResponse([
				'error' => $this->l10n->t('Server URL not configured')
			], Http::STATUS_BAD_REQUEST);
		}

		$encryptedToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '');
		if (empty($encryptedToken)) {
			return new DataResponse([
				'error' => $this->l10n->t('Application token not configured')
			], Http::STATUS_BAD_REQUEST);
		}

		try {
			$applicationToken = $this->crypto->decrypt($encryptedToken);
		} catch (\Exception $e) {
			return new DataResponse([
				'error' => $this->l10n->t('Failed to decrypt application token')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$apiUrl = rtrim($adminInstanceUrl, '/') . '/webservices/rest.php?version=1.3';

			// Query FunctionalCI to discover all subclasses present in this iTop instance
			$postData = [
				'json_data' => json_encode([
					'operation' => 'core/get',
					'class' => 'FunctionalCI',
					'key' => 'SELECT FunctionalCI',
					'output_fields' => 'finalclass',
					'limit' => 2000,
				])
			];

			$client = $this->clientService->newClient();
			$response = $client->post($apiUrl, [
				'body' => http_build_query($postData),
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Auth-Token' => $applicationToken,
					'User-Agent' => 'Nextcloud-iTop-Integration/1.0'
				],
				'timeout' => 20,
			]);

			$result = json_decode($response->getBody(), true);

			if (!is_array($result) || ($result['code'] ?? -1) !== 0) {
				return new DataResponse([
					'error' => $result['message'] ?? $this->l10n->t('Failed to fetch CI classes from iTop')
				], Http::STATUS_BAD_REQUEST);
			}

			// Collect unique finalclass values from all objects
			$discoveredClasses = [];
			foreach ($result['objects'] ?? [] as $obj) {
				$fc = $obj['fields']['finalclass'] ?? '';
				if ($fc !== '' && !in_array($fc, $discoveredClasses, true)) {
					$discoveredClasses[] = $fc;
				}
			}
			sort($discoveredClasses);

			// Filter out standard built-in classes (already managed separately)
			$availableForCustom = array_values(
				array_filter($discoveredClasses, function (string $cls) {
					return !in_array($cls, Application::SUPPORTED_CI_CLASSES, true);
				})
			);

			// Return discovered classes + currently configured custom classes
			$currentCustomClasses = Application::getCustomCIClasses($this->config);
			$classesForIcons = array_values(array_unique(array_merge($availableForCustom, $currentCustomClasses)));

			// Best-effort warmup: fetch class icon from iTop and cache in appdata
			$this->warmupCustomClassIcons($classesForIcons, $adminInstanceUrl, $applicationToken);

			$classIcons = [];
			foreach ($classesForIcons as $className) {
				$classIcons[$className] = $this->urlGenerator->linkToRoute('integration_itop.config.getCIClassIcon', ['class' => $className]);
			}

			return new DataResponse([
				'available_classes' => $availableForCustom,
				'custom_classes' => $currentCustomClasses,
				'supported_classes' => Application::SUPPORTED_CI_CLASSES,
				'class_icons' => $classIcons,
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to fetch available iTop classes: ' . $e->getMessage(), [
				'app' => Application::APP_ID
			]);
			return new DataResponse([
				'error' => $this->l10n->t('Connection failed: %s', [$e->getMessage()])
			], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * Save the list of custom CI classes chosen by the admin.
	 *
	 * Custom classes are iTop FunctionalCI subclasses not in SUPPORTED_CI_CLASSES
	 * (e.g. Monitor, Scanner). They are stored in a separate config key and merged
	 * with standard classes at runtime by Application::getAllCIClasses().
	 *
	 * @param array $customClasses Array of iTop class names to treat as custom CI classes
	 * @return DataResponse
	 */
	public function saveCustomCIClasses(array $customClasses): DataResponse {
		// Sanitize: class names must be non-empty alphanumeric strings
		$sanitized = [];
		foreach ($customClasses as $cls) {
			$cls = trim((string)$cls);
			// iTop class names are CamelCase identifiers (letters, digits)
			if ($cls !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $cls)) {
				// Must not duplicate a standard class
				if (!in_array($cls, Application::SUPPORTED_CI_CLASSES, true)) {
					$sanitized[] = $cls;
				}
			}
		}
		$sanitized = array_values(array_unique($sanitized));

		// Persist the custom class list
		$this->config->setAppValue(Application::APP_ID, 'custom_ci_classes', json_encode($sanitized));

		// Clean up ci_class_config: remove entries for classes that are no longer
		// custom (neither standard nor in the new custom list)
		$allKnown = array_merge(Application::SUPPORTED_CI_CLASSES, $sanitized);
		$configJson = $this->config->getAppValue(Application::APP_ID, 'ci_class_config', '');
		if ($configJson !== '') {
			$classConfig = json_decode($configJson, true);
			if (is_array($classConfig)) {
				$cleaned = array_filter($classConfig, function (string $cls) use ($allKnown) {
					return in_array($cls, $allKnown, true);
				}, ARRAY_FILTER_USE_KEY);
				$this->config->setAppValue(Application::APP_ID, 'ci_class_config', json_encode($cleaned));
			}
		}

		// Best-effort warmup: cache icons for currently configured custom classes
		$adminInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
		$encryptedToken = $this->config->getAppValue(Application::APP_ID, 'application_token', '');
		if (!empty($adminInstanceUrl) && !empty($encryptedToken)) {
			try {
				$applicationToken = $this->crypto->decrypt($encryptedToken);
				$this->warmupCustomClassIcons($sanitized, $adminInstanceUrl, $applicationToken);
			} catch (\Exception $e) {
				$this->logger->warning('Could not warm up custom CI class icons after save: ' . $e->getMessage(), [
					'app' => Application::APP_ID,
				]);
			}
		}

		$this->logger->info('Custom CI classes updated', [
			'app' => Application::APP_ID,
			'custom_classes' => $sanitized
		]);

		return new DataResponse([
			'message' => $this->l10n->t('Custom CI classes saved successfully'),
			'custom_classes' => $sanitized,
		]);
	}

	/**
	 * Best-effort icon warmup for custom classes.
	 * Attempts to fetch class icon from iTop datamodel UI and cache it in appdata.
	 *
	 * @param array $classes
	 * @param string $adminInstanceUrl
	 * @param string $applicationToken
	 * @return void
	 */
	private function warmupCustomClassIcons(array $classes, string $adminInstanceUrl, string $applicationToken): void {
		$misses = $this->loadIconCacheMeta('icon_discovery_misses.json');
		$missesChanged = false;

		foreach ($classes as $className) {
			$className = (string)$className;
			// Skip classes whose icon discovery recently failed — avoids re-crawling
			// the iTop datamodel on every settings page load (negative cache, 24h TTL)
			if (isset($misses[$className]) && (time() - (int)$misses[$className]) < self::ICON_CACHE_TTL) {
				continue;
			}
			try {
				if ($this->cacheClassIconFromItop($className, $adminInstanceUrl, $applicationToken)) {
					if (isset($misses[$className])) {
						unset($misses[$className]);
						$missesChanged = true;
					}
				} else {
					$misses[$className] = time();
					$missesChanged = true;
				}
			} catch (\Exception $e) {
				// Best effort only; keep UI functional with fallback icon endpoint
				$this->logger->debug('Skipping icon warmup for class ' . $className . ': ' . $e->getMessage(), [
					'app' => Application::APP_ID,
				]);
				$misses[$className] = time();
				$missesChanged = true;
			}
		}

		if ($missesChanged) {
			$this->saveIconCacheMeta('icon_discovery_misses.json', $misses);
		}
	}

	/**
	 * Load a JSON metadata file from the ci_class_icons appdata folder.
	 *
	 * @param string $fileName
	 * @return array
	 */
	private function loadIconCacheMeta(string $fileName): array {
		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);
			$folder = $appData->getFolder('ci_class_icons');
			$content = $folder->getFile($fileName)->getContent();
			$data = json_decode($content, true);
			return is_array($data) ? $data : [];
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Save a JSON metadata file into the ci_class_icons appdata folder.
	 *
	 * @param string $fileName
	 * @param array $data
	 * @return void
	 */
	private function saveIconCacheMeta(string $fileName, array $data): void {
		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);
			try {
				$folder = $appData->getFolder('ci_class_icons');
			} catch (NotFoundException $e) {
				$folder = $appData->newFolder('ci_class_icons');
			}
			$json = json_encode($data);
			try {
				$folder->getFile($fileName)->putContent($json);
			} catch (NotFoundException $e) {
				$folder->newFile($fileName)->putContent($json);
			}
		} catch (\Exception $e) {
			// Best effort only
			$this->logger->debug('Could not persist icon cache metadata: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
		}
	}

	/**
	 * Cache a class icon from iTop into appdata if not already cached.
	 *
	 * @param string $class
	 * @param string $adminInstanceUrl
	 * @param string $applicationToken
	 * @return bool true if the icon is cached (already or newly), false if discovery/download failed
	 */
	private function cacheClassIconFromItop(string $class, string $adminInstanceUrl, string $applicationToken): bool {
		$class = preg_replace('/[^A-Za-z0-9_]/', '', $class);
		if ($class === '') {
			return false;
		}

		// Already cached
		if ($this->getCachedClassIconFile($class) !== null) {
			return true;
		}

		$iconUrl = $this->discoverClassIconUrl($class, $adminInstanceUrl, $applicationToken);
		if ($iconUrl === null) {
			return false;
		}

		$downloaded = $this->downloadClassIcon($iconUrl, $applicationToken);
		if ($downloaded === null) {
			return false;
		}

		$this->storeClassIconContent($class, $downloaded['content'], $downloaded['extension']);
		return true;
	}

	/**
	 * Discover class icon URL using datamodel metadata first, then schema page fallback.
	 *
	 * @param string $class
	 * @param string $adminInstanceUrl
	 * @param string $applicationToken
	 * @return string|null
	 */
	private function discoverClassIconUrl(string $class, string $adminInstanceUrl, string $applicationToken): ?string {
		$iconUrl = $this->discoverClassIconUrlFromDatamodel($class, $adminInstanceUrl, $applicationToken);
		if ($iconUrl !== null) {
			return $iconUrl;
		}

		return $this->discoverClassIconUrlFromSchema($class, $adminInstanceUrl, $applicationToken);
	}

	/**
	 * Discover class icon URL from iTop datamodel XML files.
	 *
	 * @param string $class
	 * @param string $adminInstanceUrl
	 * @param string $applicationToken
	 * @return string|null
	 */
	private function discoverClassIconUrlFromDatamodel(string $class, string $adminInstanceUrl, string $applicationToken): ?string {
		$classDefinitions = $this->getItopClassDefinitions($adminInstanceUrl, $applicationToken);
		if (empty($classDefinitions)) {
			return null;
		}

		$iconPath = $this->resolveClassIconPath($class, $classDefinitions);
		if ($iconPath === null) {
			return null;
		}

		return $this->buildAbsoluteItopIconUrl($adminInstanceUrl, $iconPath);
	}

	/**
	 * Build and cache a class definition map from iTop datamodel XML files.
	 *
	 * @param string $adminInstanceUrl
	 * @param string $applicationToken
	 * @return array<string, array{parent:?string, icon:?string}>
	 */
	private function getItopClassDefinitions(string $adminInstanceUrl, string $applicationToken): array {
		if (isset($this->itopClassDefinitionsCache[$adminInstanceUrl])) {
			return $this->itopClassDefinitionsCache[$adminInstanceUrl];
		}

		// Persistent cache: the datamodel crawl is expensive (one HTTP request per
		// module directory + one per XML file), so reuse the parsed map for 24h
		$persisted = $this->loadIconCacheMeta('class_definitions.json');
		if (($persisted['url'] ?? '') === $adminInstanceUrl
			&& is_array($persisted['definitions'] ?? null)
			&& (time() - (int)($persisted['timestamp'] ?? 0)) < self::ICON_CACHE_TTL) {
			$this->itopClassDefinitionsCache[$adminInstanceUrl] = $persisted['definitions'];
			return $persisted['definitions'];
		}

		$classDefinitions = [];
		$xmlUrls = $this->getItopDatamodelXmlUrls($adminInstanceUrl, $applicationToken);
		foreach ($xmlUrls as $xmlUrl) {
			$xmlContent = $this->downloadTextResource($xmlUrl, $applicationToken);
			if ($xmlContent === null) {
				continue;
			}
			$this->mergeClassDefinitionsFromXml($xmlContent, $classDefinitions);
		}

		$this->itopClassDefinitionsCache[$adminInstanceUrl] = $classDefinitions;
		$this->saveIconCacheMeta('class_definitions.json', [
			'url' => $adminInstanceUrl,
			'timestamp' => time(),
			'definitions' => $classDefinitions,
		]);
		return $classDefinitions;
	}

	/**
	 * List datamodel XML URLs from iTop directory indexes.
	 *
	 * @param string $adminInstanceUrl
	 * @param string $applicationToken
	 * @return array<int, string>
	 */
	private function getItopDatamodelXmlUrls(string $adminInstanceUrl, string $applicationToken): array {
		$baseDatamodelUrl = rtrim($adminInstanceUrl, '/') . '/datamodels/2.x/';
		$indexHtml = $this->downloadTextResource($baseDatamodelUrl, $applicationToken);
		if ($indexHtml === null) {
			return [];
		}

		preg_match_all("#href=[\"']([^\"']+/)[\"']#i", $indexHtml, $moduleMatches);
		$modules = [];
		foreach ($moduleMatches[1] ?? [] as $href) {
			$decodedHref = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
			if ($decodedHref === '' || str_contains($decodedHref, '..') || str_contains($decodedHref, '?') || str_contains($decodedHref, '#')) {
				continue;
			}
			$module = trim($decodedHref, '/');
			if ($module === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $module)) {
				continue;
			}
			$modules[$module] = true;
		}

		$xmlUrls = [rtrim($adminInstanceUrl, '/') . '/core/datamodel.core.xml'];
		$moduleNames = array_keys($modules);
		sort($moduleNames);

		foreach ($moduleNames as $moduleName) {
			$moduleUrl = $baseDatamodelUrl . rawurlencode($moduleName) . '/';
			$moduleHtml = $this->downloadTextResource($moduleUrl, $applicationToken);
			if ($moduleHtml === null) {
				continue;
			}

			preg_match_all("#href=[\"'](datamodel[^\"']*\\.xml)[\"']#i", $moduleHtml, $fileMatches);
			foreach ($fileMatches[1] ?? [] as $xmlFileName) {
				$decodedFileName = html_entity_decode($xmlFileName, ENT_QUOTES | ENT_HTML5);
				if ($decodedFileName === '') {
					continue;
				}
				$xmlUrls[] = $moduleUrl . ltrim($decodedFileName, '/');
			}
		}

		$xmlUrls = array_values(array_unique($xmlUrls));
		sort($xmlUrls);
		return $xmlUrls;
	}

	/**
	 * Download a text resource from iTop, trying with token first and without token as fallback.
	 *
	 * @param string $url
	 * @param string $applicationToken
	 * @return string|null
	 */
	private function downloadTextResource(string $url, string $applicationToken): ?string {
		$client = $this->clientService->newClient();
		$response = null;

		try {
			$response = $client->get($url, [
				'headers' => [
					'Auth-Token' => $applicationToken,
					'User-Agent' => 'Nextcloud-iTop-Integration/1.0',
				],
				'timeout' => 8,
			]);
		} catch (\Exception $e) {
			try {
				$response = $client->get($url, [
					'headers' => [
						'User-Agent' => 'Nextcloud-iTop-Integration/1.0',
					],
					'timeout' => 8,
				]);
			} catch (\Exception $e2) {
				return null;
			}
		}

		if ($response === null) {
			return null;
		}

		$content = (string)$response->getBody();
		return $content === '' ? null : $content;
	}

	/**
	 * Merge class definitions from one datamodel XML content block.
	 *
	 * @param string $xmlContent
	 * @param array<string, array{parent:?string, icon:?string}> $classDefinitions
	 * @return void
	 */
	private function mergeClassDefinitionsFromXml(string $xmlContent, array &$classDefinitions): void {
		$dom = new \DOMDocument();
		$loaded = @$dom->loadXML($xmlContent);
		if ($loaded === false) {
			return;
		}

		$xpath = new \DOMXPath($dom);
		$classNodes = $xpath->query('//class[@id]');
		if ($classNodes === false) {
			return;
		}

		foreach ($classNodes as $classNode) {
			$className = trim((string)$classNode->attributes?->getNamedItem('id')?->nodeValue);
			if ($className === '') {
				continue;
			}

			if (!isset($classDefinitions[$className])) {
				$classDefinitions[$className] = [
					'parent' => null,
					'icon' => null,
				];
			}

			$parentNode = $xpath->query('./parent', $classNode);
			$parentClass = trim((string)($parentNode !== false ? $parentNode->item(0)?->textContent : ''));
			if ($parentClass !== '') {
				$classDefinitions[$className]['parent'] = $parentClass;
			}

			$iconNode = $xpath->query('./properties/style/icon', $classNode);
			$iconPath = trim((string)($iconNode !== false ? $iconNode->item(0)?->textContent : ''));
			if ($iconPath !== '') {
				$classDefinitions[$className]['icon'] = $iconPath;
			}
		}
	}

	/**
	 * Resolve icon path for class by following parent classes until an icon is found.
	 *
	 * @param string $class
	 * @param array<string, array{parent:?string, icon:?string}> $classDefinitions
	 * @return string|null
	 */
	private function resolveClassIconPath(string $class, array $classDefinitions): ?string {
		$currentClass = $class;
		$visited = [];

		while ($currentClass !== '' && !isset($visited[$currentClass])) {
			$visited[$currentClass] = true;
			$definition = $classDefinitions[$currentClass] ?? null;
			if ($definition === null) {
				return null;
			}

			$iconPath = trim((string)($definition['icon'] ?? ''));
			if ($iconPath !== '') {
				return $iconPath;
			}

			$currentClass = trim((string)($definition['parent'] ?? ''));
		}

		return null;
	}

	/**
	 * Build an absolute URL for an iTop class icon path.
	 *
	 * @param string $adminInstanceUrl
	 * @param string $iconPath
	 * @return string|null
	 */
	private function buildAbsoluteItopIconUrl(string $adminInstanceUrl, string $iconPath): ?string {
		$iconPath = trim($iconPath);
		if ($iconPath === '') {
			return null;
		}
		if (preg_match('#^https?://#i', $iconPath)) {
			return $iconPath;
		}

		$parts = parse_url($adminInstanceUrl);
		$scheme = $parts['scheme'] ?? 'http';
		$host = $parts['host'] ?? '';
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		if ($host === '') {
			return null;
		}

		$hostRoot = $scheme . '://' . $host . $port;
		$basePath = trim($parts['path'] ?? '', '/');
		$appRoot = $hostRoot . ($basePath !== '' ? '/' . $basePath : '');

		$normalizedPath = preg_replace('#^([.]/)+#', '', $iconPath) ?? $iconPath;
		while (str_starts_with($normalizedPath, '../')) {
			$normalizedPath = substr($normalizedPath, 3);
		}

		if (str_starts_with($normalizedPath, '//')) {
			return $scheme . ':' . $normalizedPath;
		}
		if (str_starts_with($normalizedPath, '/')) {
			if ($basePath !== '' && str_starts_with($normalizedPath, '/' . $basePath . '/')) {
				return $hostRoot . $normalizedPath;
			}
			return $basePath !== '' ? $appRoot . $normalizedPath : $hostRoot . $normalizedPath;
		}

		return $appRoot . '/' . ltrim($normalizedPath, '/');
	}

	/**
	 * Discover class icon URL from iTop schema details page.
	 *
	 * @param string $class
	 * @param string $adminInstanceUrl
	 * @param string $applicationToken
	 * @return string|null
	 */
	private function discoverClassIconUrlFromSchema(string $class, string $adminInstanceUrl, string $applicationToken): ?string {
		$schemaUrl = rtrim($adminInstanceUrl, '/') . '/pages/schema.php?operation=details&class=' . rawurlencode($class);
		$client = $this->clientService->newClient();

		try {
			$response = $client->get($schemaUrl, [
				'headers' => [
					'Auth-Token' => $applicationToken,
					'User-Agent' => 'Nextcloud-iTop-Integration/1.0',
				],
				'timeout' => 20,
			]);
		} catch (\Exception $e) {
			// If iTop does not allow token auth on UI endpoints, silently skip
			return null;
		}

		$html = (string)$response->getBody();
		if ($html === '' || stripos($html, 'iTop login') !== false) {
			return null;
		}

		if (!preg_match_all('/<img[^>]+src=[\'"]([^\'"]+\.(?:svg|png|webp|jpe?g))[\'"]/i', $html, $matches)) {
			return null;
		}

		$candidates = $matches[1] ?? [];
		foreach ($candidates as $candidate) {
			// Keep only likely class icon assets from iTop images/modules paths
			if (stripos($candidate, '/images/') === false && stripos($candidate, '/modules/') === false && stripos($candidate, '/env-') === false) {
				continue;
			}
			return $this->normalizeIconUrl($adminInstanceUrl, $candidate);
		}

		return null;
	}

	/**
	 * Normalize absolute/relative icon URL using iTop base URL.
	 *
	 * @param string $baseUrl
	 * @param string $iconUrl
	 * @return string
	 */
	private function normalizeIconUrl(string $baseUrl, string $iconUrl): string {
		$normalized = $this->buildAbsoluteItopIconUrl($baseUrl, $iconUrl);
		return $normalized ?? $iconUrl;
	}

	/**
	 * Download icon content from iTop and return content + file extension.
	 *
	 * @param string $iconUrl
	 * @param string $applicationToken
	 * @return array{content:string, extension:string}|null
	 */
	private function downloadClassIcon(string $iconUrl, string $applicationToken): ?array {
		$client = $this->clientService->newClient();
		$response = null;

		try {
			$response = $client->get($iconUrl, [
				'headers' => [
					'Auth-Token' => $applicationToken,
					'User-Agent' => 'Nextcloud-iTop-Integration/1.0',
				],
				'timeout' => 20,
			]);
		} catch (\Exception $e) {
			// Retry without token for publicly served static assets
			try {
				$response = $client->get($iconUrl, [
					'headers' => [
						'User-Agent' => 'Nextcloud-iTop-Integration/1.0',
					],
					'timeout' => 20,
				]);
			} catch (\Exception $e2) {
				return null;
			}
		}

		if ($response === null) {
			return null;
		}

		$content = (string)$response->getBody();
		if ($content === '' || strlen($content) > 524288) {
			return null;
		}

		$extension = strtolower(pathinfo(parse_url($iconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
		if (!in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'webp'], true)) {
			$contentType = strtolower((string)($response->getHeader('Content-Type')[0] ?? ''));
			if (str_contains($contentType, 'svg')) {
				$extension = 'svg';
			} elseif (str_contains($contentType, 'png')) {
				$extension = 'png';
			} elseif (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
				$extension = 'jpg';
			} elseif (str_contains($contentType, 'webp')) {
				$extension = 'webp';
			}
		}

		if (!in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'webp'], true)) {
			return null;
		}

		// Lightweight content sanity checks
		if ($extension === 'svg') {
			$stripped = ltrim($content, " \t\n\r\0\x0B\xEF\xBB\xBF");
			if (stripos($stripped, '<svg') === false) {
				return null;
			}
		}

		return [
			'content' => $content,
			'extension' => $extension,
		];
	}

	/**
	 * Store class icon in appdata and remove stale icons with other extensions.
	 *
	 * @param string $class
	 * @param string $content
	 * @param string $extension
	 * @return void
	 * @throws NotFoundException
	 */
	private function storeClassIconContent(string $class, string $content, string $extension): void {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			$folder = $appData->getFolder('ci_class_icons');
		} catch (NotFoundException $e) {
			$folder = $appData->newFolder('ci_class_icons');
		}

		// Remove old variants before writing new one
		foreach (['svg', 'png', 'jpg', 'jpeg', 'webp'] as $ext) {
			if ($ext === $extension) {
				continue;
			}
			try {
				$folder->getFile($class . '.' . $ext)->delete();
			} catch (NotFoundException $e) {
				// ignore
			}
		}

		$fileName = $class . '.' . $extension;
		try {
			$file = $folder->getFile($fileName);
			$file->putContent($content);
		} catch (NotFoundException $e) {
			$file = $folder->newFile($fileName);
			$file->putContent($content);
		}
	}

	/**
	 * Resolve cached icon file and extension for class if present.
	 *
	 * @param string $class
	 * @return array{file:\OCP\Files\File, extension:string}|null
	 */
	private function getCachedClassIconFile(string $class): ?array {
		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);
			$folder = $appData->getFolder('ci_class_icons');
			foreach (['svg', 'png', 'jpg', 'jpeg', 'webp'] as $ext) {
				try {
					$file = $folder->getFile($class . '.' . $ext);
					return [
						'file' => $file,
						'extension' => $ext,
					];
				} catch (NotFoundException $e) {
					// continue
				}
			}
		} catch (\Exception $e) {
			// ignore and fallback
		}
		return null;
	}

	/**
	 * Upload a custom SVG icon for a CI class (admin only).
	 *
	 * The SVG is stored in appdata so it survives app updates and does not
	 * pollute the app's img/ directory (which is part of the distributed package).
	 *
	 * @param string $class CI class name (e.g. "Monitor", "Scanner")
	 * @return DataResponse
	 */
	public function uploadCIClassIcon(string $class): DataResponse {
		// Validate class name
		$class = trim($class);
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $class)) {
			return new DataResponse(['error' => $this->l10n->t('Invalid class name')], Http::STATUS_BAD_REQUEST);
		}

		// Read raw SVG from request body
		$svgContent = file_get_contents('php://input');
		if ($svgContent === false || strlen($svgContent) === 0) {
			return new DataResponse(['error' => $this->l10n->t('No file content received')], Http::STATUS_BAD_REQUEST);
		}

		// Size guard (max 256 KB)
		if (strlen($svgContent) > 262144) {
			return new DataResponse(['error' => $this->l10n->t('Icon file too large (max 256 KB)')], Http::STATUS_BAD_REQUEST);
		}

		// Basic SVG content check (strip BOM + leading whitespace before looking for <svg)
		$stripped = ltrim($svgContent, " \t\n\r\0\x0B\xEF\xBB\xBF");
		if (stripos($stripped, '<svg') === false) {
			return new DataResponse(['error' => $this->l10n->t('File does not appear to be a valid SVG')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);
			try {
				$folder = $appData->getFolder('ci_class_icons');
			} catch (NotFoundException $e) {
				$folder = $appData->newFolder('ci_class_icons');
			}
			try {
				$file = $folder->getFile($class . '.svg');
				$file->putContent($svgContent);
			} catch (NotFoundException $e) {
				$file = $folder->newFile($class . '.svg');
				$file->putContent($svgContent);
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to save CI class icon for ' . $class . ': ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
			return new DataResponse(['error' => $this->l10n->t('Failed to save icon')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse(['message' => $this->l10n->t('Icon saved successfully')]);
	}

	/**
	 * Serve the SVG icon for a CI class.
	 *
	 * Looks for a custom icon uploaded via uploadCIClassIcon() in appdata first.
	 * Falls back to Peripheral.svg from the app's img/ directory when none exists.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param string $class CI class name
	 * @return DataDisplayResponse
	 */
	public function getCIClassIcon(string $class): DataDisplayResponse {
		// Strip anything that could form a path traversal
		$class = preg_replace('/[^A-Za-z0-9_]/', '', $class);

		// Try custom icon from appdata
		try {
			$cached = $this->getCachedClassIconFile($class);
			if ($cached !== null) {
				$mimeByExt = [
					'svg' => 'image/svg+xml',
					'png' => 'image/png',
					'jpg' => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'webp' => 'image/webp',
				];
				$mime = $mimeByExt[$cached['extension']] ?? 'application/octet-stream';
				return new DataDisplayResponse($cached['file']->getContent(), Http::STATUS_OK, ['Content-Type' => $mime]);
			}
		} catch (NotFoundException $e) {
			// No custom icon uploaded — fall through to Peripheral.svg
		} catch (\Exception $e) {
			$this->logger->warning('Could not read custom CI class icon for ' . $class . ': ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
		}

		// Fallback: Peripheral.svg bundled with the app
		$appPath = $this->appManager->getAppPath(Application::APP_ID);
		$fallbackPath = $appPath . '/img/Peripheral.svg';
		if (file_exists($fallbackPath)) {
			return new DataDisplayResponse(
				file_get_contents($fallbackPath),
				Http::STATUS_OK,
				['Content-Type' => 'image/svg+xml']
			);
		}

		return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
	}

	/**
	 * Delete the custom SVG icon for a CI class (admin only).
	 *
	 * After deletion the icon endpoint will automatically fall back to Peripheral.svg.
	 *
	 * @param string $class CI class name
	 * @return DataResponse
	 */
	public function deleteCIClassIcon(string $class): DataResponse {
		$class = preg_replace('/[^A-Za-z0-9_]/', '', $class);
		if ($class === '') {
			return new DataResponse(['error' => $this->l10n->t('Invalid class name')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);
			$folder = $appData->getFolder('ci_class_icons');
			foreach (['svg', 'png', 'jpg', 'jpeg', 'webp'] as $ext) {
				try {
					$folder->getFile($class . '.' . $ext)->delete();
				} catch (NotFoundException $e) {
					// continue
				}
			}
		} catch (NotFoundException $e) {
			// Already gone — treat as success
		} catch (\Exception $e) {
			$this->logger->error('Failed to delete CI class icon for ' . $class . ': ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
			return new DataResponse(['error' => $this->l10n->t('Failed to delete icon')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse(['message' => $this->l10n->t('Icon deleted successfully')]);
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
			$query = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
			$result = $query->select($query->func()->count('*', 'count'))
				->from('preferences')
				->where($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
				->andWhere($query->expr()->eq('configkey', $query->createNamedParameter('person_id')))
				->andWhere($query->expr()->neq('configvalue', $query->createNamedParameter('')))
				->executeQuery();

			$row = $result->fetch();
			$result->closeCursor();
			// Database may return the count as 'count' or 'COUNT(*)' depending on driver
			return (int) ($row['count'] ?? $row['COUNT(*)'] ?? 0);
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
