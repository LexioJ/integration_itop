# Observability - Logging, Metrics, and Debugging

## Overview

This document defines logging strategies, metrics collection, debug modes, and health check endpoints for the iTop Integration app to ensure effective monitoring, troubleshooting, and performance analysis.

## Log Levels and Categories

### Log Level Definitions

| Level | Purpose | When to Use | Example |
|-------|---------|-------------|---------|
| `debug` | Detailed diagnostics | Development, troubleshooting | Cache hits/misses, query details |
| `info` | Normal operations | Important events | User configured, search executed |
| `warning` | Unexpected but handled | Degraded state | Cache failure (fallback to API) |
| `error` | Operation failed | Requires attention | API error, token invalid |
| `critical` | System failure | Immediate action needed | Cannot decrypt token, database down |

### Log Categories

**Authentication & Security:**
```php
$this->logger->info('User configured integration', [
    'app' => 'integration_itop',
    'category' => 'auth',
    'user' => $userId,
    'person_id' => $personId
]);

$this->logger->warning('Invalid token attempt', [
    'app' => 'integration_itop',
    'category' => 'auth',
    'user' => $userId,
    'error' => 'Token validation failed'
]);
```

**API Operations:**
```php
$this->logger->debug('iTop API request', [
    'app' => 'integration_itop',
    'category' => 'api',
    'operation' => 'core/get',
    'class' => 'PC',
    'user' => $userId
]);

$this->logger->error('iTop API error', [
    'app' => 'integration_itop',
    'category' => 'api',
    'error_code' => $result['code'],
    'error_message' => $result['message'],
    'user' => $userId
]);
```

**Cache Operations:**
```php
$this->logger->debug('Cache operation', [
    'app' => 'integration_itop',
    'category' => 'cache',
    'operation' => 'get',
    'key' => $cacheKey,
    'hit' => $value !== null
]);
```

**Performance:**
```php
$this->logger->info('Performance metric', [
    'app' => 'integration_itop',
    'category' => 'performance',
    'operation' => 'search',
    'duration_ms' => round($duration, 2),
    'cached' => $cached,
    'result_count' => count($results)
]);
```

## What to Log (Without Sensitive Data)

### DO Log

**User Actions:**
```php
// User configuration events
$this->logger->info('User enabled integration', [
    'user' => $userId,
    'timestamp' => date('c')
]);

// Search queries (without query text for privacy)
$this->logger->info('Search executed', [
    'user' => $userId,
    'result_count' => count($results),
    'duration_ms' => $duration,
    'cached' => $cached
]);
```

**System Events:**
```php
// Admin configuration changes
$this->logger->info('Admin config updated', [
    'admin_user' => $adminUserId,
    'changed_fields' => array_keys($changedValues)
]);

// Rate limit violations
$this->logger->warning('Rate limit exceeded', [
    'user' => $userId,
    'feature' => 'search',
    'limit' => 10,
    'window' => 60
]);
```

**Errors (Sanitized):**
```php
// API errors (without sensitive details)
$this->logger->error('iTop API request failed', [
    'error_type' => 'network',
    'error_code' => $statusCode,
    'user' => $userId,
    'retried' => $retryCount
]);

// Cache failures (with fallback)
$this->logger->warning('Cache unavailable, using direct API', [
    'cache_backend' => 'redis',
    'error' => 'Connection refused'
]);
```

### DO NOT Log

**Sensitive Data:**
```php
// ❌ NEVER log tokens
$this->logger->debug('Token: ' . $token); // FORBIDDEN

// ❌ NEVER log passwords
$this->logger->debug('Password: ' . $password); // FORBIDDEN

// ✅ Log token validation result only
$this->logger->info('Token validation', [
    'success' => $valid,
    'user' => $userId
]);
```

**Personal Information:**
```php
// ❌ Avoid logging full names, emails in plain text
$this->logger->debug('User: ' . $firstName . ' ' . $lastName); // AVOID

// ✅ Log user IDs instead
$this->logger->debug('User configured', ['user' => $userId]);
```

**Query Content (Privacy):**
```php
// ❌ Avoid logging search queries (may contain PII)
$this->logger->debug('Search query: ' . $query); // AVOID

// ✅ Log search execution metadata
$this->logger->info('Search executed', [
    'query_length' => strlen($query),
    'result_count' => count($results)
]);
```

