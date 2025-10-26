# Caching and Performance Optimization

## Overview

This document outlines the caching strategies, performance optimization techniques, rate limiting approaches, and performance targets for the iTop Integration app. The goal is to minimize API load on iTop while providing responsive user experience in Nextcloud.

## Cache Layers

### 1. Client-Side Cache (Browser)

**Technology:** HTTP Cache-Control headers + Browser cache API

**What to Cache:**
- SVG icons (ticket.svg, PC.svg, etc.)
- Vue components (after build)
- Static assets

**TTL:** 24 hours for static assets

**Implementation:**
```php
// In controller responses for icons
$response = new FileDisplayResponse($iconPath);
$response->cacheFor(86400); // 24 hours
$response->addHeader('Cache-Control', 'public, max-age=86400');
return $response;
```

**Benefits:**
- Instant icon loading
- Reduced server requests
- Better offline experience

### 2. Server-Side Cache (PHP)

**Technology:** Nextcloud `ICacheFactory` (Redis/Memcached/APCu)

**What to Cache:**

| Data Type | Cache Layer | Cache Key Pattern | Default TTL | Config Key | Invalidation |
|-----------|-------------|-------------------|-------------|------------|---------------|
| **API Queries** (CIs, tickets, searches) | ItopAPIService | `api:{md5(key)}` | 60s | `cache_ttl_api_query` | TTL only |
| CI Previews | ItopReferenceProvider (via CacheService) | `ci_preview:{userId}:{class}:{id}` | 60s | *(same as API)* | Manual or TTL |
| Search Results | ItopSearchProvider | `api:{md5(query)}` | 30s | *(planned)* | TTL only |
| Profile Data | ProfileService | `profile:{userId}` | 300s | *(planned)* | Manual or TTL |
| User Info | ItopAPIService | `api:{md5(key)}` | 600s | *(planned)* | Manual |
| Picker Results | ItopReferenceProvider.search() | `api:{md5(query)}` | 60s | *(planned)* | TTL only |
| Ticket Lists | ItopAPIService | `api:{md5(key)}` | 120s | *(planned)* | TTL only |

**Key:** *(planned)* = future admin configuration in Phase 6

**Implementation:**
```php
class CacheService {
    public function __construct(
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger
    ) {
        $this->cache = $cacheFactory->createDistributed('integration_itop');
    }

    public function get(string $key): mixed {
        try {
            $value = $this->cache->get($key);
            if ($value !== null) {
                $this->logger->debug('Cache HIT', ['key' => $key]);
                return json_decode($value, true);
            }
            $this->logger->debug('Cache MISS', ['key' => $key]);
            return null;
        } catch (\Exception $e) {
            $this->logger->warning('Cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl): void {
        try {
            $this->cache->set($key, json_encode($value), $ttl);
            $this->logger->debug('Cache SET', ['key' => $key, 'ttl' => $ttl]);
        } catch (\Exception $e) {
            $this->logger->warning('Cache set failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    public function delete(string $key): void {
        $this->cache->remove($key);
        $this->logger->debug('Cache DELETE', ['key' => $key]);
    }

    public function clear(string $prefix): void {
        $this->cache->clear($prefix);
        $this->logger->info('Cache CLEAR', ['prefix' => $prefix]);
    }
}
```

### 3. Distributed Cache (Multi-Server)

**Use Case:** Nextcloud instances with multiple app servers

**Technology:** Redis (recommended) or Memcached

**Configuration:**
```php
// config/config.php
'memcache.distributed' => '\OC\Memcache\Redis',
'redis' => [
    'host' => 'localhost',
    'port' => 6379,
    'timeout' => 1.5,
],
```

**Key Namespacing:**
```
integration_itop/user_{userId}/search_{hash}
integration_itop/user_{userId}/preview_{class}_{id}
```

**Benefits:**
- Shared cache across multiple servers
- High availability
- Fast access (~1ms)

## Query-Based API Caching (ItopAPIService)

**Status:** ✅ Implemented (Phase 2-3)

**Overview:** The `ItopAPIService->request()` method implements automatic query-based caching independent of user ID. All API responses are cached based on the OQL query or ID provided, allowing multiple users to share the same cache entry for identical queries.

### Cache Flow: API Call → Store → Deliver

