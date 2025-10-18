# iTop REST API Integration Guide

## Overview

This document provides comprehensive guidance for interacting with the iTop REST/JSON API, including endpoint patterns, OQL query construction, error handling, and integration best practices.

## API Endpoint

### Base URL Structure

```
https://{itop-server}/webservices/rest.php?version={api-version}
```

**Example:**
```
http://192.168.139.92/webservices/rest.php?version=1.3
```

**API Version:** This integration targets version **1.3** (iTop 3.1+)

## Authentication

### Auth-Token Header

All requests MUST include authentication via the `Auth-Token` header:

```http
POST /webservices/rest.php?version=1.3 HTTP/1.1
Host: itop-server.example.com
Content-Type: application/x-www-form-urlencoded
Auth-Token: <application-token-here>
User-Agent: Nextcloud-iTop-Integration/1.0

json_data={"operation":"core/get",...}
```

**Implementation:** [ItopAPIService.php:614-680](../lib/Service/ItopAPIService.php#L614-L680)

```php
$options = [
    'headers' => [
        'Auth-Token' => $this->getApplicationToken(),
        'User-Agent' => 'Nextcloud iTop integration',
    ],
    'form_params' => [
        'json_data' => json_encode($params)
    ]
];

$response = $this->client->post($url, $options);
```

## Request Format

### Critical: POST with Form Params

**IMPORTANT:** Use `POST` with `form_params` (not JSON body or GET with URL encoding).

❌ **This FAILS for SELECT queries:**
```php
// BAD - URL encoding breaks OQL SELECT queries
$url = $apiUrl . '&json_data=' . urlencode(json_encode($params));
$response = $this->client->get($url);
```

✅ **This WORKS reliably:**
```php
// GOOD - POST with form_params
$options = [
    'form_params' => [
        'json_data' => json_encode($params)
    ]
];
$response = $this->client->post($url, $options);
```

**Why?** iTop's REST API parser expects `POST` requests with `application/x-www-form-urlencoded` for complex OQL queries.

### Request Parameters

All API operations are encoded in the `json_data` parameter:

```json
{
  "operation": "core/get",
  "class": "PC",
  "key": "SELECT PC WHERE name LIKE '%laptop%'",
  "output_fields": "id,name,status,org_id_friendlyname"
}
```

## Core Operations

### 1. list_operations

**Purpose:** List all available API operations (useful for token validation)

**Request:**
```json
{
  "operation": "list_operations"
}
```

**Response:**
```json
{
  "code": 0,
  "message": "Success",
  "version": "1.3",
  "operations": [
    "core/check_credentials",
    "core/get",
    "core/create",
    "core/update",
    "core/delete",
    "core/get_related",
    "core/apply_stimulus"
  ]
}
```

**Use Cases:**
- Validate application token connectivity
- Check API version compatibility

**Implementation:** [ConfigController.php:278-412](../lib/Controller/ConfigController.php#L278-L412)

### 2. core/check_credentials

**Purpose:** Validate authentication credentials

**Request:**
```json
{
  "operation": "core/check_credentials"
}
```

**Response (Success):**
```json
{
  "code": 0,
  "message": "User authenticated successfully",
  "version": "1.3",
  "user": {
    "login": "admin",
    "first_name": "Tester",
    "last_name": "Admin",
    "email": "admin@example.com",
    "org_name": "Demo"
  },
  "privileges": ["Administrator", "Configuration Manager"]
}
```

**Response (Failure):**
```json
{
  "code": 1,
  "message": "Error: Invalid token or Portal user is not allowed"
}
```

**Use Cases:**
- Admin settings: Test application token
- Personal settings: Validate personal token (before Phase 2 migration)

**Portal User Limitation:** This operation returns error code 1 for Portal users even with valid personal tokens (see [security-auth.md](security-auth.md#the-portal-user-problem))

### 3. core/get

**Purpose:** Retrieve objects from iTop CMDB

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `operation` | string | Yes | Must be `"core/get"` |
| `class` | string | Yes | iTop class name (e.g., `"PC"`, `"Person"`, `"UserRequest"`) |
| `key` | string/int | Yes | Numeric ID, OQL query, or `"SELECT ..."` |
| `output_fields` | string | No | Comma-separated field list (default: all fields) |

#### Retrieval by ID

**Request:**
```json
{
  "operation": "core/get",
  "class": "Person",
  "key": 3,
  "output_fields": "id,first_name,name,email,org_id_friendlyname"
}
```

**Response:**
```json
{
  "code": 0,
  "message": "Success",
  "objects": {
    "Person::3": {
      "code": 0,
      "message": "",
      "class": "Person",
      "key": "3",
      "fields": {
        "id": "3",
        "first_name": "Boris",
        "name": "Bereznay",
        "email": "boris@example.com",
        "org_id_friendlyname": "Demo"
      }
    }
  }
}
```

#### Retrieval by OQL Query

**Request:**
```json
{
  "operation": "core/get",
  "class": "PC",
  "key": "SELECT PC WHERE org_id_friendlyname = 'Demo' AND status = 'production'",
  "output_fields": "id,name,status,brand_id_friendlyname,model_id_friendlyname"
}
```

**Response:**
```json
{
  "code": 0,
  "message": "Success",
  "objects": {
    "PC::1": {
      "code": 0,
      "message": "",
      "class": "PC",
      "key": "1",
      "fields": {
        "id": "1",
        "name": "LAPTOP-001",
        "status": "production",
        "brand_id_friendlyname": "Dell",
        "model_id_friendlyname": "Latitude 7420"
      }
    },
    "PC::5": {
      "code": 0,
      "message": "",
      "class": "PC",
      "key": "5",
      "fields": {
        "id": "5",
        "name": "DESKTOP-042",
        "status": "production",
        "brand_id_friendlyname": "HP",
        "model_id_friendlyname": "EliteDesk 800 G6"
      }
    }
  }
}
```

#### Using :current_contact_id Placeholder

**Special Feature:** iTop provides a magic placeholder `:current_contact_id` that resolves to the authenticated user's Person ID.

**Request:**
```json
{
  "operation": "core/get",
  "class": "Person",
  "key": "SELECT Person WHERE id = :current_contact_id",
  "output_fields": "id,first_name,name,email"
}
```

**Use Cases:**
- Personal token validation (extracts Person ID without prior knowledge)
- Self-service operations (user updating own information)

**Implementation:** [ConfigController.php:645-750](../lib/Controller/ConfigController.php#L645-L750)

### 4. core/create

**Purpose:** Create new objects in iTop

**Status:** ❌ **NOT USED** in this integration (read-only by design)

### 5. core/update

**Purpose:** Update existing objects in iTop

**Status:** ❌ **NOT USED** in this integration (read-only by design)

### 6. core/delete

**Purpose:** Delete objects from iTop

**Status:** ❌ **NOT USED** in this integration (read-only by design)

## OQL Query Language

### Syntax Basics

**Object Query Language (OQL)** is iTop's SQL-like query language.

#### SELECT Structure

```sql
SELECT <class>
[WHERE <conditions>]
[JOIN <class> ON <field> = <field>]
[LIMIT <number>]
```

**Examples:**

```sql
-- All PCs
SELECT PC

-- PCs in production status
SELECT PC WHERE status = 'production'

-- PCs with specific name pattern
SELECT PC WHERE name LIKE '%laptop%'

-- PCs for a specific organization
SELECT PC WHERE org_id_friendlyname = 'Demo'

-- Limit results
SELECT PC LIMIT 10
```

### Field References

#### Direct Fields

```sql
SELECT PC WHERE name = 'LAPTOP-001'
SELECT PC WHERE id = 5
SELECT PC WHERE status = 'production'
```

#### External Key Fields (Relationships)

iTop uses `_id` suffix for foreign keys and `_id_friendlyname` for readable names.

```sql
-- Filter by organization ID
SELECT PC WHERE org_id = 1

-- Filter by organization name (recommended)
SELECT PC WHERE org_id_friendlyname = 'Demo'

-- Filter by brand name
SELECT PC WHERE brand_id_friendlyname = 'Dell'
```

**Why `_friendlyname`?**
- Easier for admins to read/write
- Stable across environments (IDs may differ)
- Natural language filtering

### Operators

| Operator | Usage | Example |
|----------|-------|---------|
| `=` | Exact match | `status = 'production'` |
| `!=` | Not equal | `status != 'obsolete'` |
| `LIKE` | Pattern match (% wildcard) | `name LIKE '%laptop%'` |
| `>`, `<`, `>=`, `<=` | Numeric/date comparison | `move2production > '2024-01-01'` |
| `IN` | List membership | `status IN ('active', 'production')` |
| `MATCHES` | Join to related class | See below |

### MATCHES - Relationship Queries

**Purpose:** Query through external keys and relationships

#### Basic MATCHES

```sql
-- PCs where the organization's code is 'DEMO'
SELECT PC WHERE org_id MATCHES Organization WHERE code = 'DEMO'
```

#### Contact Relationships (Portal User Filtering)

**Critical for Portal Users:** Filter CIs by contact relationship

```sql
-- PCs where Person ID 3 is listed as a contact
SELECT PC WHERE contacts_list MATCHES Person WHERE id = 3
```

**Breakdown:**
- `contacts_list`: N-N relationship field on PC (links to `lnkContactToFunctionalCI`)
- `MATCHES Person WHERE id = 3`: Filters to PCs related to Person #3

**Implementation Pattern:**

```php
// Portal-only users
$personId = $this->getPersonId($userId);
$query = "SELECT PC WHERE contacts_list MATCHES Person WHERE id = $personId";

// Returns only PCs where the user is listed as a contact
```

**Related Classes:**

| CI Class | Contact Relationship Field |
|----------|---------------------------|
| `PC` | `contacts_list` |
| `Phone` | `contacts_list` |
| `Printer` | `contacts_list` |
| `WebApplication` | `contacts_list` |
| All `FunctionalCI` | `contacts_list` |

### Logical Operators

#### AND

```sql
SELECT PC
WHERE org_id_friendlyname = 'Demo'
AND status = 'production'
AND name LIKE '%laptop%'
```

#### OR

```sql
SELECT FunctionalCI
WHERE finalclass = 'PC'
OR finalclass = 'Phone'
OR finalclass = 'Tablet'
```

**Note:** Use parentheses for precedence:

```sql
SELECT PC
WHERE (status = 'production' OR status = 'implementation')
AND org_id_friendlyname = 'Demo'
```

### String Escaping

**CRITICAL:** Always escape user input in OQL queries to prevent injection

```php
// Escape single quotes
$escapedTerm = str_replace("'", "\\'", $userInput);
$query = "SELECT PC WHERE name LIKE '%$escapedTerm%'";
```

**Example:**
```php
// User input: "Alice's Laptop"
$userInput = "Alice's Laptop";
$escapedTerm = str_replace("'", "\\'", $userInput); // "Alice\\'s Laptop"
$query = "SELECT PC WHERE name LIKE '%$escapedTerm%'"; // Safe
```

**Implementation:** [ItopAPIService.php:422](../lib/Service/ItopAPIService.php#L422)

### Polymorphic Queries

**Use Case:** Search across multiple CI classes simultaneously

#### Using finalclass

```sql
-- All devices (PCs, Phones, Tablets)
SELECT FunctionalCI
WHERE finalclass IN ('PC', 'Phone', 'Tablet')
AND status = 'production'
```

**Implementation Strategy for CI Browsing:**

```php
// Search across all enabled CI classes
$enabledClasses = ['PC', 'Phone', 'Tablet', 'Printer', 'PCSoftware', 'WebApplication'];
$classFilter = "finalclass IN ('" . implode("','", $enabledClasses) . "')";

$query = "SELECT FunctionalCI WHERE $classFilter AND name LIKE '%$term%'";
```

**Performance Note:** Querying `FunctionalCI` with `finalclass` filter is more efficient than separate queries per class.

## Output Fields

### Field Selection

**Recommendation:** Always specify `output_fields` to minimize payload size

```json
{
  "operation": "core/get",
  "class": "PC",
  "key": "SELECT PC LIMIT 10",
  "output_fields": "id,name,status,org_id_friendlyname"
}
```

**Wildcard:** Use `*` for all fields (not recommended for production)

```json
{
  "output_fields": "*"
}
```

### Field Sets by Use Case

Defined in [class-mapping.md](class-mapping.md)

#### Unified Search (Lightweight)

```
id,name,finalclass,org_id_friendlyname,status,asset_number,serialnumber,last_update
```

**Purpose:** Fast search results with minimal data

#### Rich Previews (Comprehensive)

```
id,name,finalclass,org_id_friendlyname,status,business_criticity,location_id_friendlyname,
move2production,asset_number,serialnumber,brand_id_friendlyname,model_id_friendlyname,
last_update,description
```

**Purpose:** Full CI preview widget rendering

#### Class-Specific Extras

**PC:**
```
,type,osfamily_id_friendlyname,osversion_id_friendlyname,cpu,ram
```

**Phone/IPPhone:**
```
,phonenumber
```

**MobilePhone:**
```
,phonenumber,imei
```

**WebApplication:**
```
,url,webserver_name
```

**PCSoftware/OtherSoftware:**
```
,system_name,software_id_friendlyname,softwarelicence_id_friendlyname,path
```

### External Keys and Friendlynames

**Pattern:** For every `<field>_id`, there's a `<field>_id_friendlyname`

| Field ID | Friendly Name | Example Value |
|----------|---------------|---------------|
| `org_id` | `org_id_friendlyname` | "Demo" |
| `brand_id` | `brand_id_friendlyname` | "Dell" |
| `model_id` | `model_id_friendlyname` | "Latitude 7420" |
| `location_id` | `location_id_friendlyname` | "Vienna Office - Floor 3" |
| `osfamily_id` | `osfamily_id_friendlyname` | "Windows" |

**Recommendation:** Always use `_friendlyname` for display purposes

## Response Format

### Success Response

```json
{
  "code": 0,
  "message": "Success",
  "objects": {
    "<Class>::<ID>": {
      "code": 0,
      "message": "",
      "class": "<Class>",
      "key": "<ID>",
      "fields": {
        "id": "<ID>",
        "field1": "value1",
        "field2": "value2"
      }
    }
  }
}
```

### Empty Result

```json
{
  "code": 0,
  "message": "Success",
  "objects": {}
}
```

**Check:** `isset($response['objects']) && !empty($response['objects'])`

### Error Codes

| Code | Meaning | Example Message |
|------|---------|-----------------|
| `0` | Success | "Success" |
| `1` | Authentication error | "Invalid token" or "Portal user is not allowed" |
| `2` | Missing parameter | "Missing parameter: class" |
| `3` | Invalid class | "Unknown class: InvalidClass" |
| `4` | Invalid key/OQL | "Invalid OQL query" |
| `100` | Internal error | "Internal error" |

**Implementation:**

```php
if (!isset($result['code']) || $result['code'] !== 0) {
    $error = $result['message'] ?? 'Unknown error';
    return ['error' => $error, 'error_code' => $result['code'] ?? null];
}
```

## Pagination & Limits

### LIMIT Clause

**OQL Syntax:**
```sql
SELECT PC LIMIT 20
```

**Recommendation:** Use limits to prevent large payloads

```php
// Unified Search: 20 results globally (distributed across classes)
// Per-class limit: ceil(20 / count($enabledClasses))
$perClassLimit = 5;
$query = "SELECT PC WHERE name LIKE '%$term%' LIMIT $perClassLimit";
```

### No Native Offset

**Limitation:** iTop OQL does not support `OFFSET` or `SKIP`

**Workaround for Pagination:**

```php
// Fetch more than needed, slice in PHP
$query = "SELECT PC LIMIT 100";
$results = $this->request($userId, $params);

// Client-side pagination
$page = 2;
$perPage = 10;
$offset = ($page - 1) * $perPage;
$pageResults = array_slice($results['objects'], $offset, $perPage);
```

**Performance Note:** For large datasets, use filtering instead of pagination:
- Filter by status, organization, or date range
- Use search terms to narrow results

## Common Query Patterns

### 1. Get User's Person Record

**Use Case:** Personal token validation, user info display

```json
{
  "operation": "core/get",
  "class": "Person",
  "key": "SELECT Person WHERE id = :current_contact_id",
  "output_fields": "id,first_name,name,email,org_id_friendlyname"
}
```

### 2. Get User's Contact CIs (Portal Users)

**Use Case:** Portal user CI browsing

```json
{
  "operation": "core/get",
  "class": "FunctionalCI",
  "key": "SELECT FunctionalCI WHERE contacts_list MATCHES Person WHERE id = 3",
  "output_fields": "id,name,finalclass,status,org_id_friendlyname"
}
```

### 3. Search Across Multiple CI Classes

**Use Case:** Unified search for power users

```json
{
  "operation": "core/get",
  "class": "FunctionalCI",
  "key": "SELECT FunctionalCI WHERE finalclass IN ('PC','Phone','Tablet','Printer') AND name LIKE '%laptop%'",
  "output_fields": "id,name,finalclass,status,org_id_friendlyname"
}
```

### 4. Get CI by URL ID

**Use Case:** Rich preview from pasted iTop URL

**URL Pattern:**
```
http://itop-server/pages/UI.php?operation=details&class=PC&id=5
```

**Query:**
```json
{
  "operation": "core/get",
  "class": "PC",
  "key": 5,
  "output_fields": "id,name,status,org_id_friendlyname,brand_id_friendlyname,model_id_friendlyname,cpu,ram"
}
```

### 5. Get User's Tickets (UserRequest + Incident)

**Use Case:** Dashboard widget, ticket search

```php
// Step 1: Get user's full name
$userInfo = $this->getCurrentUser($userId);
$fullName = $userInfo['user']['first_name'] . ' ' . $userInfo['user']['last_name'];

// Step 2: Query UserRequests
$query1 = "SELECT UserRequest WHERE caller_id_friendlyname = '$fullName' AND status != 'closed'";

// Step 3: Query Incidents
$query2 = "SELECT Incident WHERE caller_id_friendlyname = '$fullName' AND status != 'closed'";
```

**Implementation:** [ItopAPIService.php:176-251](../lib/Service/ItopAPIService.php#L176-L251)

## Error Handling

### Network Errors

```php
try {
    $response = $this->client->post($url, $options);
} catch (ConnectException $e) {
    // Network failure (DNS, timeout, connection refused)
    return [
        'error' => 'Connection failed: ' . $e->getMessage(),
        'error_type' => 'network'
    ];
} catch (ServerException $e) {
    // HTTP 5xx errors
    $statusCode = $e->getResponse()->getStatusCode();
    return [
        'error' => 'iTop server error',
        'error_code' => $statusCode,
        'error_type' => 'server'
    ];
} catch (ClientException $e) {
    // HTTP 4xx errors
    $statusCode = $e->getResponse()->getStatusCode();

    if ($statusCode === 401) {
        return [
            'error' => 'Authentication failed - invalid token',
            'error_code' => 401,
            'error_type' => 'auth'
        ];
    }

    return [
        'error' => 'Client error: ' . $statusCode,
        'error_code' => $statusCode,
        'error_type' => 'client'
    ];
}
```

**Implementation:** [ItopAPIService.php:665-679](../lib/Service/ItopAPIService.php#L665-L679)

### API-Level Errors

```php
$result = json_decode($response->getBody(), true);

// Check JSON decode
if ($result === null) {
    return ['error' => 'Invalid JSON response', 'error_type' => 'parse'];
}

// Check API error code
if (!isset($result['code']) || $result['code'] !== 0) {
    $errorMsg = $result['message'] ?? 'Unknown error';

    // Special handling for Portal users
    if (strpos($errorMsg, 'Portal user is not allowed') !== false) {
        return [
            'error' => 'Portal users must use application token flow',
            'error_type' => 'portal_restriction',
            'hint' => 'This is expected - configure personal token for identity verification only'
        ];
    }

    return [
        'error' => $errorMsg,
        'error_code' => $result['code'],
        'error_type' => 'api'
    ];
}

// Check for empty results
if (!isset($result['objects']) || empty($result['objects'])) {
    return ['error' => 'No results found', 'error_type' => 'empty'];
}
```

### Error Response to User

```php
if (isset($apiResult['error'])) {
    // Log server-side (without sensitive data)
    $this->logger->warning('iTop API error', [
        'error_type' => $apiResult['error_type'] ?? 'unknown',
        'user' => $userId
    ]);

    // Return user-friendly message
    $userMessage = match($apiResult['error_type'] ?? '') {
        'network' => $this->l10n->t('Could not connect to iTop server'),
        'auth' => $this->l10n->t('Authentication failed - check your settings'),
        'portal_restriction' => $this->l10n->t('Portal user restriction - contact administrator'),
        'empty' => $this->l10n->t('No results found'),
        default => $this->l10n->t('An error occurred: ') . $apiResult['error']
    };

    return new DataResponse(['error' => $userMessage], Http::STATUS_BAD_REQUEST);
}
```

## Performance Optimization

### 1. Minimize Output Fields

❌ **Bad:**
```json
{"operation": "core/get", "class": "PC", "key": "SELECT PC", "output_fields": "*"}
```

✅ **Good:**
```json
{"operation": "core/get", "class": "PC", "key": "SELECT PC", "output_fields": "id,name,status"}
```

**Impact:** 80% payload reduction for large objects

### 2. Use Specific Queries

❌ **Bad:**
```sql
SELECT FunctionalCI
-- Returns thousands of objects
```

✅ **Good:**
```sql
SELECT FunctionalCI WHERE org_id_friendlyname = 'Demo' AND status = 'production' LIMIT 20
```

**Impact:** 100x faster for large CMDBs

### 3. Leverage finalclass Filtering

❌ **Bad:**
```php
// 3 separate API calls
$pcs = $this->request(['key' => 'SELECT PC LIMIT 5']);
$phones = $this->request(['key' => 'SELECT Phone LIMIT 5']);
$tablets = $this->request(['key' => 'SELECT Tablet LIMIT 5']);
```

✅ **Good:**
```php
// 1 API call
$all = $this->request([
    'class' => 'FunctionalCI',
    'key' => "SELECT FunctionalCI WHERE finalclass IN ('PC','Phone','Tablet') LIMIT 15"
]);
```

**Impact:** 3x reduction in API calls

### 4. Cache Results

See [caching-performance.md](caching-performance.md) for detailed caching strategies

**Quick Example:**

```php
$cacheKey = 'ci_preview_' . $userId . '_' . $class . '_' . $id;
$cached = $this->cache->get($cacheKey);

if ($cached !== null) {
    return json_decode($cached, true);
}

$result = $this->request($userId, $params);
$this->cache->set($cacheKey, json_encode($result), 60); // 60s TTL

return $result;
```

## Security Best Practices

### 1. Never Trust User Input

```php
// Escape OQL parameters
$safeTerm = str_replace("'", "\\'", $userInput);
$query = "SELECT PC WHERE name LIKE '%$safeTerm%'";
```

### 2. Validate person_id Server-Side

```php
// NEVER accept person_id from client
public function search(string $userId, string $term): array {
    // GOOD - retrieve person_id server-side
    $personId = $this->getPersonId($userId);

    if (!$personId) {
        throw new Exception('User not configured');
    }

    // Use in query
    $query = "SELECT PC WHERE contacts_list MATCHES Person WHERE id = $personId";
}
```

### 3. Filter Results by Profile

```php
// Check user profile before allowing full CMDB access
$isPortalOnly = $this->profileService->isPortalOnly($userId);

if ($isPortalOnly) {
    // Restrict to contact-related CIs
    $query = "SELECT PC WHERE contacts_list MATCHES Person WHERE id = $personId";
} else {
    // Allow full search within ACL
    $query = "SELECT PC WHERE name LIKE '%$term%'";
}
```

### 4. Log Without Sensitive Data

```php
// BAD
$this->logger->info('Query: ' . $query); // May contain person names

// GOOD
$this->logger->info('CI search executed', [
    'user' => $userId,
    'class' => $class,
    'result_count' => count($results)
]);
```

## Rate Limiting

**Recommendation:** 5 requests/second/user for interactive features

**Implementation:** See [caching-performance.md](caching-performance.md#rate-limiting)

## Testing & Debugging

### Manual Testing with curl

**Test 1: Validate Application Token**

```bash
curl -s --location -g --request POST \
  'http://192.168.139.92/webservices/rest.php?version=1.3' \
  --header 'Auth-Token: YOUR_APP_TOKEN_HERE' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'json_data={"operation":"list_operations"}' | jq
```

**Expected:**
```json
{
  "code": 0,
  "message": "Success",
  "operations": [...]
}
```

**Test 2: Get Person by :current_contact_id**

```bash
curl -s --location -g --request POST \
  'http://192.168.139.92/webservices/rest.php?version=1.3' \
  --header 'Auth-Token: YOUR_PERSONAL_TOKEN_HERE' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'json_data={"operation":"core/get","class":"Person","key":"SELECT Person WHERE id = :current_contact_id","output_fields":"id,first_name,name,email"}' | jq
```

**Expected:**
```json
{
  "code": 0,
  "objects": {
    "Person::3": {
      "fields": {
        "id": "3",
        "first_name": "Boris",
        "name": "Bereznay",
        "email": "boris@example.com"
      }
    }
  }
}
```

**Test 3: Get Contact CIs**

```bash
curl -s --location -g --request POST \
  'http://192.168.139.92/webservices/rest.php?version=1.3' \
  --header 'Auth-Token: YOUR_APP_TOKEN_HERE' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'json_data={"operation":"core/get","class":"FunctionalCI","key":"SELECT FunctionalCI WHERE contacts_list MATCHES Person WHERE id = 3","output_fields":"id,name,finalclass,status"}' | jq
```

### Debug Logging

**Enable verbose logging:**

```php
// In ItopAPIService::request()
$this->logger->debug('iTop API Request', [
    'operation' => $params['operation'] ?? 'unknown',
    'class' => $params['class'] ?? 'unknown',
    'user' => $userId
]);

$this->logger->debug('iTop API Response', [
    'code' => $result['code'] ?? 'missing',
    'object_count' => isset($result['objects']) ? count($result['objects']) : 0
]);
```

**Log Location:** `nextcloud/data/nextcloud.log`

## Common Issues & Solutions

### Issue 1: Portal User Gets "Not Allowed" Error

**Symptom:**
```json
{"code": 1, "message": "Error: Portal user is not allowed"}
```

**Cause:** Portal users cannot use REST API directly (iTop core restriction)

**Solution:** Use dual-token architecture (see [security-auth.md](security-auth.md))
- Personal token for identity verification only
- Application token for all queries

### Issue 2: SELECT Query Returns Empty

**Symptom:**
```json
{"code": 0, "objects": {}}
```

**Debug Steps:**
1. Verify OQL syntax in iTop console (Data Administration → OQL Queries)
2. Check field names match iTop data model
3. Verify user has permissions to query the class
4. Test with simpler query (e.g., `SELECT PC LIMIT 1`)

**Common Mistake:** Using `org_id` instead of `org_id_friendlyname`

### Issue 3: GET Request Fails for SELECT Queries

**Symptom:** HTTP 400 or malformed query error

**Cause:** URL encoding breaks complex OQL

**Solution:** Use POST with `form_params` (not GET)

### Issue 4: Person Not Found for Portal User

**Symptom:**
```json
{"code": 0, "objects": {}}
```

**Cause:** User doesn't have a linked Person record in iTop

**Solution:** Admin must create Person record and link to User account (contactid field)

## API Version Compatibility

| iTop Version | API Version | Compatibility | Notes |
|--------------|-------------|---------------|-------|
| 3.1.x | 1.3 | ✅ Full | Target version |
| 3.0.x | 1.3 | ✅ Full | Compatible |
| 2.7.x | 1.3 | ⚠️ Partial | Test thoroughly |
| 2.6.x and older | 1.2 | ❌ Not tested | May need changes |

**Recommendation:** Require iTop 3.0+ in documentation

## Future Enhancements

### Planned Improvements

- [ ] **Batch queries** - Multiple operations in one request (iTop 3.2+)
- [ ] **GraphQL support** - If iTop adds GraphQL endpoint
- [ ] **Webhook subscriptions** - Real-time CI updates
- [ ] **Field metadata caching** - Reduce data model queries

## References

- **Official API Docs:** https://www.itophub.io/wiki/page?id=latest:advancedtopics:rest_json
- **OQL Reference:** https://www.itophub.io/wiki/page?id=latest:oql:start
- **Implementation:** [ItopAPIService.php](../lib/Service/ItopAPIService.php)
- **Security:** [security-auth.md](security-auth.md)
- **Class Mapping:** [class-mapping.md](class-mapping.md)
