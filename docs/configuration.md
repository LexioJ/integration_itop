# Configuration - Admin and Personal Settings

## Overview

The iTop Integration app provides two configuration interfaces: Admin Settings for system-wide configuration and Personal Settings for individual user setup. Both interfaces include validation, test connectivity features, and clear error messaging to ensure proper configuration.

## Admin Settings Panel

### Location

**Path:** Settings → Administration → iTop Integration
**URL:** `/settings/admin/integration_itop`
**Access:** Admin users only
**Component:** `lib/Settings/Admin.php` + `src/views/AdminSettings.vue`

### Settings Fields

#### 1. iTop URL

**Field Type:** Text input (URL)
**Required:** Yes
**Validation Rules:**
- Must be a valid URL format (`http://` or `https://`)
- Must be reachable from Nextcloud server
- Must not include trailing slash

**Example Values:**
```
✅ http://itop.example.com
✅ https://itop-prod.company.local
✅ http://192.168.139.92
❌ itop.example.com (missing protocol)
❌ http://itop.example.com/ (trailing slash)
❌ http://itop.example.com/pages/UI.php (too specific)
```

**Configuration Key:** `admin_instance_url`

**Validation Implementation:**
```php
private function validateUrl(string $url): array {
    // Remove trailing slash
    $url = rtrim($url, '/');

    // Check URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'valid' => false,
            'error' => $this->l10n->t('Invalid URL format')
        ];
    }

    // Check protocol
    if (!preg_match('/^https?:\/\//', $url)) {
        return [
            'valid' => false,
            'error' => $this->l10n->t('URL must start with http:// or https://')
        ];
    }

    return ['valid' => true, 'url' => $url];
}
```

#### 2. Display Name

**Field Type:** Text input
**Required:** No
**Default:** "iTop"
**Validation Rules:**
- Length: 1-50 characters
- No special characters except spaces, hyphens, underscores

**Example Values:**
```
✅ iTop
✅ iTop Production
✅ IT Service Management
✅ iTop-Dev
❌ (empty string - uses default "iTop")
❌ iTop <Production> (HTML chars not allowed)
```

**Configuration Key:** `user_facing_name`

**Usage:** Displayed in search results, widget titles, link buttons

**Validation Implementation:**
```php
private function validateDisplayName(string $name): array {
    $name = trim($name);

    if (empty($name)) {
        return ['valid' => true, 'name' => 'iTop']; // Use default
    }

    if (strlen($name) > 50) {
        return [
            'valid' => false,
            'error' => $this->l10n->t('Display name too long (max 50 characters)')
        ];
    }

    // Remove HTML tags for security
    $sanitized = strip_tags($name);
    if ($sanitized !== $name) {
        return [
            'valid' => false,
            'error' => $this->l10n->t('HTML tags not allowed in display name')
        ];
    }

    return ['valid' => true, 'name' => $sanitized];
}
```

#### 3. Application Token

**Field Type:** Password input (masked)
**Required:** Yes
**Storage:** Encrypted using `ICrypto` service
**Validation Rules:**
- Not empty
- Valid iTop API token format
- Token must have required permissions

**Example Format:**
```
4c2a8f9b3d1e6a7c5b0f2e8d9a3c1b6e
```

**Configuration Key:** `application_token` (encrypted)

**Security:**
- Token encrypted at rest using Nextcloud's `ICrypto::encrypt()`
- Never logged in plain text
- Never sent to client-side
- Decrypted only for API requests

**Validation Implementation:**
```php
private function validateApplicationToken(string $token): array {
    $token = trim($token);

    if (empty($token)) {
        return [
            'valid' => false,
            'error' => $this->l10n->t('Application token is required')
        ];
    }

    // Test token validity by calling iTop API
    try {
        $result = $this->itopAPIService->testToken($token);

        if (isset($result['error'])) {
            return [
                'valid' => false,
                'error' => $this->l10n->t('Invalid token: ') . $result['error']
            ];
        }

        return ['valid' => true, 'token' => $token];
    } catch (\Exception $e) {
        return [
            'valid' => false,
            'error' => $this->l10n->t('Token validation failed: ') . $e->getMessage()
        ];
    }
}
```

#### 4. Enabled CI Classes (Phase 2)