**CI Content:**
```php
// ❌ Avoid logging full CI descriptions
$this->logger->debug('Description: ' . $description); // AVOID

// ✅ Log CI ID and class only
$this->logger->debug('CI fetched', [
    'class' => 'PC',
    'id' => $id
]);
```

## Debug Mode Switches

### Admin Debug Mode

**Enable in Admin Settings:**
```php
// Admin settings controller
public function setDebugMode(bool $enabled): DataResponse {
    $this->config->setAppValue(
        'integration_itop',
        'debug_mode',
        $enabled ? '1' : '0'
    );

    $this->logger->info('Debug mode ' . ($enabled ? 'enabled' : 'disabled'), [
        'admin_user' => $this->userId
    ]);

    return new DataResponse(['success' => true]);
}
```

**Check Debug Mode:**
```php
private function isDebugMode(): bool {
    return $this->config->getAppValue('integration_itop', 'debug_mode', '0') === '1';
}

// Usage
if ($this->isDebugMode()) {
    $this->logger->debug('Detailed cache info', [
        'key' => $cacheKey,
        'ttl_remaining' => $this->cache->ttl($cacheKey),
        'size_bytes' => strlen(json_encode($value))
    ]);
}
```

### User Debug Mode (Personal Settings)

**Enable for Individual Users:**
```php
public function setUserDebugMode(bool $enabled): DataResponse {
    $this->config->setUserValue(
        $this->userId,
        'integration_itop',
        'debug_mode',
        $enabled ? '1' : '0'
    );

    return new DataResponse(['success' => true]);
}
```

**Combined Check:**
```php
private function shouldLogDebug(string $userId): bool {
    // Global debug mode OR user-specific debug mode
    $globalDebug = $this->config->getAppValue('integration_itop', 'debug_mode', '0') === '1';
    $userDebug = $this->config->getUserValue($userId, 'integration_itop', 'debug_mode', '0') === '1';

    return $globalDebug || $userDebug;
}
```

### Request Logging

**Log Full Request/Response in Debug Mode:**
```php
public function request(string $userId, array $params): array {
    if ($this->shouldLogDebug($userId)) {
        $this->logger->debug('iTop API request', [
            'user' => $userId,
            'operation' => $params['operation'] ?? 'unknown',
            'class' => $params['class'] ?? 'unknown',
            'params' => json_encode($params)
        ]);
    }

    $result = $this->executeRequest($params);

    if ($this->shouldLogDebug($userId)) {
        $this->logger->debug('iTop API response', [
            'user' => $userId,
            'code' => $result['code'] ?? 'unknown',
            'object_count' => isset($result['objects']) ? count($result['objects']) : 0,
            'response_size' => strlen(json_encode($result))
        ]);
    }

    return $result;
}
```

## Metrics to Track

### Response Time Metrics

**Histogram of Response Times:**
```php
class MetricsCollector {
    private array $metrics = [];

    public function recordResponseTime(string $operation, float $durationMs, bool $cached): void {
        $bucket = $this->getBucket($durationMs);

        $key = "metrics_response_time_{$operation}_{$bucket}_" . ($cached ? 'cached' : 'uncached');
        $this->cache->inc($key);
    }

    private function getBucket(float $ms): string {
        if ($ms < 100) return '0-100ms';
        if ($ms < 500) return '100-500ms';
        if ($ms < 1000) return '500-1000ms';
        if ($ms < 2000) return '1000-2000ms';
        return '2000ms+';
    }

    public function getResponseTimeDistribution(string $operation): array {
        $buckets = ['0-100ms', '100-500ms', '500-1000ms', '1000-2000ms', '2000ms+'];
        $distribution = ['cached' => [], 'uncached' => []];

        foreach ($buckets as $bucket) {
            $cachedKey = "metrics_response_time_{$operation}_{$bucket}_cached";
            $uncachedKey = "metrics_response_time_{$operation}_{$bucket}_uncached";

            $distribution['cached'][$bucket] = (int) $this->cache->get($cachedKey);
            $distribution['uncached'][$bucket] = (int) $this->cache->get($uncachedKey);
        }

        return $distribution;
    }
}

// Usage
$start = microtime(true);
$results = $this->search($userId, $query);
$duration = (microtime(true) - $start) * 1000;

$this->metricsCollector->recordResponseTime('search', $duration, $cached);
```