```
1. User A requests CI id=32
   ├─ Build cache key: md5("32") → "c4ca4238a0b923820dcc509a6f75849b"
   ├─ Check cache: cache.get("api:c4ca4238...") → null (MISS)
   ├─ Call iTop API with app token
   ├─ Add metadata: _cache_timestamp + _cache_ttl
   └─ Store in cache: cache.set(..., 60s TTL)

2. User B requests same CI id=32 (within 60s)
   ├─ Build same cache key: md5("32")
   ├─ Check cache: cache.get("api:c4ca4238...") → hit!
   ├─ Validate: age (15s) < ttl (60s) ✓
   └─ Return cached result (no API call)

3. User A requests CI id=32 after 60s
   ├─ Build cache key: md5("32")
   ├─ Check cache: cache.get(...) → null (expired)
   ├─ Call iTop API again
   └─ Cache refreshed with new TTL
```

### Implementation Details

**Cache Key Generation:**
```php
$key = $params['key'] ?? '';  // OQL query or ID
$keyHash = md5($key);         // Hash for reasonable length
return 'api:' . $keyHash;     // Final key: api:c4ca4238...
```

**Examples of Query Keys:**
```
Direct ID lookup:
  $params['key'] = "32"
  Cache key: api:c4ca4238a0b923820dcc509a6f75849b

OQL query:
  $params['key'] = "SELECT PC WHERE name LIKE '%APC0001%'"
  Cache key: api:a1b2c3d4e5f6...

Search query:
  $params['key'] = "SELECT Software AS ci WHERE ci.name LIKE '%Office%'"
  Cache key: api:f6e5d4c3b2a1...
```

**Metadata Storage:**
Cache payload includes internal metadata for explicit TTL validation:
```json
{
  "code": 0,
  "message": "Found: 1",
  "objects": {...},
  "_cache_timestamp": 1729886322,
  "_cache_ttl": 60
}
```
Metadata is stripped before returning to callers.

**Multi-User Cache Sharing:**
- User A requests PC id=32 → cache miss → API call → cache stored
- User B requests PC id=32 → cache hit → serves same entry
- Same query = same cache key = shared cache entry
- No userId in cache key (intentional for efficiency)

### Configuration

**Admin Settings (Phase 6):**
Administrators can configure TTL per data type via appconfig:

| Config Key | Default | Description | Planned |
|------------|---------|-------------|----------|
| `cache_ttl_api_query` | 60s | General API query caching (CIs, searches, tickets) | ✅ Implemented |
| `cache_ttl_search` | 30s | Search results specifically | Phase 6 |
| `cache_ttl_profile` | 300s | User profile/permission data | Phase 6 |
| `cache_ttl_ci_preview` | 60s | CI preview rendering | Phase 6 |
| `cache_ttl_tickets` | 120s | Ticket list data | Phase 6 |

**Code Pattern:**
```php
// Get TTL from admin config with default fallback
$defaultTTL = 60;
$ttl = (int)$this->config->getAppValue('integration_itop', 'cache_ttl_api_query', $defaultTTL);

// Cache if TTL > 0 (allows disabling cache with TTL=0)
if ($ttl > 0) {
    $this->cache->set($cacheKey, json_encode($data), $ttl);
}
```

### Logging

Cache operations are logged with debug level for monitoring:

```
API query cache HIT: age=15s, ttl=60s, class=PC
API query cache MISS: cacheKey=api:c4ca4238...
API query CACHED: cacheKey=api:c4ca4238..., ttl=60s
```

## TTL Values Per Data Type

### Short TTL (30-60s) - Frequently Changing Data

**CI Previews: 60 seconds**
```php
private const CACHE_TTL_CI_PREVIEW = 60;
```

**Rationale:**
- CIs can be updated frequently
- Balance between freshness and performance
- Acceptable staleness for most use cases

**Search Results: 30 seconds**
```php
private const CACHE_TTL_SEARCH = 30;
```

**Rationale:**
- Users expect recent results in search
- Search queries vary widely (low cache hit rate)
- Short TTL reduces stale results

**Picker Suggestions: 60 seconds**
```php
private const CACHE_TTL_PICKER = 60;
```

**Rationale:**
- Similar to search but slightly longer
- Users may repeat similar queries while typing

### Medium TTL (120-300s) - Moderately Stable Data

**Ticket Lists: 120 seconds**
```php
private const CACHE_TTL_TICKET_LIST = 120;
```

