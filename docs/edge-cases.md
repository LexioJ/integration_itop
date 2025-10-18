# Edge Cases and Error Handling

## Overview

This document catalogs edge cases, error scenarios, and their handling strategies for the iTop Integration app. Each scenario includes symptoms, causes, detection methods, handling approaches, and user experience implications.

## Network Errors

### Scenario 1: Network Timeout

**Symptoms:**
- API requests hang for >30 seconds
- User sees loading spinner indefinitely
- No error message displayed

**Causes:**
- iTop server unresponsive
- Network congestion
- Firewall blocking requests
- DNS resolution failure

**Detection:**
```php
try {
    $response = $this->client->post($url, $options + [
        'timeout' => 30, // 30 second timeout
        'connect_timeout' => 5 // 5 second connection timeout
    ]);
} catch (ConnectException $e) {
    // Connection timeout or refused
    return ['error' => 'Network timeout', 'error_type' => 'network'];
}
```

**Handling:**
```php
// Backend
if (isset($result['error']) && $result['error_type'] === 'network') {
    $this->logger->warning('Network timeout', [
        'url' => $url,
        'timeout_seconds' => 30
    ]);

    return new DataResponse([
        'error' => $this->l10n->t('Could not connect to iTop server. Please try again later.')
    ], Http::STATUS_GATEWAY_TIMEOUT);
}

// Frontend
if (error.response.status === 504) {
    showError(t('integration_itop', 'Connection to iTop timed out'))
}
```

**User Experience:**
- Graceful degradation: Show error message, don't freeze UI
- Retry button available
- Fallback to cached data if available

**Prevention:**
- Set reasonable timeouts (30s max)
- Implement exponential backoff for retries
- Use connection pooling to reduce latency

### Scenario 2: DNS Resolution Failure

**Symptoms:**
- Immediate connection failure
- Error: "Could not resolve host"

**Causes:**
- Invalid iTop URL
- DNS server down
- Network configuration issue

**Detection:**
```php
catch (RequestException $e) {
    if (strpos($e->getMessage(), 'Could not resolve host') !== false) {
        return ['error' => 'DNS resolution failed', 'error_type' => 'dns'];
    }
}
```

**Handling:**
```php
// Admin settings - validate URL on save
public function setConfig(array $values): DataResponse {
    if (isset($values['admin_instance_url'])) {
        $url = $values['admin_instance_url'];

        // Test DNS resolution
        $host = parse_url($url, PHP_URL_HOST);
        if (!gethostbyname($host)) {
            return new DataResponse([
                'error' => $this->l10n->t('Cannot resolve hostname: {host}', ['host' => $host])
            ], Http::STATUS_BAD_REQUEST);
        }
    }
}
```

**User Experience:**
- Immediate feedback in admin settings
- Helpful error message with hostname
- Suggest checking URL spelling

### Scenario 3: SSL Certificate Error

**Symptoms:**
- Error: "SSL certificate problem"
- HTTPS requests fail, HTTP works

**Causes:**
- Self-signed certificate
- Expired certificate
- Certificate hostname mismatch

**Detection:**
```php
catch (RequestException $e) {
    if (strpos($e->getMessage(), 'SSL certificate') !== false) {
        return ['error' => 'SSL certificate error', 'error_type' => 'ssl'];
    }
}
```

**Handling:**
```php
// Development mode: Allow self-signed certs (INSECURE - dev only)
if ($this->config->getSystemValue('debug', false)) {
    $options['verify'] = false;
    $this->logger->warning('SSL verification disabled (debug mode)');
}

// Production: Require valid certificates
else {
    $options['verify'] = true;

    try {
        $response = $this->client->post($url, $options);
    } catch (RequestException $e) {
        if (strpos($e->getMessage(), 'SSL') !== false) {
            return new DataResponse([
                'error' => $this->l10n->t('SSL certificate validation failed. Please check iTop server certificate.')
            ], Http::STATUS_BAD_REQUEST);
        }
    }
}
```

**User Experience:**
- Clear error message explaining SSL issue
- Link to documentation on certificate setup
- Admin can add certificate to Nextcloud's trust store

## Authentication Errors

### Scenario 4: Invalid Application Token

**Symptoms:**
- All API requests return 401 Unauthorized
- Admin test connection fails

**Causes:**
- Token typo during configuration
- Token revoked in iTop
- Token expired
- Wrong iTop instance URL

**Detection:**
```php
$result = $this->request($params);

if (isset($result['code']) && $result['code'] === 1) {
    if (strpos($result['message'], 'Invalid token') !== false) {
        return ['error' => 'Invalid token', 'error_type' => 'auth'];
    }
}
```

