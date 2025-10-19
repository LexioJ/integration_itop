<?php

/**
 * Nextcloud - iTop
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author iTop Integration Team
 * @copyright iTop Integration Team 2025
 */

namespace OCA\Itop\Service;

use OCA\Itop\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Profile Service for detecting and caching user profiles
 *
 * Determines if a user is portal-only (only has "Portal user" profile)
 * or a power user (has additional profiles like Service Desk Agent, etc.)
 *
 * Uses caching with 300s TTL to minimize API calls while maintaining reasonable freshness
 */
class ProfileService {
	private const CACHE_TTL = 300; // 5 minutes
	private const PORTAL_PROFILE_NAME = 'Portal user';

	public function __construct(
		private IConfig $config,
		private ItopAPIService $itopAPIService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check if user has only "Portal user" profile (no additional profiles)
	 *
	 * Portal-only users have restricted access - they can only see CIs where they are
	 * listed as a contact. Users with additional profiles get full CMDB access within ACL.
	 *
	 * @param string $userId Nextcloud user ID
	 * @return bool True if user has ONLY Portal user profile, false if has additional profiles
	 * @throws \Exception If profile detection fails
	 */
	public function isPortalOnly(string $userId): bool {
		// Check cache first
		$cached = $this->getCachedProfileStatus($userId);
		if ($cached !== null) {
			return $cached;
		}

		// Fetch fresh profile data
		$profiles = $this->getUserProfiles($userId);

		// Portal-only = exactly one profile named "Portal user"
		$isPortalOnly = (count($profiles) === 1 && $profiles[0] === self::PORTAL_PROFILE_NAME);

		// Cache the result
		$this->cacheProfileStatus($userId, $isPortalOnly);

		return $isPortalOnly;
	}

	/**
	 * Get list of profile names for a user
	 *
	 * @param string $userId Nextcloud user ID
	 * @return array List of profile names (e.g., ["Portal user", "Service Desk Agent"])
	 * @throws \Exception If API request fails or user not found
	 */
	public function getUserProfiles(string $userId): array {
		// Get user_id from config (stored during personal token validation)
		$itopUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id', '');

		if (empty($itopUserId)) {
			// Fallback: Try to get via person_id
			$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');

			if (empty($personId)) {
				throw new \Exception('User not configured - missing person_id and user_id');
			}

			// Query User class by contactid to get user_id
			$itopUserId = $this->getUserIdByPersonId($userId, $personId);

			if (empty($itopUserId)) {
				// User has no User account in iTop - assume portal-only
				$this->logger->info('User has Person but no User account in iTop - treating as portal-only', [
					'app' => Application::APP_ID,
					'userId' => $userId,
					'personId' => $personId
				]);
				return [self::PORTAL_PROFILE_NAME];
			}

			// Cache the user_id for future use
			$this->config->setUserValue($userId, Application::APP_ID, 'user_id', $itopUserId);
		}

		// Query User record to get profile_list
		$params = [
			'operation' => 'core/get',
			'class' => 'User',
			'key' => $itopUserId,
			'output_fields' => 'id,login,profile_list'
		];

		$result = $this->itopAPIService->request($userId, $params);

		if (isset($result['error']) || empty($result['objects'])) {
			throw new \Exception('Failed to fetch user profiles: ' . ($result['error'] ?? 'No user found'));
		}

		$userObject = reset($result['objects']);
		$fields = $userObject['fields'] ?? [];

		// Parse profile_list (it's typically a formatted string with profile names)
		$profiles = $this->parseProfileList($fields['profile_list'] ?? '');

		if (empty($profiles)) {
			// No profiles returned - user might have no profiles or API error
			// Log warning and assume portal-only for safety
			$this->logger->warning('User has no profiles in iTop - treating as portal-only', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'itopUserId' => $itopUserId
			]);
			return [self::PORTAL_PROFILE_NAME];
		}

		return $profiles;
	}

	/**
	 * Get User ID by Person ID (lookup via contactid field)
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $personId iTop Person ID
	 * @return string|null iTop User ID or null if not found
	 */
	private function getUserIdByPersonId(string $userId, string $personId): ?string {
		$params = [
			'operation' => 'core/get',
			'class' => 'User',
			'key' => "SELECT User WHERE contactid = $personId",
			'output_fields' => 'id,login'
		];

		$result = $this->itopAPIService->request($userId, $params);

		if (isset($result['error']) || empty($result['objects'])) {
			return null;
		}

		$userObject = reset($result['objects']);
		return $userObject['fields']['id'] ?? null;
	}

	/**
	 * Parse profile_list field from iTop User record
	 *
	 * iTop's profile_list can be in various formats:
	 * - "Portal user" (single profile)
	 * - "Portal user, Service Desk Agent" (multiple profiles)
	 * - Sometimes JSON-encoded array
	 *
	 * @param mixed $profileList Profile list from iTop (string or array)
	 * @return array List of profile names
	 */
	private function parseProfileList($profileList): array {
		if (empty($profileList)) {
			return [];
		}

		// If it's already an array, return it
		if (is_array($profileList)) {
			return array_values($profileList);
		}

		// If it's a string, try to parse it
		if (is_string($profileList)) {
			// Check if it's JSON
			$decoded = json_decode($profileList, true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				return array_values($decoded);
			}

			// Otherwise, split by comma
			$profiles = array_map('trim', explode(',', $profileList));
			return array_filter($profiles); // Remove empty strings
		}

		return [];
	}

	/**
	 * Manually refresh profile cache for a user
	 *
	 * Useful when user's profiles change in iTop and immediate update is needed
	 *
	 * @param string $userId Nextcloud user ID
	 * @return void
	 */
	public function refreshProfiles(string $userId): void {
		// Delete cached status
		$this->config->deleteUserValue($userId, Application::APP_ID, 'is_portal_only');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'profiles_last_check');

		$this->logger->info('Profile cache cleared for user', [
			'app' => Application::APP_ID,
			'userId' => $userId
		]);
	}

	/**
	 * Get cached profile status if available and not expired
	 *
	 * @param string $userId Nextcloud user ID
	 * @return bool|null Cached status or null if not cached/expired
	 */
	private function getCachedProfileStatus(string $userId): ?bool {
		$cachedStatus = $this->config->getUserValue($userId, Application::APP_ID, 'is_portal_only', '');
		$lastCheck = $this->config->getUserValue($userId, Application::APP_ID, 'profiles_last_check', '');

		if ($cachedStatus === '' || $lastCheck === '') {
			return null;
		}

		$lastCheckTime = (int)$lastCheck;
		$now = time();

		// Check if cache is expired
		if (($now - $lastCheckTime) > self::CACHE_TTL) {
			return null;
		}

		return $cachedStatus === '1';
	}

	/**
	 * Cache profile status for a user
	 *
	 * @param string $userId Nextcloud user ID
	 * @param bool $isPortalOnly Whether user is portal-only
	 * @return void
	 */
	private function cacheProfileStatus(string $userId, bool $isPortalOnly): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'is_portal_only', $isPortalOnly ? '1' : '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'profiles_last_check', (string)time());

		$this->logger->debug('Profile status cached', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'isPortalOnly' => $isPortalOnly,
			'ttl' => self::CACHE_TTL
		]);
	}
}