**Rationale:**
- Dashboard widgets tolerate slight staleness
- Reduce API load for frequently viewed dashboards

**Profile Data: 300 seconds (5 minutes)**
```php
private const CACHE_TTL_PROFILE = 300;
```

**Rationale:**
- User profiles rarely change
- Portal/power user status is relatively stable
- 5 minutes balances freshness with performance

### Long TTL (600s+) - Stable Data

**User Info: 600 seconds (10 minutes)**
```php
private const CACHE_TTL_USER_INFO = 600;
```

**Rationale:**
- Person name, email, org rarely change
- Used for display only (not auth)

**Application Config: No expiry (manual invalidation)**
```php
// Stored in appconfig table, not cache
$url = $this->config->getAppValue('integration_itop', 'admin_instance_url');
```

## Cache Key Patterns

### Pattern Structure

```
{app_id}/{user_scope}/{data_type}_{identifier}
```

**Examples:**
```
integration_itop/user_boris/search_5f4dcc3b5aa765d61d8327deb882cf99
integration_itop/user_boris/preview_PC_5
integration_itop/user_boris/profile
integration_itop/global/ci_classes
```

### User Isolation

**Critical:** Always include user ID in cache keys for user-specific data

```php
// WRONG - shared across users!
$cacheKey = 'search_' . md5($query);

// CORRECT - isolated per user
$cacheKey = 'search_' . $userId . '_' . md5($query);
```

**Rationale:**
- Prevents data leakage between users
- Respects portal user permissions
- Essential for security

### Hash Generation for Complex Keys

```php
private function generateCacheKey(string $userId, string $type, array $params): string {
    $hash = md5(json_encode($params));
    return "integration_itop/user_{$userId}/{$type}_{$hash}";
}

// Example
$key = $this->generateCacheKey('boris', 'search', [
    'query' => 'laptop',
    'classes' => ['PC', 'Phone'],
    'limit' => 20
]);
// Result: integration_itop/user_boris/search_a3f8b1c2d4e5f6...
```

## Invalidation Strategies

### Time-Based (TTL Expiry)

**Default Strategy:** Let cache expire naturally

**Implementation:** Automatic by cache backend

**Pros:**
- Simple, no code needed
- Predictable behavior

**Cons:**
- May serve stale data
- Cannot force refresh

### Manual Invalidation

**Trigger Events:**
1. User updates personal settings
2. Admin updates application token
3. User explicitly refreshes

**Implementation:**
```php
public function invalidateUserCache(string $userId): void {
    $prefix = "integration_itop/user_{$userId}/";
    $this->cacheService->clear($prefix);
    $this->logger->info('Invalidated user cache', ['user' => $userId]);
}

// Called from settings controller
public function setConfig(array $values): DataResponse {
    // Save config...

    // Invalidate cache
    $this->cacheService->invalidateUserCache($this->userId);

    return new DataResponse(['success' => true]);
}
```

### Selective Invalidation

**Use Case:** Invalidate only specific cache entries

```php
public function invalidateCIPreview(string $userId, string $class, int $id): void {
    $key = "integration_itop/user_{$userId}/preview_{$class}_{$id}";
    $this->cacheService->delete($key);
}
```

### Cache Warming (Phase 2)

**Strategy:** Pre-populate cache for likely requests

```php
public function warmDashboardCache(string $userId): void {
    // Pre-fetch ticket counts
    $this->getTicketCounts($userId);

    // Pre-fetch recent tickets
    $this->getRecentTickets($userId, 5);

    $this->logger->info('Dashboard cache warmed', ['user' => $userId]);
}

// Call after user login
```

## Rate Limiting

### Global Rate Limit: 5 req/sec/user

**Implementation:**
```php
class RateLimiter {
    private const MAX_REQUESTS_PER_SECOND = 5;
    private const WINDOW_SECONDS = 1;

    public function checkLimit(string $userId): bool {
        $key = 'rate_limit_' . $userId;
        $count = (int) $this->cache->get($key);

        if ($count >= self::MAX_REQUESTS_PER_SECOND) {
            return false; // Rate limit exceeded
        }

        $this->cache->set($key, $count + 1, self::WINDOW_SECONDS);
        return true;
    }
}

// In controller
if (!$this->rateLimiter->checkLimit($userId)) {
    return new DataResponse(
        ['error' => 'Rate limit exceeded'],
        Http::STATUS_TOO_MANY_REQUESTS
    );
}
```

