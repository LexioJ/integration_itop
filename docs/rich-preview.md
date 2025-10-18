# Rich Preview - Reference Provider Specification

## Overview

The Rich Preview feature enables rich, interactive link previews for iTop tickets and Configuration Items pasted anywhere in Nextcloud (Text app, Talk, comments, etc.). When a user pastes an iTop URL, Nextcloud automatically fetches and renders a compact, informative widget displaying key object information.

## Architecture

### Reference Provider

**Class:** `OCA\Itop\Reference\ItopReferenceProvider`
**Interface:** `OCP\Collaboration\Reference\ADiscoverableReferenceProvider`
**Location:** `lib/Reference/ItopReferenceProvider.php`

**Responsibilities:**
- Detect iTop URLs in pasted text
- Fetch object data from iTop API
- Transform data into rich object format
- Manage preview caching
- Handle access control

## URL Pattern Matching

### Detection Pattern

The provider detects iTop URLs using the following regex pattern:

```php
$pattern = '#^' . preg_quote($itopUrl, '#') . '/pages/UI\.php\?.*operation=details.*class=([^&]+).*id=(\d+)#';
```

**Matches:**
```
✅ http://itop.example.com/pages/UI.php?operation=details&class=UserRequest&id=123
✅ http://itop.example.com/pages/UI.php?operation=details&class=PC&id=5&param=foo
✅ https://192.168.139.92/pages/UI.php?operation=details&class=Incident&id=42
```

**Does NOT match:**
```
❌ http://itop.example.com/pages/UI.php?operation=list&class=PC (no id param)
❌ http://itop.example.com/pages/UI.php?id=5 (no class param)
❌ http://other-site.com/pages/UI.php?operation=details&class=PC&id=5 (wrong domain)
```

### URL Components Extracted

| Component | Extraction | Example |
|-----------|------------|---------|
| `class` | Regex capture group 1 | `UserRequest`, `PC`, `Incident` |
| `id` | Regex capture group 2 (as int) | `123`, `5`, `42` |

### Base URL Configuration

The provider supports two URL configurations:

1. **Admin-configured URL:** `admin_instance_url` (global default)
2. **User-configured URL:** User's personal iTop URL (overrides admin)

**Fallback Logic:**
```php
$adminItopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
$userItopUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
$itopUrl = $userItopUrl ?: $adminItopUrl;
```

**Use Case:** Users accessing multiple iTop instances can set personal URLs while others use the global default.

## Supported Object Classes

### Phase 1: Tickets (Current Implementation)

| Class | Icon | Status Badge | Priority | Use Case |
|-------|------|--------------|----------|----------|
| `UserRequest` | user-request.svg / user-request-closed.svg | Yes | Yes | Service requests |
| `Incident` | incident.svg | Yes | Yes | IT incidents |

### Phase 2: Configuration Items (Planned)

| Class | Icon | Status Badge | CI-Specific Fields |
|-------|------|--------------|-------------------|
| `PC` | PC.svg | Yes | Brand, Model, CPU, RAM, OS |
| `Phone` | Phone.svg | Yes | Phone number |
| `IPPhone` | Phone.svg | Yes | Phone number |
| `MobilePhone` | Phone.svg | Yes | Phone number, IMEI |
| `Tablet` | Tablet.svg | Yes | Brand, Model |
| `Printer` | Printer.svg | Yes | Brand, Model |
| `Peripheral` | Peripheral.svg | Yes | Brand, Model |
| `PCSoftware` | PCSoftware.svg | Yes | Software, License, Path |
| `OtherSoftware` | Software.svg | Yes | Software, License, Path |
| `WebApplication` | WebApplication.svg | Yes | URL, Webserver |

**Fallback Icon:** `FunctionalCI.svg` for unrecognized CI classes

## Preview Widget Rendering

### Widget Structure (Tickets)

The preview widget follows a three-row layout:

```
┌─────────────────────────────────────────────────────────────┐
│ [Icon]  🔴 [R-000123] Laptop running slow    [Production] · 2h ago │
│         🏷️ IT Support > Hardware for Boris B. (Demo)                │
│         🏢 Demo > 👥 IT Team > 👤 Jane Smith                        │
│ ─────────────────────────────────────────────────────────── │
│ Description: My laptop has been running very slow since...  │
└─────────────────────────────────────────────────────────────┘
```

**Component:** `src/views/ReferenceItopWidget.vue`

### Row 1: Priority + Title | Status + Date

**Left Side:**
- Priority emoji (🔴🟠🟡🟢) - Visual priority indicator
- Ticket reference link (`[R-000123]` or `[I-000456]`)
- Ticket title (clickable, opens in iTop)

**Right Side:**
- Status badge (colored pill with status text)
- Relative date (closed/updated time with tooltip)

**Example:**
```vue
<div class="row-1">
  <div class="left">
    <span class="priority-emoji">🔴</span>
    <a :href="ticketUrl" target="_blank">
      <strong>[R-000123] Laptop running slow</strong>
    </a>
  </div>
  <div class="right">
    <span class="status-badge" style="background: rgba(139,92,246,0.15); color: #8b5cf6">
      assigned
    </span>
    <span v-tooltip="'Oct 18, 2025 2:30 PM'" class="date">
      · 2h ago
    </span>
  </div>
</div>
```

### Row 2: Service Breadcrumb | Org/Team/Agent Breadcrumb

**Left Side (Service Breadcrumb):**
- Service emoji (🏷️)
- Service name
- Subcategory (if present) with `>` separator
- Caller name (linked to Person details) with organization

**Format:** `🏷️ Service > Subcategory for Caller Name (Org)`

**Right Side (Org/Team/Agent Breadcrumb):**
- Organization emoji (🏢) + name
- Team emoji (👥) + name
- Agent emoji (👤) + name (linked to Person details)

**Format:** `🏢 Org > 👥 Team > 👤 Agent`

**Example:**
```vue
<div class="row-2">
  <div class="left">
    <span v-html="serviceBreadcrumb" />
    <!-- Renders: 🏷️ IT Support > Hardware for Boris B. (Demo) -->
  </div>
  <div class="right">
    <span v-html="orgTeamAgentBreadcrumb" />
    <!-- Renders: 🏢 Demo > 👥 IT Team > 👤 Jane Smith -->
  </div>
</div>
```

### Description Section

**Behavior:**
- Initially collapsed (40px max-height)
- Click to expand to full description (250px max scrollable)
- Tooltip hint: "Click to expand description"
- Pre-wrapped text for line breaks
- Stripped of HTML tags

**Example:**
```vue
<div class="description">
  <div
    class="description-content"
    :class="{ 'short-description': shortDescription }"
    @click="shortDescription = !shortDescription"
    v-tooltip="shortDescription ? 'Click to expand' : undefined">
    {{ richObject.description }}
  </div>
</div>
```

## Field Mapping from iTop Data

### Ticket Objects (UserRequest, Incident)

**API Request:**
```php
$params = [
    'operation' => 'core/get',
    'class' => $class, // 'UserRequest' or 'Incident'
    'key' => $ticketId,
    'output_fields' => implode(',', [
        'id', 'ref', 'title', 'description',
        'status', 'priority',
        'caller_id', 'caller_id_friendlyname',
        'agent_id', 'agent_id_friendlyname',
        'org_name', 'org_id_friendlyname',
        'team_id_friendlyname',
        'service_name', 'servicesubcategory_name',
        'creation_date', 'last_update', 'close_date', 'start_date'
    ])
];
```

