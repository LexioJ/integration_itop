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
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Cache Service for iTop Integration
 *
 * Provides distributed caching for CI previews, search results, and profile data
 * to minimize API calls and improve performance. Uses Nextcloud's distributed
 * cache (Redis/Memcached if available, falls back to file-based cache).
 *
 * Cache Strategy (from docs/caching-performance.md):
 * - CI Previews: 60s TTL (relatively stable data, but changes should appear quickly)
 * - Search Results: 30s TTL (dynamic, needs freshness)
 * - Profile Status: 300s TTL (handled by ProfileService directly)
 */
class CacheService {
	private ICache $cache;

	// Cache TTLs (in seconds)
	private const CI_PREVIEW_TTL = 60;
	private const TICKET_INFO_TTL = 60;
	private const SEARCH_RESULTS_TTL = 30;

	// Cache key prefixes
	private const PREFIX_CI_PREVIEW = 'ci_preview';
	private const PREFIX_TICKET_INFO = 'ticket_info';
	private const PREFIX_SEARCH = 'search';

	// Wrapper keys for stored data
	private const WRAPPER_CACHED_AT = 'cached_at';
	private const WRAPPER_TTL = 'ttl';
	private const WRAPPER_DATA = 'data';

	public function __construct(
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		// Use distributed cache for multi-server deployments
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '_ci_data');
	}

	/**
	 * Get cached CI preview
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $class iTop CI class
	 * @param int $id CI ID
	 * @return array|null Cached preview data or null if not found/expired
	 */
	public function getCIPreview(string $userId, string $class, int $id): ?array {
		$key = $this->buildCIPreviewKey($userId, $class, $id);
		$cached = $this->cache->get($key);

		if ($cached !== null) {
			$wrapper = json_decode($cached, true);

			// Validate wrapper structure
			if (!is_array($wrapper) || !isset($wrapper['cached_at']) || !isset($wrapper['ttl']) || !isset($wrapper['data'])) {
				// Invalid cache format - treat as miss
				$this->logger->warning('CI preview cache invalid format', [
					'app' => Application::APP_ID,
					'userId' => $userId,
					'class' => $class,
					'id' => $id
				]);
				$this->cache->remove($key);
				return null;
			}

			// Application-level TTL validation
			$currentTime = time();
			$age = $currentTime - $wrapper['cached_at'];
			if ($age > $wrapper['ttl']) {
				// Expired - enforce TTL at application level
				$this->logger->info('CI preview cache EXPIRED', [
					'app' => Application::APP_ID,
					'userId' => $userId,
					'class' => $class,
					'id' => $id,
					'cached_at' => $wrapper['cached_at'],
					'current_time' => $currentTime,
					'age' => $age,
					'ttl' => $wrapper['ttl'],
					'exceeded_by' => $age - $wrapper['ttl'] . 's'
				]);
				$this->cache->remove($key);
				return null;
			}

			$this->logger->debug('CI preview cache hit', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'class' => $class,
				'id' => $id,
				'age' => $age,
				'ttl' => $wrapper['ttl']
			]);
			return $wrapper['data'];
		}

		$this->logger->debug('CI preview cache miss', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'class' => $class,
			'id' => $id
		]);
		return null;
	}

	/**
	 * Set CI preview in cache
	 *
	 * Wraps data with timestamp for application-level TTL validation
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $class iTop CI class
	 * @param int $id CI ID
	 * @param array $previewData Preview data to cache
	 * @return void
	 */
	public function setCIPreview(string $userId, string $class, int $id, array $previewData): void {
		$key = $this->buildCIPreviewKey($userId, $class, $id);

		// Wrap data with timestamp for application-level TTL enforcement
		$wrapper = [
			'cached_at' => time(),
			'ttl' => self::CI_PREVIEW_TTL,
			'data' => $previewData
		];

		$this->cache->set($key, json_encode($wrapper), self::CI_PREVIEW_TTL);

		$this->logger->debug('CI preview cached', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'class' => $class,
			'id' => $id,
			'ttl' => self::CI_PREVIEW_TTL,
			'cached_at' => $wrapper['cached_at']
		]);
	}


	/**
	 * Invalidate CI preview cache for a specific CI
	 *
	 * Useful when CI data is updated and immediate refresh is needed
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $class iTop CI class
	 * @param int $id CI ID
	 * @return void
	 */
	public function invalidateCIPreview(string $userId, string $class, int $id): void {
		$key = $this->buildCIPreviewKey($userId, $class, $id);
		$this->cache->remove($key);

		$this->logger->info('CI preview cache invalidated', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'class' => $class,
			'id' => $id
		]);
	}

	/**
	 * Get cached ticket info
	 *
	 * Caches both UserRequest and Incident ticket details with timestamps
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $ticketId Ticket ID
	 * @param string $class Ticket class (UserRequest or Incident)
	 * @return array|null Cached ticket data or null if not found/expired
	 */
	public function getTicketInfo(string $userId, int $ticketId, string $class): ?array {
		$key = $this->buildTicketInfoKey($userId, $ticketId, $class);
		$cached = $this->cache->get($key);

		if ($cached !== null) {
			$wrapper = json_decode($cached, true);

			if (!is_array($wrapper) || !isset($wrapper[self::WRAPPER_CACHED_AT], $wrapper[self::WRAPPER_TTL], $wrapper[self::WRAPPER_DATA])) {
				$this->cache->remove($key);
				return null;
			}

			$age = time() - $wrapper[self::WRAPPER_CACHED_AT];

			if ($age > $wrapper[self::WRAPPER_TTL]) {
				$this->logger->info('Ticket info cache expired', [
					'app' => Application::APP_ID,
					'userId' => $userId,
					'ticketId' => $ticketId,
					'class' => $class,
					'age' => $age,
					'ttl' => $wrapper[self::WRAPPER_TTL]
				]);
				$this->cache->remove($key);
				return null;
			}

			$this->logger->debug('Ticket info cache hit', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'ticketId' => $ticketId,
				'class' => $class,
				'age' => $age
			]);
			return $wrapper[self::WRAPPER_DATA];
		}

		$this->logger->debug('Ticket info cache miss', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'ticketId' => $ticketId,
			'class' => $class
		]);
		return null;
	}

	/**
	 * Set ticket info in cache
	 *
	 * Caches ticket details with timestamp for TTL validation
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $ticketId Ticket ID
	 * @param string $class Ticket class (UserRequest or Incident)
	 * @param array $ticketData Ticket data to cache
	 * @return void
	 */
	public function setTicketInfo(string $userId, int $ticketId, string $class, array $ticketData): void {
		$key = $this->buildTicketInfoKey($userId, $ticketId, $class);

		$wrapper = [
			self::WRAPPER_CACHED_AT => time(),
			self::WRAPPER_TTL => self::TICKET_INFO_TTL,
			self::WRAPPER_DATA => $ticketData
		];

		$this->cache->set($key, json_encode($wrapper), self::TICKET_INFO_TTL);

		$this->logger->debug('Ticket info cached', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'ticketId' => $ticketId,
			'class' => $class,
			'ttl' => self::TICKET_INFO_TTL
		]);
	}

	/**
	 * Invalidate ticket info cache for a specific ticket
	 *
	 * Called when ticket data is updated and immediate refresh is needed
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $ticketId Ticket ID
	 * @param string $class Ticket class (UserRequest or Incident)
	 * @return void
	 */
	public function invalidateTicketInfo(string $userId, int $ticketId, string $class): void {
		$key = $this->buildTicketInfoKey($userId, $ticketId, $class);
		$this->cache->remove($key);

		$this->logger->info('Ticket info cache invalidated', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'ticketId' => $ticketId,
			'class' => $class
		]);
	}

	/**
	 * Get cached search results
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $term Search term
	 * @param array $classes CI classes searched
	 * @param bool $isPortalOnly Whether portal-only filtering was applied
	 * @return array|null Cached search results or null if not found/expired
	 */
	public function getSearchResults(string $userId, string $term, array $classes, bool $isPortalOnly): ?array {
		$key = $this->buildSearchKey($userId, $term, $classes, $isPortalOnly);
		$cached = $this->cache->get($key);

		if ($cached !== null) {
			$this->logger->debug('Search results cache hit', [
				'app' => Application::APP_ID,
				'userId' => $userId,
				'term' => $term,
				'isPortalOnly' => $isPortalOnly
			]);
			return json_decode($cached, true);
		}

		$this->logger->debug('Search results cache miss', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'term' => $term
		]);
		return null;
	}

	/**
	 * Set search results in cache
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $term Search term
	 * @param array $classes CI classes searched
	 * @param bool $isPortalOnly Whether portal-only filtering was applied
	 * @param array $results Search results to cache
	 * @return void
	 */
	public function setSearchResults(string $userId, string $term, array $classes, bool $isPortalOnly, array $results): void {
		$key = $this->buildSearchKey($userId, $term, $classes, $isPortalOnly);
		$this->cache->set($key, json_encode($results), self::SEARCH_RESULTS_TTL);

		$this->logger->debug('Search results cached', [
			'app' => Application::APP_ID,
			'userId' => $userId,
			'term' => $term,
			'resultCount' => count($results),
			'ttl' => self::SEARCH_RESULTS_TTL
		]);
	}

	/**
	 * Invalidate all search results for a user
	 *
	 * Useful when user's profile changes (portal-only → power user or vice versa)
	 * Note: Only invalidates known search keys, not all possible searches
	 *
	 * @param string $userId Nextcloud user ID
	 * @return void
	 */
	public function invalidateUserSearchCache(string $userId): void {
		// Since we can't enumerate all search keys, we'll rely on TTL expiration
		// This is a placeholder for future implementation if needed (e.g., key pattern matching)
		$this->logger->info('User search cache invalidation requested (will expire naturally)', [
			'app' => Application::APP_ID,
			'userId' => $userId
		]);
	}

	/**
	 * Build cache key for CI preview
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $class iTop CI class
	 * @param int $id CI ID
	 * @return string Cache key
	 */
	private function buildCIPreviewKey(string $userId, string $class, int $id): string {
		return self::PREFIX_CI_PREVIEW . ':' . $userId . ':' . $class . ':' . $id;
	}

	/**
	 * Build cache key for ticket info
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $ticketId Ticket ID
	 * @param string $class Ticket class (UserRequest or Incident)
	 * @return string Cache key
	 */
	private function buildTicketInfoKey(string $userId, int $ticketId, string $class): string {
		return self::PREFIX_TICKET_INFO . ':' . $userId . ':' . $class . ':' . $ticketId;
	}

	/**
	 * Build cache key for search results
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string $term Search term
	 * @param array $classes CI classes searched
	 * @param bool $isPortalOnly Whether portal-only filtering was applied
	 * @return string Cache key
	 */
	private function buildSearchKey(string $userId, string $term, array $classes, bool $isPortalOnly): string {
		// Normalize classes for consistent key generation
		sort($classes);
		$classesStr = implode(',', $classes);
		$portalFlag = $isPortalOnly ? '1' : '0';

		// Hash the term to keep key length reasonable
		$termHash = md5(strtolower($term));

		return self::PREFIX_SEARCH . ':' . $userId . ':' . $termHash . ':' . $classesStr . ':' . $portalFlag;
	}

	/**
	 * Clear all cache entries for the app
	 *
	 * Use with caution - this clears all CI preview and search caches for all users
	 * Useful for testing or after major iTop data changes
	 *
	 * @return void
	 */
	public function clearAll(): void {
		$this->cache->clear();

		$this->logger->warning('All cache entries cleared', [
			'app' => Application::APP_ID
		]);
	}
}