**Field Type:** Multi-select checkboxes
**Required:** No
**Default:** All classes enabled
**Options:**
```
☑ PC
☑ Phone
☑ IPPhone
☑ MobilePhone
☑ Tablet
☑ Printer
☑ Peripheral
☑ PCSoftware
☑ OtherSoftware
☑ WebApplication
```

**Configuration Key:** `enabled_ci_classes` (JSON array)

**Implementation:**
```php
private function saveEnabledClasses(array $classes): void {
    $validClasses = [
        'PC', 'Phone', 'IPPhone', 'MobilePhone', 'Tablet',
        'Printer', 'Peripheral', 'PCSoftware', 'OtherSoftware', 'WebApplication'
    ];

    // Filter to only valid classes
    $enabled = array_intersect($classes, $validClasses);

    $this->config->setAppValue(
        Application::APP_ID,
        'enabled_ci_classes',
        json_encode($enabled)
    );
}
```

#### 5. Global Result Limit

**Field Type:** Number input
**Required:** No
**Default:** 20
**Range:** 5-50

**Configuration Key:** `global_result_limit`

**Validation:**
```php
private function validateResultLimit(int $limit): array {
    if ($limit < 5 || $limit > 50) {
        return [
            'valid' => false,
            'error' => $this->l10n->t('Result limit must be between 5 and 50')
        ];
    }

    return ['valid' => true, 'limit' => $limit];
}
```

### Test Connection Functionality

**Button:** "Test Connection"
**Location:** Below Application Token field
**Behavior:** Validates URL + token by calling iTop API

**API Endpoint:** `POST /apps/integration_itop/admin/test-connection`

**Test Steps:**
1. Validate URL format
2. Check network connectivity to iTop server
3. Validate application token
4. Fetch iTop version and capabilities
5. Check required permissions

**Implementation:**
```php
/**
 * @NoCSRFRequired
 * @AuthorizedAdminSettingClass(settings=Admin::class)
 */
public function testConnection(): DataResponse {
    $url = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
    $token = $this->config->getAppValue(Application::APP_ID, 'application_token', '');

    if (empty($url) || empty($token)) {
        return new DataResponse([
            'success' => false,
            'message' => $this->l10n->t('Please configure URL and token first')
        ], Http::STATUS_BAD_REQUEST);
    }

    // Decrypt token
    try {
        $decryptedToken = $this->crypto->decrypt($token);
    } catch (\Exception $e) {
        return new DataResponse([
            'success' => false,
            'message' => $this->l10n->t('Failed to decrypt token')
        ], Http::STATUS_INTERNAL_SERVER_ERROR);
    }

    // Test connectivity
    try {
        $result = $this->itopAPIService->testConnection($url, $decryptedToken);

        if ($result['success']) {
            return new DataResponse([
                'success' => true,
                'message' => $this->l10n->t('Connection successful'),
                'details' => [
                    'version' => $result['version'] ?? 'unknown',
                    'user' => $result['user'] ?? [],
                    'operations' => $result['operations'] ?? []
                ]
            ]);
        } else {
            return new DataResponse([
                'success' => false,
                'message' => $result['error'] ?? $this->l10n->t('Connection failed')
            ], Http::STATUS_BAD_REQUEST);
        }
    } catch (\Exception $e) {
        return new DataResponse([
            'success' => false,
            'message' => $this->l10n->t('Connection error: ') . $e->getMessage()
        ], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
```

**Success Response:**
```json
{
  "success": true,
  "message": "Connection successful",
  "details": {
    "version": "1.3",
    "user": {
      "login": "admin",
      "first_name": "Admin",
      "last_name": "User"
    },
    "operations": [
      "core/get",
      "core/check_credentials",
      "list_operations"
    ]
  }
}
```

**Error Response:**
```json
{
  "success": false,
  "message": "Invalid token or insufficient permissions"
}
```

### User Info Display

**Section:** Connected Users
**Display:** Count of configured users
**Refresh:** Manual button click

**Implementation:**
```php
public function getConnectedUsersCount(): DataResponse {
    $count = $this->userManager->callForAllUsers(function($user) {
        $personId = $this->config->getUserValue(
            $user->getUID(),
            Application::APP_ID,
            'person_id',
            ''
        );
        return !empty($personId) ? 1 : 0;
    });

    return new DataResponse([
        'count' => array_sum($count)
    ]);
}
```

### Admin Settings UI Component

**Component:** `src/views/AdminSettings.vue`