**Rich Object Mapping:**
```php
$reference->setRichObject('integration_itop_ticket', [
    'id' => $ticketId,
    'class' => $class,
    'title' => $fields['title'],
    'ref' => $fields['ref'], // e.g., 'R-000123'
    'status' => $fields['status'], // e.g., 'assigned'
    'priority' => $fields['priority'], // e.g., '1' (critical)
    'caller_id' => $fields['caller_id'],
    'caller_id_friendlyname' => $fields['caller_id_friendlyname'],
    'agent_id' => $fields['agent_id'],
    'agent_id_friendlyname' => $fields['agent_id_friendlyname'],
    'org_name' => $fields['org_name'],
    'org_id_friendlyname' => $fields['org_id_friendlyname'],
    'team_id_friendlyname' => $fields['team_id_friendlyname'],
    'service_name' => $fields['service_name'],
    'servicesubcategory_name' => $fields['servicesubcategory_name'],
    'description' => strip_tags($fields['description']),
    'creation_date' => $fields['creation_date'],
    'last_update' => $fields['last_update'],
    'close_date' => $fields['close_date'],
    'start_date' => $fields['start_date'],
    'url' => $url,
    'itop_url' => $itopUrl,
]);
```

### CI Objects (Phase 2)

**Common Core Fields:**
```php
$coreFields = [
    'id', 'name', 'finalclass',
    'org_id_friendlyname',
    'status', 'business_criticity',
    'location_id_friendlyname',
    'move2production',
    'asset_number', 'serialnumber',
    'brand_id_friendlyname', 'model_id_friendlyname',
    'last_update', 'description'
];
```

**Class-Specific Extras:**
```php
$classExtras = match($class) {
    'PC' => ['type', 'osfamily_id_friendlyname', 'osversion_id_friendlyname', 'cpu', 'ram'],
    'Phone', 'IPPhone' => ['phonenumber'],
    'MobilePhone' => ['phonenumber', 'imei'],
    'WebApplication' => ['url', 'webserver_name'],
    'PCSoftware', 'OtherSoftware' => ['system_name', 'software_id_friendlyname', 'softwarelicence_id_friendlyname', 'path'],
    default => []
};
```

**CI Rich Object Structure:**
```php
$reference->setRichObject('integration_itop_ci', [
    'id' => $id,
    'class' => $class,
    'name' => $fields['name'],
    'status' => $fields['status'],
    'organization' => $fields['org_id_friendlyname'],
    'location' => $fields['location_id_friendlyname'],
    'business_criticity' => $fields['business_criticity'],
    'asset_number' => $fields['asset_number'],
    'serialnumber' => $fields['serialnumber'],
    'brand' => $fields['brand_id_friendlyname'],
    'model' => $fields['model_id_friendlyname'],
    'last_update' => $fields['last_update'],
    'description' => strip_tags($fields['description']),
    // Class-specific fields
    'extras' => $this->mapClassExtras($fields, $class),
    'url' => $url,
    'itop_url' => $itopUrl,
]);
```

## Icons and Theming

### Icon Assets

**Location:** `img/`

**Ticket Icons:**
- `user-request.svg` - Open user request (blue)
- `user-request-closed.svg` - Closed user request (green checkmark)
- `incident.svg` - Incident (red alert)
- `ticket.svg` - Generic fallback

**CI Icons:**
- `PC.svg` - Desktop/laptop computers
- `Phone.svg` - Phones (all types)
- `Tablet.svg` - Tablet devices
- `Printer.svg` - Printers
- `Peripheral.svg` - Other peripherals
- `PCSoftware.svg` - Desktop software
- `Software.svg` - Generic software
- `WebApplication.svg` - Web applications
- `FunctionalCI.svg` - Fallback CI icon

**Icon Selection Logic:**
```javascript
ticketIcon() {
  const ticketClass = this.richObject.class || ''
  const status = this.richObject.status?.toLowerCase() || ''
  const isClosed = status.includes('resolved') || status.includes('closed')

  const basePath = window.location.origin + '/apps/integration_itop/img/'

  if (ticketClass === 'Incident') {
    return basePath + 'incident.svg'
  }
  if (ticketClass === 'UserRequest') {
    return isClosed
      ? basePath + 'user-request-closed.svg'
      : basePath + 'user-request.svg'
  }
  // CI classes
  if (ticketClass === 'PC') return basePath + 'PC.svg'
  // ... other CI classes

  return basePath + 'ticket.svg' // Fallback
}
```

### Status Badge Colors

**Color Scheme:**