**Handling:**
```php
// Admin settings - test connection
public function testConnection(): DataResponse {
    $result = $this->itopAPIService->listOperations();

    if (isset($result['error']) && $result['error_type'] === 'auth') {
        return new DataResponse([
            'success' => false,
            'message' => $this->l10n->t('Application token is invalid or expired. Please check your iTop configuration.')
        ], Http::STATUS_UNAUTHORIZED);
    }
}

// Log for admin investigation
$this->logger->error('Invalid application token', [
    'admin_user' => $this->userId,
    'itop_url' => $url
]);
```

**User Experience:**
- Clear error during test connection
- Prompt to regenerate token in iTop
- Step-by-step guide in error message

**Prevention:**
- Validate token immediately on save
- Show token format hint in UI
- Provide "Test Connection" button

### Scenario 5: Portal User Personal Token Blocked

**Symptoms:**
- Personal token validation returns "Portal user is not allowed"
- User can't complete setup

**Causes:**
- iTop hardcoded restriction (see [security-auth.md](security-auth.md))
- Portal users blocked from REST API

**Detection:**
```php
if (isset($result['code']) && $result['code'] === 1) {
    if (strpos($result['message'], 'Portal user is not allowed') !== false) {
        return ['error' => 'Portal user blocked', 'error_type' => 'portal_restriction'];
    }
}
```

**Handling:**
```php
// Dual-token flow - use app token instead
if ($result['error_type'] === 'portal_restriction') {
    $this->logger->info('Portal user detected, using app token flow', [
        'user' => $userId
    ]);

    // Validate using app token + :current_contact_id
    $appTokenResult = $this->validateWithAppToken($userId);

    if ($appTokenResult['success']) {
        return new DataResponse([
            'success' => true,
            'message' => $this->l10n->t('Portal user configured successfully (using application token)'),
            'person_id' => $appTokenResult['person_id']
        ]);
    }
}
```

**User Experience:**
- Inform user this is expected for Portal users
- Explain dual-token architecture
- Configuration succeeds transparently

**Prevention:**
- Detect Portal users automatically
- Skip personal token step if possible
- Clear documentation in settings UI

### Scenario 6: Missing Person ID After Token Validation

**Symptoms:**
- Token validates successfully
- But person_id not extracted
- User info incomplete

**Causes:**
- User not linked to Person in iTop
- `:current_contact_id` placeholder not working
- Empty Person record

**Detection:**
```php
$result = $this->request([
    'operation' => 'core/get',
    'class' => 'Person',
    'key' => 'SELECT Person WHERE id = :current_contact_id'
]);

if (empty($result['objects'])) {
    return ['error' => 'Person not found', 'error_type' => 'no_person'];
}
```

**Handling:**
```php
if ($result['error_type'] === 'no_person') {
    return new DataResponse([
        'error' => $this->l10n->t('Your user account is not linked to a Person in iTop. Please contact your administrator.')
    ], Http::STATUS_PRECONDITION_FAILED);
}

// Log for admin troubleshooting
$this->logger->error('User not linked to Person', [
    'user' => $userId,
    'hint' => 'Create Person record in iTop and link to User.contactid'
]);
```

**User Experience:**
- Actionable error message
- Link to admin contact
- Documentation on Person/User linking

**Prevention:**
- Admin pre-creates Person records
- Sync tool to auto-create Persons from LDAP/AD
- Validation script to check all users have Persons

## Empty Result Scenarios

### Scenario 7: No Search Results

**Symptoms:**
- Search returns empty array
- No error, just no results

**Causes:**
- No CIs matching search term
- Portal user has no assigned CIs
- All matching CIs outside user's permissions

**Detection:**
```php
$result = $this->search($userId, $query);

if (empty($result) || (isset($result['objects']) && empty($result['objects']))) {
    return ['results' => [], 'count' => 0];
}
```

**Handling:**
```php
// Frontend
if (results.length === 0) {
    return SearchResult::complete($this->getName(), [])
}

// No error thrown - empty results are valid
```

**User Experience:**
- Search section doesn't appear (Nextcloud behavior)
- No error message (not an error state)
- User can try different search term

**When to Show Hint:**
```javascript
// Only show hint if user typed >2 chars and got 0 results
if (query.length > 2 && results.length === 0 && !isPortalUser) {
    showInfo(t('integration_itop', 'No results found. Try a different search term.'))
}

// Portal users: different message
if (query.length > 2 && results.length === 0 && isPortalUser) {
    showInfo(t('integration_itop', 'No CIs assigned to you match this search.'))
}
```

### Scenario 8: CI Not Found (404)

**Symptoms:**
- Rich preview for valid URL shows nothing
- User pasted link to deleted CI

**Causes:**
- CI deleted in iTop
- CI ID doesn't exist
- User lacks permission to view