```vue
<template>
  <div id="itop-admin-settings">
    <h2>{{ t('integration_itop', 'iTop Integration') }}</h2>

    <div class="section">
      <h3>{{ t('integration_itop', 'Connection') }}</h3>

      <div class="field">
        <label for="itop-url">{{ t('integration_itop', 'iTop URL') }}</label>
        <input
          id="itop-url"
          v-model="url"
          type="url"
          placeholder="http://itop.example.com"
          @change="onUrlChange">
        <p class="hint">{{ t('integration_itop', 'Base URL of your iTop instance (without trailing slash)') }}</p>
      </div>

      <div class="field">
        <label for="display-name">{{ t('integration_itop', 'Display Name') }}</label>
        <input
          id="display-name"
          v-model="displayName"
          type="text"
          placeholder="iTop"
          @change="onDisplayNameChange">
        <p class="hint">{{ t('integration_itop', 'Name shown in search results and widgets') }}</p>
      </div>

      <div class="field">
        <label for="app-token">{{ t('integration_itop', 'Application Token') }}</label>
        <input
          id="app-token"
          v-model="appToken"
          type="password"
          placeholder="••••••••••••••••"
          @change="onAppTokenChange">
        <p class="hint">{{ t('integration_itop', 'Admin-level API token from iTop') }}</p>
      </div>

      <button @click="testConnection" :disabled="loading">
        <span v-if="!loading">{{ t('integration_itop', 'Test Connection') }}</span>
        <span v-else>{{ t('integration_itop', 'Testing...') }}</span>
      </button>

      <div v-if="testResult" class="test-result" :class="testResult.success ? 'success' : 'error'">
        <p>{{ testResult.message }}</p>
        <div v-if="testResult.details" class="details">
          <p>{{ t('integration_itop', 'API Version: {version}', { version: testResult.details.version }) }}</p>
          <p v-if="testResult.details.user">
            {{ t('integration_itop', 'Authenticated as: {user}', {
              user: testResult.details.user.first_name + ' ' + testResult.details.user.last_name
            }) }}
          </p>
        </div>
      </div>
    </div>

    <div class="section">
      <h3>{{ t('integration_itop', 'Users') }}</h3>
      <p>{{ t('integration_itop', 'Connected users: {count}', { count: connectedUsersCount }) }}</p>
      <button @click="refreshUserCount">{{ t('integration_itop', 'Refresh') }}</button>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
  name: 'AdminSettings',

  data() {
    return {
      url: '',
      displayName: '',
      appToken: '',
      loading: false,
      testResult: null,
      connectedUsersCount: 0,
    }
  },

  async mounted() {
    await this.loadSettings()
    await this.refreshUserCount()
  },

  methods: {
    async loadSettings() {
      try {
        const response = await axios.get(generateUrl('/apps/integration_itop/admin/config'))
        this.url = response.data.url || ''
        this.displayName = response.data.display_name || 'iTop'
        // Don't load token (security)
      } catch (error) {
        showError(t('integration_itop', 'Failed to load settings'))
      }
    },

    async onUrlChange() {
      await this.saveConfig({ url: this.url })
    },

    async onDisplayNameChange() {
      await this.saveConfig({ display_name: this.displayName })
    },

    async onAppTokenChange() {
      await this.saveConfig({ application_token: this.appToken })
    },

    async saveConfig(data) {
      try {
        await axios.post(generateUrl('/apps/integration_itop/admin/config'), data)
        showSuccess(t('integration_itop', 'Settings saved'))
      } catch (error) {
        showError(t('integration_itop', 'Failed to save settings'))
      }
    },

    async testConnection() {
      this.loading = true
      this.testResult = null

      try {
        const response = await axios.post(generateUrl('/apps/integration_itop/admin/test-connection'))
        this.testResult = response.data
        if (response.data.success) {
          showSuccess(t('integration_itop', 'Connection successful'))
        } else {
          showError(response.data.message)
        }
      } catch (error) {
        this.testResult = {
          success: false,
          message: error.response?.data?.message || t('integration_itop', 'Connection failed')
        }
        showError(this.testResult.message)
      } finally {
        this.loading = false
      }
    },

    async refreshUserCount() {
      try {
        const response = await axios.get(generateUrl('/apps/integration_itop/admin/users/count'))
        this.connectedUsersCount = response.data.count
      } catch (error) {
        console.error('Failed to load user count', error)
      }
    },
  },
}
</script>

<style scoped lang="scss">
#itop-admin-settings {
  .section {
    margin-bottom: 32px;

    h3 {
      margin-top: 0;
    }
  }

  .field {
    margin-bottom: 16px;

    label {
      display: block;
      margin-bottom: 4px;
      font-weight: 500;
    }

    input {
      width: 400px;
      max-width: 100%;
    }

    .hint {
      margin-top: 4px;
      font-size: 12px;
      color: var(--color-text-maxcontrast);
    }
  }

  .test-result {
    margin-top: 16px;
    padding: 12px;
    border-radius: var(--border-radius);

    &.success {
      background: var(--color-success);
      color: white;
    }

    &.error {
      background: var(--color-error);
      color: white;
    }

    .details {
      margin-top: 8px;
      font-size: 12px;
      opacity: 0.9;
    }
  }
}
</style>
```