| Status Pattern | Background | Text Color | Example |
|----------------|------------|------------|---------|
| `resolved`, `closed` | `rgba(40,167,69,0.15)` | `#28a745` | Green pill |
| `assigned` | `rgba(139,92,246,0.15)` | `#8b5cf6` | Purple pill |
| `new` | `rgba(59,130,246,0.15)` | `#3b82f6` | Blue pill |
| `pending` | `rgba(245,158,11,0.15)` | `#f59e0b` | Orange pill |
| Default | `rgba(239,68,68,0.15)` | `#ef4444` | Red pill |

**Implementation:**
```javascript
statusBadgeColor() {
  const status = this.richObject.status?.toLowerCase() || ''
  if (status.includes('resolved') || status.includes('closed')) {
    return 'rgba(40, 167, 69, 0.15)'
  }
  // ... other conditions
  return 'rgba(239, 68, 68, 0.15)' // Default red
}

statusColor() {
  const status = this.richObject.status?.toLowerCase() || ''
  if (status.includes('resolved') || status.includes('closed')) {
    return '#28a745'
  }
  // ... other conditions
  return '#ef4444' // Default red
}
```

### Priority Emoji

| Priority | Emoji | Label |
|----------|-------|-------|
| `1` or `critical` | 🔴 | Critical |
| `2` or `high` | 🟠 | High |
| `3` or `medium` | 🟡 | Medium |
| `4` or `low` | 🟢 | Low |

**Implementation:**
```javascript
priorityEmoji() {
  const priority = this.richObject.priority?.toLowerCase() || ''
  if (priority.includes('1') || priority.includes('critical')) return '🔴'
  if (priority.includes('2') || priority.includes('high')) return '🟠'
  if (priority.includes('3') || priority.includes('medium')) return '🟡'
  return '🟢'
}
```

## Responsive Design

### Desktop Layout (≥768px)

```
┌──────────────────────────────────────────────────────────┐
│ [Icon]  🔴 [Title................................] [Status] │
│         🏷️ Service breadcrumb              Org breadcrumb │
│ ──────────────────────────────────────────────────────── │
│ Description text (expandable)                            │
└──────────────────────────────────────────────────────────┘
```

**Characteristics:**
- Two-column layout with flex `justify-content: space-between`
- Icon: 48x48px
- Full breadcrumbs visible
- Description: 40px collapsed, 250px expanded

### Mobile Layout (<768px)

```
┌────────────────────────────┐
│ [Icon] 🔴 [Title..........]│
│        [Status badge]      │
│        🏷️ Service         │
│        🏢 Org > 👤 Agent   │
│ ────────────────────────── │
│ Description (expandable)   │
└────────────────────────────┘
```

**Responsive Breakpoints:**
```scss
@media (max-width: 768px) {
  .row-1, .row-2 {
    flex-direction: column;
    align-items: flex-start;

    .right {
      margin-top: 4px;
    }
  }

  .ticket-icon {
    width: 36px;
    height: 36px;
  }
}
```

### Text Overflow Handling

**Breadcrumb Truncation:**
```scss
.service-breadcrumb, .org-breadcrumb {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  @media (max-width: 768px) {
    white-space: normal; // Allow wrapping on mobile
  }
}
```

**Title Truncation:**
```scss
.ticket-link {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0; // Allow flex shrinking
}
```

## Error States and Fallbacks

### Error Detection

**Provider Error Handling:**
```php
// API error
if (isset($ticketInfo['error']) || empty($ticketInfo['objects'])) {
    $this->logger->debug('Ticket not found or error', [
        'ticketId' => $ticketId,
        'class' => $class,
        'error' => $ticketInfo['error'] ?? 'No objects returned'
    ]);
    return null; // No reference rendered
}
```

**Widget Error Handling:**
```vue
<div v-if="isError">
  <h3>
    <ItopIcon :size="20" />
    <span>{{ t('integration_itop', 'iTop API error') }}</span>
  </h3>
  <p class="widget-error">{{ richObject.error }}</p>
  <a :href="settingsUrl" class="settings-link" target="_blank">
    <OpenInNewIcon :size="20" />
    {{ t('integration_itop', 'iTop connected accounts settings') }}
  </a>
</div>
```