**Detection:**
```php
$result = $this->itopAPIService->getCIPreview($userId, $class, $id);

if (empty($result['objects'])) {
    return ['error' => 'CI not found', 'error_type' => 'not_found'];
}
```

**Handling:**
```php
// ReferenceProvider
if ($result['error_type'] === 'not_found') {
    $this->logger->debug('CI not found', [
        'class' => $class,
        'id' => $id,
        'user' => $userId
    ]);

    return null; // No preview - render as plain URL
}
```

**User Experience:**
- No preview widget rendered
- URL stays as clickable link
- Silent degradation (no error popup)

**Security Note:**
- Don't reveal whether CI exists or just lacks permission
- Same response for both cases to prevent information disclosure

### Scenario 9: Partial Data in API Response

**Symptoms:**
- Some fields missing in response
- Preview renders with gaps

**Causes:**
- iTop field not populated
- Field doesn't exist in older iTop versions
- Field removed from datamodel

**Detection:**
```php
$fields = $result['objects'][0]['fields'] ?? [];

// Check for required fields
$requiredFields = ['id', 'name', 'finalclass'];
foreach ($requiredFields as $field) {
    if (!isset($fields[$field])) {
        $this->logger->warning('Required field missing', [
            'field' => $field,
            'class' => $class,
            'id' => $id
        ]);
    }
}
```

**Handling:**
```php
// PreviewMapper - provide defaults
public function mapCIToPreview(array $ciData): array {
    $fields = $ciData['fields'] ?? [];

    return [
        'title' => $fields['name'] ?? $this->l10n->t('Unknown'),
        'subtitle' => $fields['org_id_friendlyname'] ?? '',
        'status' => $fields['status'] ?? 'unknown',
        'brand' => $fields['brand_id_friendlyname'] ?? '',
        'model' => $fields['model_id_friendlyname'] ?? '',
        // ... with defaults for all fields
    ];
}
```

**User Experience:**
- Preview renders with available data
- Missing fields show as empty or "N/A"
- No error message (graceful degradation)

## Mixed Locales

### Scenario 10: iTop and Nextcloud in Different Languages

**Symptoms:**
- Status labels in French, UI in English
- Mixed language in preview widgets

**Causes:**
- iTop configured for French locale
- Nextcloud user prefers English
- iTop returns localized strings

**Detection:**
```php
// iTop returns localized values
$status = $fields['status']; // "En cours" (French)

// Nextcloud UI in English
$message = $this->l10n->t('Status: {status}', ['status' => $status]);
// Result: "Status: En cours" (mixed)
```

**Handling:**

**Option 1: Accept iTop's Localization**
```php
// Don't translate iTop values - display as-is
$this->logger->debug('Using iTop localized values', [
    'itop_locale' => $this->detectItopLocale(),
    'user_locale' => $this->l10n->getLocaleCode()
]);

// Display: "Status: En cours" (French status, English label)
```

**Option 2: Maintain Translation Map**
```php
// Map common iTop values to Nextcloud translations
private const STATUS_MAP = [
    'new' => 'new',
    'assigned' => 'assigned',
    'En cours' => 'assigned', // French
    'Nouveau' => 'new', // French
    // ...
];

$translatedStatus = self::STATUS_MAP[$fields['status']] ?? $fields['status'];
$label = $this->l10n->t('status_' . $translatedStatus);
```

**Option 3: Emoji/Icons (Language-Independent)**
```php
// Use emoji for status (no translation needed)
$statusEmoji = match($fields['status']) {
    'new', 'Nouveau' => '🆕',
    'assigned', 'En cours' => '👥',
    'resolved', 'Résolu' => '✅',
    default => '⚪'
};

// Display: 🆕 Nouveau (emoji + original text)
```

**User Experience:**
- Clear that iTop values are in iTop's language
- Nextcloud UI labels remain in user's language
- Consider adding locale indicator: "🌐 FR"