## Personal Settings Panel

### Location

**Path:** Settings → Personal → iTop Integration
**URL:** `/settings/user/connected-accounts#itop_prefs`
**Access:** All users
**Component:** `lib/Settings/Personal.php` + `src/views/PersonalSettings.vue`

### Settings Fields

#### 1. Personal API Token (One-Time)

**Field Type:** Password input
**Required:** Yes (for initial setup)
**Storage:** **NEVER STORED** - Used only once to extract Person ID
**Validation Rules:**
- Valid iTop personal token format
- Token must belong to authenticated user
- Token grants access to Person record

**Workflow:**
1. User generates personal token in iTop
2. User enters token in Nextcloud personal settings
3. Token validated via `:current_contact_id` query
4. Person ID extracted and stored
5. Token immediately discarded

**See:** [security-auth.md](security-auth.md) for detailed dual-token flow

**Implementation:**
```php
public function setConfig(array $values): DataResponse {
    $personalToken = $values['personal_token'] ?? null;

    if ($personalToken) {
        // One-time validation
        $validation = $this->validatePersonalTokenAndExtractPersonId($personalToken);

        if (!$validation['success']) {
            return new DataResponse([
                'error' => $validation['error']
            ], Http::STATUS_BAD_REQUEST);
        }

        // Store ONLY the person_id
        $this->config->setUserValue(
            $this->userId,
            Application::APP_ID,
            'person_id',
            $validation['person_id']
        );

        // Store user info for display
        $this->config->setUserValue(
            $this->userId,
            Application::APP_ID,
            'user_info',
            json_encode($validation['user_info'])
        );

        // Personal token is NEVER stored - it's now out of scope

        return new DataResponse([
            'success' => true,
            'user_info' => $validation['user_info']
        ]);
    }

    return new DataResponse(['success' => true]);
}

private function validatePersonalTokenAndExtractPersonId(string $personalToken): array {
    try {
        // Query using personal token + magic placeholder
        $params = [
            'operation' => 'core/get',
            'class' => 'Person',
            'key' => 'SELECT Person WHERE id = :current_contact_id',
            'output_fields' => 'id,first_name,name,email,org_id_friendlyname'
        ];

        $result = $this->itopAPIService->requestWithToken($params, $personalToken);

        if (isset($result['error']) || empty($result['objects'])) {
            return [
                'success' => false,
                'error' => $result['error'] ?? $this->l10n->t('Person not found')
            ];
        }

        $person = $result['objects'][array_key_first($result['objects'])];
        $fields = $person['fields'];

        return [
            'success' => true,
            'person_id' => $fields['id'],
            'user_info' => [
                'first_name' => $fields['first_name'],
                'last_name' => $fields['name'],
                'email' => $fields['email'],
                'organization' => $fields['org_id_friendlyname']
            ]
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => $this->l10n->t('Validation failed: ') . $e->getMessage()
        ];
    }
}
```

#### 2. Enable Search (Opt-Out)

**Field Type:** Checkbox
**Required:** No
**Default:** Enabled
**Configuration Key:** `search_enabled`

**Implementation:**
```php
$searchEnabled = $values['search_enabled'] ?? true;
$this->config->setUserValue(
    $this->userId,
    Application::APP_ID,
    'search_enabled',
    $searchEnabled ? '1' : '0'
);
```

#### 3. Enable Notifications (Future)

**Field Type:** Checkbox
**Default:** Disabled
**Configuration Key:** `notification_enabled`

### User Info Display