### Error Rate Metrics

**Track Errors by Type:**
```php
public function recordError(string $errorType): void {
    $key = 'metrics_errors_' . $errorType;
    $this->cache->inc($key);
}

// Usage
try {
    $result = $this->itopAPIService->request($params);
} catch (NetworkException $e) {
    $this->metricsCollector->recordError('network');
    throw $e;
} catch (AuthException $e) {
    $this->metricsCollector->recordError('auth');
    throw $e;
}
```

**Error Rate Calculation:**
```php
public function getErrorRate(string $errorType, int $windowSeconds = 3600): float {
    $errorKey = 'metrics_errors_' . $errorType;
    $successKey = 'metrics_success_' . $errorType;

    $errors = (int) $this->cache->get($errorKey);
    $successes = (int) $this->cache->get($successKey);

    if ($errors + $successes === 0) {
        return 0.0;
    }

    return $errors / ($errors + $successes);
}
```

### Cache Hit Rates

**Track Cache Performance:**
```php
public function recordCacheHit(string $cacheType): void {
    $key = 'metrics_cache_hits_' . $cacheType;
    $this->cache->inc($key);
}

public function recordCacheMiss(string $cacheType): void {
    $key = 'metrics_cache_misses_' . $cacheType;
    $this->cache->inc($key);
}

public function getCacheHitRate(string $cacheType): float {
    $hits = (int) $this->cache->get('metrics_cache_hits_' . $cacheType);
    $misses = (int) $this->cache->get('metrics_cache_misses_' . $cacheType);

    if ($hits + $misses === 0) {
        return 0.0;
    }

    return $hits / ($hits + $misses);
}
```

### User Activity Metrics

**Track Active Users:**
```php
public function recordUserActivity(string $userId, string $activity): void {
    $key = 'metrics_active_users_' . date('Y-m-d');
    $this->cache->set($key . '_' . $userId, 1, 86400); // 24h TTL

    $activityKey = 'metrics_activity_' . $activity;
    $this->cache->inc($activityKey);
}

public function getActiveUsersToday(): int {
    $key = 'metrics_active_users_' . date('Y-m-d');
    $pattern = $key . '_*';

    $count = 0;
    foreach ($this->cache->keys($pattern) as $userKey) {
        if ($this->cache->get($userKey)) {
            $count++;
        }
    }

    return $count;
}
```

### API Call Metrics

**Track API Usage:**
```php
public function recordAPICall(string $operation, string $class): void {
    $operationKey = 'metrics_api_' . $operation;
    $classKey = 'metrics_api_class_' . $class;

    $this->cache->inc($operationKey);
    $this->cache->inc($classKey);
}

public function getAPICallStats(): array {
    return [
        'total_calls' => $this->getTotalAPICalls(),
        'by_operation' => $this->getCallsByOperation(),
        'by_class' => $this->getCallsByClass()
    ];
}
```

## Health Check Endpoints

### Basic Health Check

**Endpoint:** `GET /apps/integration_itop/health`

**Purpose:** Verify app is responsive

**Response:**
```json
{
  "status": "ok",
  "timestamp": "2025-10-18T14:30:00Z",
  "version": "1.0.0"
}
```

**Implementation:**
```php
/**
 * @PublicPage
 * @NoCSRFRequired
 */
public function health(): DataResponse {
    return new DataResponse([
        'status' => 'ok',
        'timestamp' => date('c'),
        'version' => $this->appManager->getAppVersion('integration_itop')
    ]);
}
```

### Detailed Health Check

**Endpoint:** `GET /apps/integration_itop/health/detailed`

**Purpose:** Check all system components

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2025-10-18T14:30:00Z",
  "components": {
    "cache": {
      "status": "ok",
      "backend": "redis",
      "response_time_ms": 1.2
    },
    "itop_api": {
      "status": "ok",
      "url": "http://itop.example.com",
      "response_time_ms": 245.8,
      "api_version": "1.3"
    },
    "database": {
      "status": "ok",
      "response_time_ms": 3.4
    }
  },
  "metrics": {
    "active_users_today": 42,
    "cache_hit_rate": 0.82,
    "avg_response_time_ms": 187.5
  }
}
```

**Implementation:**
```php
/**
 * @NoAdminRequired
 * @AuthorizedAdminSettingClass(settings=Admin::class)
 */