### Sliding Window Algorithm

**More Accurate:** Prevents burst at window boundaries

```php
class SlidingWindowRateLimiter {
    private const MAX_REQUESTS = 5;
    private const WINDOW_MS = 1000;

    public function checkLimit(string $userId): bool {
        $key = 'rate_limit_sliding_' . $userId;
        $now = microtime(true) * 1000; // milliseconds

        // Get timestamps of recent requests
        $timestamps = json_decode($this->cache->get($key) ?? '[]', true);

        // Remove old timestamps (outside window)
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $now - self::WINDOW_MS);

        if (count($timestamps) >= self::MAX_REQUESTS) {
            return false;
        }

        // Add current timestamp
        $timestamps[] = $now;
        $this->cache->set($key, json_encode($timestamps), 2); // 2 seconds TTL

        return true;
    }
}
```

### Per-Feature Rate Limits

| Feature | Limit | Window |
|---------|-------|--------|
| Unified Search | 10 req/min | 60s |
| Smart Picker | 20 req/min | 60s |
| Rich Preview | 30 req/min | 60s |
| Dashboard Widget | 5 req/min | 60s |

**Implementation:**
```php
private function getRateLimitForFeature(string $feature): array {
    return match($feature) {
        'search' => ['limit' => 10, 'window' => 60],
        'picker' => ['limit' => 20, 'window' => 60],
        'preview' => ['limit' => 30, 'window' => 60],
        'dashboard' => ['limit' => 5, 'window' => 60],
        default => ['limit' => 5, 'window' => 1]
    };
}
```

## Cache Topology and Service Relationships

**Overview:** The application uses multiple caching layers across different services. Understanding how they interact is critical for debugging and optimization.

### Cache Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                      Nextcloud App                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Layer 1: UI Components (Vue)                             │  │
│  │ ├─ ReferenceItopWidget (CI preview display)             │  │
│  │ ├─ ItopSearchProvider results                            │  │
│  │ └─ ItopSmartPickerProvider suggestions                   │  │
│  └──────────────────────────────────────────────────────────┘  │
│                            ↓                                    │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Layer 2: Services (PHP)                                  │  │
│  │ ├─ ItopReferenceProvider                                 │  │
│  │ │  └─ Uses CacheService for CI preview caching          │  │
│  │ ├─ ItopSearchProvider                                    │  │
│  │ │  └─ Calls ItopAPIService.searchCIs()                  │  │
│  │ └─ ProfileService                                        │  │
│  │    └─ Determines user permissions (portal vs power)     │  │
│  └──────────────────────────────────────────────────────────┘  │
│                            ↓                                    │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Layer 3: API Service (ItopAPIService)                   │  │
│  │ ├─ buildQueryCacheKey() → md5(key)                      │  │
│  │ ├─ Cache check: cache.get(api:hash) → HIT/MISS         │  │
│  │ ├─ If MISS: Call iTop REST API                          │  │
│  │ ├─ Add metadata: _cache_timestamp, _cache_ttl           │  │
│  │ ├─ Cache store: cache.set(api:hash, $data, $ttl)       │  │
│  │ └─ Methods:                                              │  │
│  │    - getCIPreview()  → Single CI by ID                  │  │
│  │    - searchCIs()     → OQL search query                  │  │
│  │    - getTicketInfo() → Single ticket by ID              │  │
│  │    - search()        → Broad search (tickets + CIs)    │  │
│  └──────────────────────────────────────────────────────────┘  │
│                            ↓                                    │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Layer 4: Cache Backend (Nextcloud ICacheFactory)         │  │
│  │ ├─ Redis (recommended for multi-server)                  │  │
│  │ ├─ Memcached (alternative)                               │  │
│  │ ├─ APCu (single-server)                                  │  │
│  │ └─ File cache (fallback)                                 │  │
│  └──────────────────────────────────────────────────────────┘  │
│                            ↓                                    │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Layer 5: iTop API (External)                             │  │
│  │ ├─ REST endpoint: /webservices/rest.php                  │  │
│  │ ├─ Authentication: App token (admin-configured)          │  │
│  │ └─ Queries: SELECT OQL or core/get operations           │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Cache Decision Flow