**Display After Token Validation:**
```
✓ Connected as: Boris Bereznay (boris@example.com)
  Organization: Demo
  Person ID: 3
```

**Implementation:**
```vue
<div v-if="isConfigured" class="user-info">
  <CheckCircleIcon :size="20" class="icon success" />
  <div class="content">
    <p class="name">
      {{ t('integration_itop', 'Connected as: {name}', {
        name: userInfo.first_name + ' ' + userInfo.last_name
      }) }}
    </p>
    <p class="email">({{ userInfo.email }})</p>
    <p class="org">{{ t('integration_itop', 'Organization: {org}', { org: userInfo.organization }) }}</p>
    <p class="person-id">{{ t('integration_itop', 'Person ID: {id}', { id: personId }) }}</p>
  </div>
</div>
```

### Profile Preview (Phase 2)

**Display:**
```
Profile: Portal user
Access: Limited to assigned tickets and related CIs
```

**Implementation:**
```php
public function getProfileInfo(): DataResponse {
    $personId = $this->config->getUserValue($this->userId, Application::APP_ID, 'person_id', '');

    if (empty($personId)) {
        return new DataResponse(['error' => 'Not configured'], Http::STATUS_PRECONDITION_FAILED);
    }

    $profiles = $this->profileService->getUserProfiles($this->userId);
    $isPortalOnly = $this->profileService->isPortalOnly($this->userId);

    return new DataResponse([
        'profiles' => $profiles,
        'is_portal_only' => $isPortalOnly,
        'access_level' => $isPortalOnly ? 'limited' : 'full'
    ]);
}
```

### Error Messaging

#### Configuration Not Complete

```
⚠ iTop integration not configured
Please configure the iTop URL and application token in admin settings first.
[Go to Admin Settings]
```

#### Invalid Personal Token

```
❌ Token validation failed
The personal token is invalid or does not grant access to your Person record.
Please check your token and try again.
```

#### Network Error

```
❌ Connection error
Could not connect to iTop server. Please check your network connection.
```

#### Portal User API Block

```
⚠ Portal user restriction detected
Portal users cannot use personal tokens directly. This is expected.
Your configuration is being saved using the application token.
```

## Validation Rules Summary

### Admin Settings

| Field | Required | Validation | Error Message |
|-------|----------|------------|---------------|
| iTop URL | Yes | Valid URL format, http(s):// | "Invalid URL format" |
| Display Name | No | 1-50 chars, no HTML | "Display name too long" |
| Application Token | Yes | Non-empty, valid token | "Invalid token" |
| Result Limit | No | 5-50 | "Must be between 5 and 50" |

### Personal Settings

| Field | Required | Validation | Error Message |
|-------|----------|------------|---------------|
| Personal Token | Yes (once) | Valid token, matches user | "Token validation failed" |
| Search Enabled | No | Boolean | N/A |

## API Endpoints

### Admin Endpoints

```
GET  /apps/integration_itop/admin/config
POST /apps/integration_itop/admin/config
POST /apps/integration_itop/admin/test-connection
GET  /apps/integration_itop/admin/users/count
```

### Personal Endpoints

```
GET  /apps/integration_itop/config
POST /apps/integration_itop/config
POST /apps/integration_itop/config/validate-token
GET  /apps/integration_itop/config/user-info
DELETE /apps/integration_itop/config
```

## Testing

### Manual Testing

**Admin Settings:**
1. Navigate to admin settings
2. Enter invalid URL → Verify error message
3. Enter valid URL + token → Test connection → Verify success
4. Change display name → Save → Verify used in search

**Personal Settings:**
1. Navigate to personal settings without admin config → Verify warning
2. Enter invalid personal token → Verify error
3. Enter valid token → Verify user info displayed
4. Delete configuration → Verify cleared

### Automated Tests

```php
class ConfigControllerTest extends TestCase {
    public function testValidateUrl() {
        $result = $controller->validateUrl('http://itop.example.com');
        $this->assertTrue($result['valid']);

        $result = $controller->validateUrl('invalid-url');
        $this->assertFalse($result['valid']);
    }

    public function testPersonalTokenValidation() {
        $result = $controller->setConfig(['personal_token' => 'valid-token']);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }
}
```

## References

- **Security Architecture:** [security-auth.md](security-auth.md)
- **API Integration:** [itop-api.md](itop-api.md)
- **Testing:** [testing.md](testing.md)