### Error Scenarios

| Scenario | Provider Behavior | Widget Display |
|----------|-------------------|----------------|
| User not configured | `matchReference() = false` | No preview (plain URL) |
| Invalid token | API returns error | Error message + settings link |
| Ticket not found | `return null` | No preview (plain URL) |
| Network timeout | Exception caught, `return null` | No preview (plain URL) |
| Missing person_id | `matchReference() = false` | No preview (plain URL) |
| Portal user restricted | API returns empty | No preview (plain URL) |
| Invalid URL format | `matchReference() = false` | No preview (plain URL) |

### Fallback to Plain URL

**When preview fails:**
1. Provider returns `null` from `resolveReference()`
2. Nextcloud renders URL as plain hyperlink
3. No error message shown to user (silent degradation)

**Example:**
```
User pastes: http://itop.example.com/pages/UI.php?operation=details&class=PC&id=999
Provider checks: Ticket not found
Result: http://itop.example.com/pages/UI.php?operation=details&class=PC&id=999
        (Plain blue hyperlink, no widget)
```

### Missing Field Handling

**Conditional Rendering:**
```vue
<span v-if="richObject.priority" class="priority-emoji">
  {{ priorityEmoji }}
</span>

<span v-if="serviceBreadcrumb" class="service-breadcrumb" v-html="serviceBreadcrumb" />

<div v-if="richObject.description" class="description">
  <!-- ... -->
</div>
```

**Breadcrumb Fallback:**
```javascript
serviceBreadcrumb() {
  const service = this.richObject.service_name
  const subcategory = this.richObject.servicesubcategory_name
  const caller = this.richObject.caller_id_friendlyname

  if (!service && !subcategory && !caller) {
    return null // Hide entire breadcrumb if all fields missing
  }

  const parts = []
  if (service) parts.push('🏷️ ' + service)
  if (subcategory) parts.push(' > ' + subcategory)
  if (caller) parts.push(' for ' + caller)

  return parts.join('')
}
```

## Caching Strategy

### Cache Configuration

**Provider Interface Methods:**
```php
public function getCachePrefix(string $referenceId): string {
    return $this->userId ?? '';
}

public function getCacheKey(string $referenceId): ?string {
    return $referenceId; // URL as cache key
}
```

**Cache Key Pattern:**
```
{userId}/{url}

Example:
boris/http://itop.example.com/pages/UI.php?operation=details&class=UserRequest&id=123
```

### Cache TTL

| Data Type | TTL | Rationale |
|-----------|-----|-----------|
| Ticket previews | 60s | Tickets update frequently |
| CI previews | 300s | CIs change less often |
| User info | 600s | Rarely changes |

**Configuration (Phase 2):**
```php
// In ItopReferenceProvider
private const CACHE_TTL_TICKET = 60;
private const CACHE_TTL_CI = 300;

public function getCacheTTL(string $class): int {
    return in_array($class, ['UserRequest', 'Incident'])
        ? self::CACHE_TTL_TICKET
        : self::CACHE_TTL_CI;
}
```

### Cache Invalidation

**User-Triggered:**
```php
public function invalidateUserCache(string $userId): void {
    // Called when user updates settings
    $this->cacheService->deletePrefix('integration_itop/' . $userId . '/');
}
```

**Time-Based:**
- Automatic expiry after TTL
- No manual invalidation needed for most cases

### ETag Support (Future Enhancement)

**Response Headers:**
```php
$response = new DataResponse($richObject);
$response->addHeader('ETag', md5(json_encode($richObject)));
$response->addHeader('Cache-Control', 'private, max-age=60');
return $response;
```

## Access Control

### Permission Checks