**Recommendation:** Use Option 1 (accept iTop's localization) + Option 3 (emojis)

### Scenario 11: Date Format Mismatch

**Symptoms:**
- Timestamps in different formats
- Timezone confusion

**Causes:**
- iTop returns dates in server timezone
- Nextcloud displays in user timezone
- Format string differences

**Detection:**
```php
// iTop returns: "2025-10-18 14:30:00" (server local time, Europe/Vienna)
// User expects: "Oct 18, 2025 8:30 AM" (US Pacific time)
```

**Handling:**
```php
// Parse with iTop timezone
$serverTz = new \DateTimeZone($this->config->getSystemValue('default_timezone', 'Europe/Vienna'));
$dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $fields['last_update'], $serverTz);

// Convert to user's timezone (handled by moment.js on frontend)
$timestamp = $dateTime->getTimestamp();

// Return ISO 8601 for frontend
return [
    'last_update' => $dateTime->format('c') // "2025-10-18T14:30:00+02:00"
];

// Frontend (moment.js)
moment(richObject.last_update).fromNow() // "2 hours ago" (user's locale)
```

**User Experience:**
- Consistent relative times ("2h ago")
- Tooltips show full timestamp in user's locale
- No confusion about timezone

## Rate Limiting Edge Cases

### Scenario 12: Burst Requests at Window Boundary

**Symptoms:**
- User makes 5 requests at 09:59:59
- User makes 5 more at 10:00:00
- Total: 10 requests in 1 second span

**Causes:**
- Fixed window rate limiting
- Window resets at second boundary

**Detection:**
```php
// Simple fixed window (vulnerable to burst)
$count = (int) $this->cache->get('rate_limit_' . $userId);
if ($count >= 5) {
    return false; // Rate limited
}
$this->cache->set('rate_limit_' . $userId, $count + 1, 1); // 1 second TTL
```

**Handling (Sliding Window):**
```php
class SlidingWindowRateLimiter {
    public function checkLimit(string $userId): bool {
        $key = 'rate_limit_' . $userId;
        $now = microtime(true) * 1000; // milliseconds

        // Get request timestamps
        $timestamps = json_decode($this->cache->get($key) ?? '[]', true);

        // Remove timestamps older than 1 second
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $now - 1000);

        if (count($timestamps) >= 5) {
            return false; // Rate limited
        }

        $timestamps[] = $now;
        $this->cache->set($key, json_encode($timestamps), 2);

        return true;
    }
}
```

**User Experience:**
- Fair rate limiting across second boundaries
- No double-capacity at window edge
- Smoother request distribution

### Scenario 13: Cached Response Counted Toward Rate Limit

**Symptoms:**
- User gets rate limited despite seeing cached results
- Cache doesn't reduce API load

**Causes:**
- Rate limit checked before cache lookup
- User penalized for cache hits

**Handling:**
```php
// Check cache BEFORE rate limiting
$cacheKey = $this->getCacheKey($userId, $query);
$cached = $this->cache->get($cacheKey);

if ($cached !== null) {
    // Cache hit - NO rate limit check
    $this->logger->debug('Cache hit, skipping rate limit', ['user' => $userId]);
    return json_decode($cached, true);
}

// Cache miss - check rate limit
if (!$this->rateLimiter->checkLimit($userId)) {
    return ['error' => 'Rate limit exceeded'];
}

// Execute API request
$result = $this->itopAPIService->search($userId, $query);
$this->cache->set($cacheKey, json_encode($result), 30);

return $result;
```

**User Experience:**
- Cache hits don't consume rate limit quota
- Faster responses for repeated queries
- Rate limit only applies to actual API calls

## Concurrent Request Issues

### Scenario 14: Race Condition in Cache Invalidation

**Symptoms:**
- User updates settings
- Some requests still use old cache

**Causes:**
- Cache invalidation not atomic
- Requests in flight when cache cleared

**Detection:**
```php
// User saves config at 10:00:00
public function setConfig(array $values): DataResponse {
    $this->config->setUserValue($userId, 'integration_itop', 'person_id', $newPersonId);
    $this->cacheService->invalidateUserCache($userId); // Clears cache

    return new DataResponse(['success' => true]);
}

// Concurrent search started at 09:59:59, finishes at 10:00:01
// Uses old person_id from before invalidation
```

**Handling:**
```php
// Include config version in cache key
public function getCacheKey(string $userId, string $type, array $params): string {
    $configVersion = $this->getConfigVersion($userId);
    $hash = md5(json_encode($params));

    return "integration_itop/user_{$userId}/v{$configVersion}/{$type}_{$hash}";
}

private function getConfigVersion(string $userId): int {
    // Increment on config change
    $version = (int) $this->config->getUserValue($userId, 'integration_itop', 'config_version', '0');
    return $version;
}

// On config update
public function setConfig(array $values): DataResponse {
    // ... save config ...

    // Bump version (automatically invalidates all caches)
    $oldVersion = (int) $this->config->getUserValue($userId, 'integration_itop', 'config_version', '0');
    $this->config->setUserValue($userId, 'integration_itop', 'config_version', (string)($oldVersion + 1));

    return new DataResponse(['success' => true]);
}
```

**User Experience:**
- Config changes take effect immediately
- No stale cache issues
- No race conditions

## References

- **API Integration:** [itop-api.md](itop-api.md)
- **Security:** [security-auth.md](security-auth.md)
- **Caching:** [caching-performance.md](caching-performance.md)
- **Observability:** [observability.md](observability.md)