public function healthDetailed(): DataResponse {
    $components = [];

    // Check cache
    $cacheStart = microtime(true);
    try {
        $this->cache->get('health_check');
        $components['cache'] = [
            'status' => 'ok',
            'backend' => $this->getCacheBackend(),
            'response_time_ms' => round((microtime(true) - $cacheStart) * 1000, 2)
        ];
    } catch (\Exception $e) {
        $components['cache'] = [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }

    // Check iTop API
    $itopStart = microtime(true);
    try {
        $result = $this->itopAPIService->testConnection();
        $components['itop_api'] = [
            'status' => 'ok',
            'url' => $this->config->getAppValue('integration_itop', 'admin_instance_url'),
            'response_time_ms' => round((microtime(true) - $itopStart) * 1000, 2),
            'api_version' => $result['version'] ?? 'unknown'
        ];
    } catch (\Exception $e) {
        $components['itop_api'] = [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }

    // Overall status
    $status = 'healthy';
    foreach ($components as $component) {
        if ($component['status'] === 'error') {
            $status = 'unhealthy';
            break;
        }
    }

    return new DataResponse([
        'status' => $status,
        'timestamp' => date('c'),
        'components' => $components,
        'metrics' => $this->getHealthMetrics()
    ]);
}
```

### Readiness Check (Kubernetes/Docker)

**Endpoint:** `GET /apps/integration_itop/health/ready`

**Purpose:** Signal app is ready to serve traffic

**Response:**
```json
{
  "ready": true
}
```

**Implementation:**
```php
/**
 * @PublicPage
 * @NoCSRFRequired
 */
public function ready(): DataResponse {
    // Check critical dependencies
    $adminUrl = $this->config->getAppValue('integration_itop', 'admin_instance_url');
    $appToken = $this->config->getAppValue('integration_itop', 'application_token');

    $ready = !empty($adminUrl) && !empty($appToken);

    return new DataResponse(
        ['ready' => $ready],
        $ready ? Http::STATUS_OK : Http::STATUS_SERVICE_UNAVAILABLE
    );
}
```

### Liveness Check (Kubernetes)

**Endpoint:** `GET /apps/integration_itop/health/live`

**Purpose:** Signal app is alive (restart if not)

**Response:**
```json
{
  "alive": true
}
```

**Implementation:**
```php
/**
 * @PublicPage
 * @NoCSRFRequired
 */
public function live(): DataResponse {
    // Simple check - can we execute PHP?
    return new DataResponse(['alive' => true]);
}
```

## Logging Best Practices

### Structured Logging

**Always use array context:**
```php
// ✅ Good - structured
$this->logger->info('User search', [
    'user' => $userId,
    'result_count' => count($results),
    'duration_ms' => $duration
]);

// ❌ Bad - string concatenation
$this->logger->info('User ' . $userId . ' searched, found ' . count($results) . ' results');
```

### Error Context

**Include actionable information:**
```php
// ✅ Good - includes context for debugging
$this->logger->error('Failed to fetch CI', [
    'class' => $class,
    'id' => $id,
    'user' => $userId,
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString()
]);

// ❌ Bad - not enough context
$this->logger->error('Fetch failed');
```

### Log Rotation

**Nextcloud handles log rotation automatically**

**Monitor log file size:**
```bash
# Check Nextcloud log size
ls -lh /var/www/nextcloud/data/nextcloud.log
```

**Recommended:** Set up logrotate for large installations

## Debugging Tools

### Log Viewer (Admin)

**View Recent Logs:**
```bash
# Last 100 lines of iTop integration logs
tail -n 100 /var/www/nextcloud/data/nextcloud.log | grep integration_itop
```

**Filter by User:**
```bash
grep '"user":"boris"' /var/www/nextcloud/data/nextcloud.log | tail -n 50
```

### Debug Panel (Future Enhancement)

**Admin UI Showing:**
- Recent errors (last 24h)
- Slow queries (>2s)
- Cache statistics
- Active users
- API call frequency

## References

- **Caching:** [caching-performance.md](caching-performance.md)
- **Architecture:** [architecture.md](architecture.md)
- **Edge Cases:** [edge-cases.md](edge-cases.md)