```
Request from UI Component
        ↓
    [Check Scope]
        ├─ CI Preview? → Use CacheService (user-isolated)
        ├─ Search/Pick? → Use ItopAPIService (query-based)
        └─ Other? → Direct API call
        ↓
    [Build Cache Key]
        ├─ CacheService: ci_preview:userId:class:id
        └─ ItopAPIService: api:md5(oql_query)
        ↓
    [Check Cache]
        ├─ HIT: Validate TTL & timestamp → Return
        └─ MISS: Call iTop API
        ↓
    [Process Response]
        ├─ Success: Add metadata + cache
        └─ Error: Return without caching
        ↓
    [Strip Metadata]
        └─ Remove _cache_timestamp, _cache_ttl before returning
        ↓
    Response to UI
```

### Service Cache Responsibility Matrix

| Service | Cache Type | Key Pattern | TTL Config | Sharing |
|---------|-----------|-------------|------------|----------|
| **ItopAPIService** | Query-based | `api:{md5(key)}` | `cache_ttl_api_query` | Cross-user |
| **ItopReferenceProvider** | CI preview | `ci_preview:{userId}:{class}:{id}` | Uses API TTL | Per-user |
| **ItopSearchProvider** | Search results | Via ItopAPIService | `cache_ttl_api_query` | Cross-user |
| **ItopSmartPickerProvider** | Picker results | Via ItopAPIService | `cache_ttl_api_query` | Cross-user |
| **ProfileService** | Profile data | `profile:{userId}` | `cache_ttl_profile` (Phase 6) | Per-user |

### Data Flow Examples

**Example 1: Getting CI Preview (PC id=32)**
```
ReferenceItopWidget (Vue component)
  └─ getCIReference() in ItopReferenceProvider
     ├─ CacheService.getCIPreview(userId, 'PC', 32)
     │  └─ Key: ci_preview:boris:PC:32
     │     ├─ HIT: Return cached preview
     │     └─ MISS: Continue to API
     └─ ItopAPIService.getCIPreview(userId, 'PC', 32)
        ├─ buildQueryCacheKey() → api:c4ca4238...
        ├─ cache.get() → MISS
        ├─ request(userId, {operation, class, key='32', output_fields='*'})
        │  ├─ POST /webservices/rest.php?version=1.3
        │  └─ Auth-Token: [app_token]
        └─ Store in cache with _cache_timestamp + _cache_ttl
```

**Example 2: Searching Software (term="Office")**
```
ItopSearchProvider.search("Office")
  └─ ItopAPIService.searchCIs(userId, "Office", [], isPortalOnly)
     ├─ buildQueryCacheKey() → api:a1b2c3d4...
     ├─ cache.get() → MISS
     └─ request(userId, {operation: 'core/get', class: 'Software', key: "SELECT Software ... LIKE '%Office%'", ...})
        ├─ Multiple API calls per class (if needed)
        └─ Cache entire result set once
```

**Example 3: Multiple Users, Same Query**
```
User A: getCIPreview(PC, 32)
  └─ buildQueryCacheKey() → api:c4ca4238... → MISS → API call → CACHED

User B: getCIPreview(PC, 32)
  └─ buildQueryCacheKey() → api:c4ca4238... → HIT! → No API call
  └─ Result served from cache (same entry as User A)
```

## Performance Targets

### Response Time Targets

| Operation | Target | Cached | Uncached |
|-----------|--------|--------|----------|
| Search | <500ms | <100ms | <2s |
| Preview | <300ms | <50ms | <1s |
| Picker | <400ms | <80ms | <1.5s |
| Dashboard | <600ms | <150ms | <2s |
| Settings Load | <200ms | N/A | <500ms |

### Measurement

```php
class PerformanceMonitor {
    public function measure(string $operation, callable $callback): mixed {
        $start = microtime(true);

        try {
            $result = $callback();
            $duration = (microtime(true) - $start) * 1000; // milliseconds

            $this->logger->info('Performance', [
                'operation' => $operation,
                'duration_ms' => round($duration, 2),
                'cached' => $this->wasCacheHit()
            ]);

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            $this->logger->error('Performance (failed)', [
                'operation' => $operation,
                'duration_ms' => round($duration, 2),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

// Usage
$results = $this->perfMonitor->measure('search', function() {
    return $this->itopAPIService->search($userId, $query);
});
```

### Cache Hit Rate Targets