**Provider-Level:**
```php
public function matchReference(string $referenceText): bool {
    if ($this->userId === null) {
        return false; // Guest users cannot see previews
    }

    $personId = $this->config->getUserValue(
        $this->userId,
        Application::APP_ID,
        'person_id',
        ''
    );

    if ($personId === '') {
        return false; // User not configured
    }

    // URL pattern matching
    return $this->getTicketIdFromUrl($referenceText, $itopUrl) !== null;
}
```

**API-Level:**
```php
private function getTicketReference(int $ticketId, string $class, string $url): ?IReference {
    $ticketInfo = $this->itopAPIService->getTicketInfo($this->userId, $ticketId, $class);

    if (isset($ticketInfo['error']) || empty($ticketInfo['objects'])) {
        // User doesn't have permission or ticket doesn't exist
        return null;
    }

    // Render preview
}
```

### Portal User Restrictions

**Scenario:** Portal user pastes link to ticket they don't own

**Flow:**
1. Provider matches URL pattern
2. API service applies contacts filter (see [security-auth.md](security-auth.md))
3. iTop returns empty result (no ACL violation logged)
4. Provider returns `null`
5. User sees plain URL (no error message)

**Rationale:** Silent failure prevents information disclosure about ticket existence

## Integration Points

### Text App

**Trigger:** User pastes iTop URL in rich text editor

**Behavior:**
1. Text app detects URL via Nextcloud's reference system
2. Calls `ItopReferenceProvider::matchReference()`
3. If matched, calls `resolveReference()` to fetch data
4. Renders `ReferenceItopWidget` component inline
5. Widget is interactive (clickable links, expandable description)

### Talk

**Trigger:** User pastes iTop URL in chat message

**Behavior:**
1. Talk detects URL in message text
2. Fetches reference via provider
3. Renders widget below message
4. Widget supports dark mode (uses Nextcloud CSS variables)

**OpenGraph Fallback:**
```php
// Set title to help Talk understand reference type
$reference->setTitle('[' . $ticketRef . '] ' . $ticketTitle);
```

**Note:** Without `setTitle()`, Talk may show "Enable interactive view" button instead of auto-rendering

### Comments (Files, Deck, etc.)

**Trigger:** User pastes iTop URL in comment field

**Behavior:** Same as Text app - widget rendered inline

### Searchable References

**Interface:** `ISearchableReferenceProvider`

**Method:**
```php
public function search(string $term): array {
    // Search tickets/CIs matching term
    $searchResults = $this->itopAPIService->search($this->userId, $term, 0, 5);

    return array_map(function($item) {
        return [
            'id' => $item['url'],
            'title' => $item['title'],
            'description' => strip_tags($item['description']),
            'url' => $item['url'],
            'imageUrl' => $this->getIconUrl(),
        ];
    }, $searchResults);
}
```

**Use Case:** Smart picker autocomplete (user types "@" or "/" in Text app to search tickets)

## Performance Considerations

### Lazy Loading

**Widget Component:**
```javascript
// Only fetch heavy data when widget is visible
mounted() {
  if (this.richObject && !this.richObject.error) {
    // Widget already has data from provider
    // No additional API calls needed
  }
}
```

### Batch Processing

**Future Enhancement:** Render multiple previews efficiently

```php
// Detect multiple URLs in one paste operation
public function resolveReferences(array $referenceTexts): array {
    $ticketIds = [];
    foreach ($referenceTexts as $text) {
        $info = $this->getTicketIdFromUrl($text, $itopUrl);
        if ($info) $ticketIds[] = $info;
    }

    // Batch API request
    $tickets = $this->itopAPIService->getTickets($ticketIds);

    // Map to references
    return array_map(fn($ticket) => $this->createReference($ticket), $tickets);
}
```

### Size Limits

**Description Truncation:**
- Strip HTML tags: `strip_tags($description)`
- No server-side truncation (handled by widget CSS)
- Client-side: 40px height when collapsed, 250px when expanded

**Icon Size:**
- Desktop: 48x48px
- Mobile: 36x36px
- SVG format for scalability

## Internationalization

### Translatable Strings