| Data Type | Target Hit Rate |
|-----------|----------------|
| Previews | >80% |
| Search | >60% |
| Picker | >70% |
| Profiles | >90% |

**Monitoring:**
```php
class CacheMetrics {
    public function recordHit(string $type): void {
        $key = 'cache_metrics_' . $type . '_hits';
        $this->cache->inc($key);
    }

    public function recordMiss(string $type): void {
        $key = 'cache_metrics_' . $type . '_misses';
        $this->cache->inc($key);
    }

    public function getHitRate(string $type): float {
        $hits = (int) $this->cache->get('cache_metrics_' . $type . '_hits');
        $misses = (int) $this->cache->get('cache_metrics_' . $type . '_misses');

        if ($hits + $misses === 0) {
            return 0.0;
        }

        return $hits / ($hits + $misses);
    }
}
```

## Optimization Techniques

### 1. Lazy Loading

**Defer non-critical API calls:**

```vue
<template>
  <div class="dashboard-widget">
    <div v-if="!loaded" class="skeleton">Loading...</div>
    <div v-else>
      <!-- Widget content -->
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      loaded: false,
      tickets: []
    }
  },

  async mounted() {
    // Delay loading until component is visible
    if (this.isVisible()) {
      await this.loadTickets()
    } else {
      this.observeVisibility()
    }
  },

  methods: {
    observeVisibility() {
      const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting) {
          this.loadTickets()
          observer.disconnect()
        }
      })
      observer.observe(this.$el)
    }
  }
}
</script>
```

### 2. Debouncing and Throttling

**Search Input Debouncing:**
```javascript
import { debounce } from '@nextcloud/vue'

export default {
  methods: {
    onSearchInput: debounce(async function(query) {
      const results = await this.search(query)
      this.displayResults(results)
    }, 300) // Wait 300ms after user stops typing
  }
}
```

**Scroll Event Throttling:**
```javascript
import { throttle } from 'lodash'

export default {
  mounted() {
    window.addEventListener('scroll', throttle(this.onScroll, 100))
  },

  methods: {
    onScroll() {
      // Handle scroll (max once per 100ms)
    }
  }
}
```

### 3. Request Batching

**Batch Multiple CI Requests:**
```php
public function getMultipleCIPreviews(string $userId, array $items): array {
    // items: [['class' => 'PC', 'id' => 5], ['class' => 'Phone', 'id' => 10], ...]

    // Group by class
    $grouped = [];
    foreach ($items as $item) {
        $grouped[$item['class']][] = $item['id'];
    }

    // Single query per class
    $results = [];
    foreach ($grouped as $class => $ids) {
        $query = "SELECT $class WHERE id IN (" . implode(',', $ids) . ")";
        $classResults = $this->itopAPIService->query($query);
        $results = array_merge($results, $classResults);
    }

    return $results;
}
```

### 4. Partial Field Loading

**Minimize Output Fields:**
```php
// List view - lightweight
$listFields = 'id,name,status,last_update';

// Preview view - comprehensive
$previewFields = 'id,name,status,org_id_friendlyname,brand_id_friendlyname,model_id_friendlyname,cpu,ram,description';

// Only request what's needed
$query = [
    'operation' => 'core/get',
    'class' => 'PC',
    'key' => 'SELECT PC LIMIT 10',
    'output_fields' => $context === 'list' ? $listFields : $previewFields
];
```

### 5. Compression

**Enable Gzip Compression:**
```php
// In API requests to iTop
$options = [
    'headers' => [
        'Accept-Encoding' => 'gzip, deflate'
    ]
];
```

## Monitoring and Debugging

### Debug Mode

**Enable Verbose Logging:**
```php
// In admin settings
$debugMode = $this->config->getAppValue('integration_itop', 'debug_mode', '0') === '1';

if ($debugMode) {
    $this->logger->debug('Cache check', [
        'key' => $cacheKey,
        'hit' => $cached !== null,
        'ttl_remaining' => $this->cache->ttl($cacheKey)
    ]);
}
```

### Performance Dashboard (Future)

**Admin Page Showing:**
- Cache hit rates per type
- Average response times
- Rate limit violations
- Top slowest queries

## References

- **Architecture:** [architecture.md](architecture.md)
- **API Integration:** [itop-api.md](itop-api.md)
- **Observability:** [observability.md](observability.md)