**Provider:**
```php
$this->l10n->t('Status: %s', [$status]);
$this->l10n->t('Priority: %s', [$priority]);
$this->l10n->t('Caller: %s', [$caller]);
$this->l10n->t('Created: %s', [$date]);
```

**Widget:**
```javascript
t('integration_itop', 'iTop API error')
t('integration_itop', 'Unknown error')
t('integration_itop', 'iTop connected accounts settings')
t('integration_itop', 'created {relativeDate}', { relativeDate: ... })
t('integration_itop', 'closed {relativeDate}', { relativeDate: ... })
t('integration_itop', 'updated {relativeDate}', { relativeDate: ... })
t('integration_itop', 'Click to expand description')
```

### Date Formatting

**Relative Dates (moment.js):**
```javascript
moment(this.richObject.creation_date).fromNow()
// Output: "2 hours ago", "3 days ago", etc.
```

**Absolute Dates (tooltip):**
```javascript
moment(this.richObject.creation_date).format('LLL')
// Output: "October 18, 2025 2:30 PM" (localized)
```

**Timezone Handling:**
- iTop returns dates in server timezone (configured in `default_timezone`)
- Moment.js converts to user's browser timezone automatically
- Tooltips show full timestamp for clarity

## Testing Scenarios

### Manual Testing

**Test Case 1: Basic Ticket Preview**
1. Copy ticket URL: `http://itop.example.com/pages/UI.php?operation=details&class=UserRequest&id=123`
2. Paste in Text app
3. Verify widget renders with title, status, description
4. Click title link → Opens in iTop
5. Click description → Expands/collapses

**Test Case 2: Portal User Restriction**
1. Login as portal user (boris)
2. Paste URL to ticket not owned by boris
3. Verify plain URL displayed (no widget)
4. Paste URL to boris's own ticket
5. Verify widget renders correctly

**Test Case 3: Error Handling**
1. Paste URL with invalid ticket ID (9999)
2. Verify plain URL displayed (silent failure)
3. Disable iTop API (stop container)
4. Paste valid URL → Verify plain URL (network error)

### Automated Tests

**Unit Tests:**
```php
class ItopReferenceProviderTest extends TestCase {
    public function testMatchReference() {
        $provider = new ItopReferenceProvider(...);

        $validUrl = 'http://itop.test/pages/UI.php?operation=details&class=UserRequest&id=123';
        $this->assertTrue($provider->matchReference($validUrl));

        $invalidUrl = 'http://other.test/page';
        $this->assertFalse($provider->matchReference($invalidUrl));
    }

    public function testGetTicketIdFromUrl() {
        $result = $provider->getTicketIdFromUrl($url, $itopUrl);
        $this->assertEquals(['class' => 'UserRequest', 'id' => 123], $result);
    }
}
```

**Integration Tests:**
```php
public function testResolveReference() {
    $reference = $provider->resolveReference($validUrl);

    $this->assertInstanceOf(IReference::class, $reference);
    $this->assertEquals('integration_itop_ticket', $reference->getRichObjectType());
    $this->assertEquals('Laptop running slow', $reference->getRichObject()['title']);
}
```

## Future Enhancements

### Phase 2 Additions

- [ ] CI preview support (PC, Phone, Tablet, etc.)
- [ ] PreviewMapper service for data transformation
- [ ] Class-specific field rendering
- [ ] CI-specific icons and badges

### Phase 3+ Features

- [ ] Inline actions (Assign to me, Change status)
- [ ] Ticket timeline preview
- [ ] Attachment thumbnails
- [ ] Related tickets/CIs preview
- [ ] Graph visualizations (CI relationships)

## References

- **Implementation:** [lib/Reference/ItopReferenceProvider.php](../lib/Reference/ItopReferenceProvider.php)
- **Widget Component:** [src/views/ReferenceItopWidget.vue](../src/views/ReferenceItopWidget.vue)
- **API Integration:** [itop-api.md](itop-api.md)
- **Security:** [security-auth.md](security-auth.md)
- **Class Mapping:** [class-mapping.md](class-mapping.md)
- **Nextcloud Reference API:** https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/references.html
