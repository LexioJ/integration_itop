# Smart Picker - Provider Specification

## Overview

The Smart Picker integration provides intelligent suggestions for iTop tickets and Configuration Items when composing text in Nextcloud apps (Text, Talk, Comments). When users type a trigger character or search term, they get instant access to relevant iTop objects that can be inserted as clickable links with automatic rich preview rendering.

## Architecture

### Smart Picker Provider

**Class:** `OCA\Itop\Picker\ItopSmartPickerProvider` (Phase 3 - Planned)
**Interface:** `OCP\Collaboration\AutoComplete\IProvider`
**Location:** `lib/Picker/ItopSmartPickerProvider.php`

**Responsibilities:**
- Register as autocomplete/smart picker provider
- Provide suggestions based on user input
- Debounce and throttle search queries
- Format suggestions for picker UI
- Insert links in appropriate format
- Apply access control rules

## Integration Points

### Nextcloud Text App

**Trigger Methods:**

1. **Slash Command:** `/itop` or `/ticket`
   ```
   User types: "/itop laptop"
   → Shows dropdown with matching tickets/CIs
   → User selects "R-000123 Laptop running slow"
   → Inserts: [R-000123 Laptop running slow](http://itop.../UI.php?...&id=123)
   ```

2. **At-Mention Style:** `@itop:`
   ```
   User types: "@itop:PC"
   → Shows dropdown with PCs
   → User selects "LAPTOP-001"
   → Inserts: [LAPTOP-001](http://itop.../UI.php?class=PC&id=5)
   ```

**Implementation Hook:**
```javascript
// src/components/TextEditorIntegration.js
OC.Plugins.register('OCA.Text.Editor', {
  attach: function(editor) {
    editor.registerAutoComplete({
      id: 'integration_itop',
      triggers: ['/itop', '/ticket', '@itop:'],
      search: async (query) => {
        const response = await fetch('/apps/integration_itop/api/v1/picker/search', {
          method: 'POST',
          body: JSON.stringify({ query })
        });
        return response.json();
      },
      renderSuggestion: (suggestion) => {
        return `${suggestion.icon} ${suggestion.title}`;
      }
    });
  }
});
```

### Nextcloud Talk

**Trigger Methods:**

1. **Mention-Style:** `@itop laptop`
   ```
   User types in chat: "@itop laptop"
   → Shows suggestion dropdown
   → User selects ticket
   → Message sent with rich preview
   ```

2. **Smart Compose:** Automatic detection
   ```
   User types: "See ticket about laptop issue"
   → Inline suggestion: "R-000123 Laptop running slow"
   → Press Tab to accept
   ```

**Implementation Hook:**
```javascript
// Talk registers its own autocomplete provider
OCA.Talk.registerMentionProvider({
  id: 'integration_itop',
  icon: '/apps/integration_itop/img/app.svg',
  callback: async (query) => {
    return await fetch(`/apps/integration_itop/api/v1/picker/search?q=${query}`);
  }
});
```

### Comments (Files, Deck, Tasks)

**Trigger:** Same as Text app

**Behavior:**
- User types trigger in comment field
- Picker shows suggestions
- Link inserted
- Rich preview renders below comment

**Example:**
```
File comment: "This relates to /itop R-123"
→ Picker shows: R-000123 Laptop running slow
→ Inserted link: [R-000123](http://itop.../...)
→ Preview widget renders below comment
```

### Dashboard Widgets (Future)

**Use Case:** Quick ticket creation widget

**Trigger:** Button click → Search modal with smart picker

**Example:**
```
[Create ticket from template]
→ Modal opens with template picker
→ User selects "Hardware Request"
→ Pre-fills form with template data
```

## Suggestion Debouncing and Throttling

### Client-Side Debouncing

**Input Delay:**
```javascript
// Wait 300ms after user stops typing before searching
const searchDebounced = debounce(async (query) => {
  const results = await searchItop(query);
  showSuggestions(results);
}, 300);

input.addEventListener('input', (e) => {
  searchDebounced(e.target.value);
});
```

**Implementation:**
```javascript
function debounce(func, delay) {
  let timeoutId;
  return function(...args) {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => func.apply(this, args), delay);
  };
}
```

**Benefits:**
- Reduces API calls by 80%+
- Improves UX (waits for user to finish typing)
- Prevents server overload

### Server-Side Throttling

**Rate Limiting (Per User):**
```php
class PickerController {
    private const MAX_REQUESTS_PER_MINUTE = 20;

    public function search(string $query): DataResponse {
        $userId = $this->userId;
        $cacheKey = 'picker_rate_limit_' . $userId;

        $requestCount = (int) $this->cache->get($cacheKey);

        if ($requestCount >= self::MAX_REQUESTS_PER_MINUTE) {
            return new DataResponse(
                ['error' => 'Rate limit exceeded'],
                Http::STATUS_TOO_MANY_REQUESTS
            );
        }

        $this->cache->set($cacheKey, $requestCount + 1, 60); // 1-minute TTL

        // Execute search
        $results = $this->pickerService->search($userId, $query);
        return new DataResponse($results);
    }
}
```

**Rate Limit Headers:**
```http
HTTP/1.1 200 OK
X-RateLimit-Limit: 20
X-RateLimit-Remaining: 15
X-RateLimit-Reset: 1697654400
```

### Minimum Query Length

**Requirement:** At least 2 characters

```php
public function search(string $query): array {
    $query = trim($query);

    if (strlen($query) < 2) {
        return []; // No results for single-char searches
    }

    return $this->itopAPIService->search($this->userId, $query, 0, 10);
}
```

**Rationale:**
- Prevents overly broad searches
- Reduces API load
- Improves result relevance

### Cache-First Strategy

**Cache Recent Searches:**
```php
private function searchWithCache(string $userId, string $query): array {
    $cacheKey = 'picker_search_' . $userId . '_' . md5($query);
    $cached = $this->cache->get($cacheKey);

    if ($cached !== null) {
        return json_decode($cached, true);
    }

    $results = $this->itopAPIService->search($userId, $query, 0, 10);

    // Cache for 60 seconds
    $this->cache->set($cacheKey, json_encode($results), 60);

    return $results;
}
```

**Benefits:**
- Instant responses for repeated queries
- Reduces iTop API load
- Better UX for common searches

## Result Formatting for Picker UI

### Suggestion Entry Structure

**Provider Format:**
```php
public function search(string $query): array {
    $results = $this->itopAPIService->search($this->userId, $query, 0, 10);

    return array_map(function($item) {
        return [
            'id' => $item['id'],                    // Unique identifier
            'label' => $this->formatLabel($item),   // Main display text
            'subline' => $this->formatSubline($item), // Secondary text
            'icon' => $this->getIconUrl($item),     // Icon URL
            'value' => $this->formatValue($item),   // Text to insert
            'metadata' => [
                'class' => $item['class'],
                'status' => $item['status'],
                'priority' => $item['priority']
            ]
        ];
    }, $results);
}
```

### Label Formatting

**Ticket Labels:**
```php
private function formatLabel(array $item): string {
    $statusEmoji = $this->getStatusEmoji($item['status']);
    $priorityEmoji = $this->getPriorityEmoji($item['priority']);

    // Format: 🔴 ✅ [R-000123] Laptop running slow
    return $priorityEmoji . ' ' . $statusEmoji . ' [' . $item['ref'] . '] ' . $item['title'];
}

// Examples:
// "🔴 🆕 [R-000123] Laptop running slow"
// "🟡 👥 [I-000456] Network outage"
// "🟢 ✅ [R-000789] Request new monitor"
```

**CI Labels (Phase 2):**
```php
private function formatLabel(array $item): string {
    $icon = $this->getClassIcon($item['class']);
    $statusBadge = $this->getStatusBadge($item['status']);

    // Format: 💻 LAPTOP-001 [Production] (Demo)
    return $icon . ' ' . $item['name'] . ' ' . $statusBadge . ' (' . $item['org'] . ')';
}

// Examples:
// "💻 LAPTOP-001 [Production] (Demo)"
// "📱 PHONE-042 [Active] (IT Dept)"
// "🖨️ PRINTER-005 [Stock] (Vienna Office)"
```

**Class Icons (Emoji Fallback):**
```php
private function getClassIcon(string $class): string {
    return match($class) {
        'PC' => '💻',
        'Phone', 'IPPhone', 'MobilePhone' => '📱',
        'Tablet' => '📱',
        'Printer' => '🖨️',
        'WebApplication' => '🌐',
        'PCSoftware', 'OtherSoftware' => '💾',
        default => '📦'
    };
}
```

### Subline Formatting

**Ticket Sublines:**
```php
private function formatSubline(array $item): string {
    $parts = [];

    // Organization
    if (!empty($item['org'])) {
        $parts[] = '🏢 ' . $item['org'];
    }

    // Agent
    if (!empty($item['agent'])) {
        $parts[] = '👤 ' . $item['agent'];
    }

    // Time info
    $time = $this->formatRelativeTime($item['last_update']);
    if ($time) {
        $parts[] = '🕐 ' . $time;
    }

    return implode(' • ', $parts);
}

// Example: "🏢 Demo • 👤 Jane Smith • 🕐 2h ago"
```

**CI Sublines:**
```php
private function formatSubline(array $item): string {
    $parts = [];

    // Location
    if (!empty($item['location'])) {
        $parts[] = '📍 ' . $item['location'];
    }

    // Asset number
    if (!empty($item['asset_number'])) {
        $parts[] = '🏷️ ' . $item['asset_number'];
    }

    // Brand/Model (for hardware)
    if (!empty($item['brand']) && !empty($item['model'])) {
        $parts[] = $item['brand'] . ' ' . $item['model'];
    }

    return implode(' • ', $parts);
}

// Example: "📍 Vienna Office • 🏷️ ASSET-001 • Dell Latitude 7420"
```

### Icon URLs

**Ticket Icons:**
```php
private function getIconUrl(array $item): string {
    $class = $item['class'];
    $status = strtolower($item['status'] ?? '');

    if ($class === 'UserRequest') {
        $isClosed = in_array($status, ['resolved', 'closed']);
        return $this->urlGenerator->imagePath(
            'integration_itop',
            $isClosed ? 'user-request-closed.svg' : 'user-request.svg'
        );
    }

    if ($class === 'Incident') {
        return $this->urlGenerator->imagePath('integration_itop', 'incident.svg');
    }

    return $this->urlGenerator->imagePath('integration_itop', 'ticket.svg');
}
```

**CI Icons:**
```php
private function getIconUrl(array $item): string {
    $iconMap = [
        'PC' => 'PC.svg',
        'Phone' => 'Phone.svg',
        'IPPhone' => 'Phone.svg',
        'MobilePhone' => 'Phone.svg',
        'Tablet' => 'Tablet.svg',
        'Printer' => 'Printer.svg',
        'Peripheral' => 'Peripheral.svg',
        'PCSoftware' => 'PCSoftware.svg',
        'OtherSoftware' => 'Software.svg',
        'WebApplication' => 'WebApplication.svg',
    ];

    $icon = $iconMap[$item['class']] ?? 'FunctionalCI.svg';
    return $this->urlGenerator->imagePath('integration_itop', $icon);
}
```

## Link Insertion Format

### Markdown Links

**Format:** `[Display Text](URL)`

**Ticket Link:**
```markdown
[R-000123 Laptop running slow](http://itop.example.com/pages/UI.php?operation=details&class=UserRequest&id=123)
```

**CI Link:**
```markdown
[LAPTOP-001](http://itop.example.com/pages/UI.php?operation=details&class=PC&id=5)
```

**Implementation:**
```php
private function formatValue(array $item): string {
    $displayText = $item['ref'] ?? $item['name'];
    if (!empty($item['title'])) {
        $displayText .= ' ' . $item['title'];
    }

    $url = $this->buildItopUrl($item['class'], $item['id']);

    return '[' . $displayText . '](' . $url . ')';
}

private function buildItopUrl(string $class, int $id): string {
    $itopUrl = $this->config->getAppValue('integration_itop', 'admin_instance_url');
    return $itopUrl . '/pages/UI.php?operation=details&class=' . $class . '&id=' . $id;
}
```

### Plain Text Links (Talk)

**Format:** Direct URL (Talk auto-generates preview)

```
http://itop.example.com/pages/UI.php?operation=details&class=UserRequest&id=123
```

**Implementation:**
```php
private function formatValueForTalk(array $item): string {
    // Talk doesn't need markdown - just the URL
    return $this->buildItopUrl($item['class'], $item['id']);
}
```

### Rich Object Format (Future)

**Nextcloud Rich Object Format:**
```php
return [
    'type' => 'integration_itop_ticket',
    'id' => $item['id'],
    'name' => $item['ref'] . ' ' . $item['title'],
    'link' => $this->buildItopUrl($item['class'], $item['id'])
];
```

**Rendering:** Nextcloud automatically renders rich preview widget

## Access Control Rules

### User Configuration Check

**Requirement:** User must have configured personal token

```php
public function search(string $query): DataResponse {
    if ($this->userId === null) {
        return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
    }

    $personId = $this->config->getUserValue($this->userId, 'integration_itop', 'person_id', '');

    if ($personId === '') {
        return new DataResponse(
            ['error' => 'Not configured', 'hint' => 'Please configure iTop integration in settings'],
            Http::STATUS_PRECONDITION_FAILED
        );
    }

    // Proceed with search
}
```

### Portal User Filtering

**Apply same filters as Unified Search:**

```php
private function search(string $userId, string $query): array {
    $personId = $this->getPersonId($userId);
    $isPortalOnly = $this->profileService->isPortalOnly($userId);

    if ($isPortalOnly) {
        // Filter by contacts for tickets
        $ticketQuery = "SELECT UserRequest WHERE "
                     . "title LIKE '%$query%' "
                     . "AND caller_id = $personId "
                     . "LIMIT 10";

        // Filter by contacts for CIs
        $ciQuery = "SELECT PC WHERE "
                 . "name LIKE '%$query%' "
                 . "AND contacts_list MATCHES Person WHERE id = $personId "
                 . "LIMIT 10";
    } else {
        // Power users: full search
        $ticketQuery = "SELECT UserRequest WHERE title LIKE '%$query%' LIMIT 10";
        $ciQuery = "SELECT PC WHERE name LIKE '%$query%' LIMIT 10";
    }

    return array_merge(
        $this->itopAPIService->query($ticketQuery),
        $this->itopAPIService->query($ciQuery)
    );
}
```

### Result Limit Enforcement

**Maximum Suggestions:** 10 per request

```php
private const MAX_PICKER_RESULTS = 10;

public function search(string $query): array {
    $results = $this->itopAPIService->search($userId, $query, 0, self::MAX_PICKER_RESULTS);

    // Ensure we don't exceed limit
    return array_slice($results, 0, self::MAX_PICKER_RESULTS);
}
```

**Rationale:**
- Picker UI has limited space
- More results = slower rendering
- User can refine search if needed

### Class Filtering (Admin Configurable)

**Admin Settings:**
```php
$enabledClasses = $this->config->getAppValue(
    'integration_itop',
    'enabled_ci_classes',
    json_encode(['PC', 'Phone', 'Tablet', 'Printer'])
);

$enabledClasses = json_decode($enabledClasses, true);
```

**Filter Results:**
```php
private function search(string $userId, string $query): array {
    $enabledClasses = $this->getEnabledClasses();

    $classFilter = "finalclass IN ('" . implode("','", $enabledClasses) . "')";

    $query = "SELECT FunctionalCI WHERE "
           . $classFilter . " AND name LIKE '%$query%' "
           . "LIMIT 10";

    return $this->itopAPIService->query($query);
}
```

## Frontend Implementation

### Vue Component (Text App Integration)

**Component:** `src/components/ItopSmartPicker.vue`

```vue
<template>
  <div class="itop-smart-picker">
    <input
      v-model="searchQuery"
      type="text"
      placeholder="Search tickets or CIs..."
      @input="onSearchInput"
    >
    <ul v-if="suggestions.length > 0" class="suggestions">
      <li
        v-for="suggestion in suggestions"
        :key="suggestion.id"
        @click="selectSuggestion(suggestion)">
        <img :src="suggestion.icon" class="icon">
        <div class="content">
          <div class="label">{{ suggestion.label }}</div>
          <div class="subline">{{ suggestion.subline }}</div>
        </div>
      </li>
    </ul>
  </div>
</template>

<script>
import { debounce } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
  name: 'ItopSmartPicker',

  data() {
    return {
      searchQuery: '',
      suggestions: [],
      loading: false,
    }
  },

  methods: {
    onSearchInput: debounce(async function() {
      if (this.searchQuery.length < 2) {
        this.suggestions = []
        return
      }

      this.loading = true
      try {
        const response = await axios.post(
          generateUrl('/apps/integration_itop/api/v1/picker/search'),
          { query: this.searchQuery }
        )
        this.suggestions = response.data
      } catch (error) {
        console.error('Picker search error:', error)
        this.suggestions = []
      } finally {
        this.loading = false
      }
    }, 300),

    selectSuggestion(suggestion) {
      this.$emit('select', suggestion.value)
      this.searchQuery = ''
      this.suggestions = []
    },
  },
}
</script>

<style scoped lang="scss">
.itop-smart-picker {
  position: relative;

  .suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 300px;
    overflow-y: auto;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    z-index: 1000;

    li {
      display: flex;
      align-items: center;
      padding: 8px 12px;
      cursor: pointer;

      &:hover {
        background: var(--color-background-hover);
      }

      .icon {
        width: 32px;
        height: 32px;
        margin-right: 12px;
      }

      .content {
        flex: 1;
        min-width: 0;

        .label {
          font-weight: 500;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .subline {
          font-size: 12px;
          color: var(--color-text-maxcontrast);
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }
    }
  }
}
</style>
```

### Backend API Endpoint

**Controller:** `lib/Controller/PickerController.php`

```php
<?php

namespace OCA\Itop\Controller;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\ItopAPIService;
use OCA\Itop\Service\ProfileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class PickerController extends Controller {

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private ItopAPIService $itopAPIService,
        private ProfileService $profileService,
        private ?string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function search(string $query): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $personId = $this->config->getUserValue($this->userId, Application::APP_ID, 'person_id', '');
        if ($personId === '') {
            return new DataResponse(['error' => 'Not configured'], Http::STATUS_PRECONDITION_FAILED);
        }

        $query = trim($query);
        if (strlen($query) < 2) {
            return new DataResponse([]);
        }

        try {
            $results = $this->itopAPIService->search($this->userId, $query, 0, 10);

            $suggestions = array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'label' => $this->formatLabel($item),
                    'subline' => $this->formatSubline($item),
                    'icon' => $this->getIconUrl($item),
                    'value' => $this->formatValue($item),
                ];
            }, $results);

            return new DataResponse($suggestions);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Search failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function formatLabel(array $item): string {
        // Implementation as shown above
    }

    private function formatSubline(array $item): string {
        // Implementation as shown above
    }

    private function getIconUrl(array $item): string {
        // Implementation as shown above
    }

    private function formatValue(array $item): string {
        // Implementation as shown above
    }
}
```

## Performance Considerations

### Optimization Strategies

**1. Prefix Matching (Faster than LIKE %term%):**
```sql
-- Slower
SELECT PC WHERE name LIKE '%laptop%'

-- Faster (but less flexible)
SELECT PC WHERE name LIKE 'laptop%'
```

**2. Limit Early in Query:**
```sql
-- Good
SELECT PC WHERE name LIKE '%laptop%' LIMIT 10

-- Better (if iTop supports subqueries)
SELECT * FROM (SELECT * FROM PC LIMIT 100) WHERE name LIKE '%laptop%' LIMIT 10
```

**3. Field Selection:**
```php
// Minimal fields for picker
$outputFields = 'id,name,ref,title,status,priority,class,last_update';
```

**4. Parallel Class Queries:**
```php
// Search tickets and CIs in parallel
$promises = [
    $this->searchTickets($query),
    $this->searchCIs($query)
];

$results = Promise::all($promises)->wait();
```

### Caching Strategy

**Cache Duration:** 60 seconds

**Cache Invalidation:**
- Time-based (TTL)
- Manual (when user updates settings)

**Cache Key Pattern:**
```
picker_search_{userId}_{md5(query)}
```

## Error Handling

### Network Errors

**Graceful Degradation:**
```php
try {
    $results = $this->itopAPIService->search($userId, $query, 0, 10);
} catch (\Exception $e) {
    $this->logger->error('Picker search failed', [
        'error' => $e->getMessage(),
        'user' => $userId
    ]);
    return []; // Empty suggestions, no error UI
}
```

### Rate Limit Exceeded

**Response:**
```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/json

{
  "error": "Rate limit exceeded",
  "retry_after": 45
}
```

**Client Handling:**
```javascript
if (error.response.status === 429) {
  this.$emit('rate-limit', error.response.data.retry_after)
  // Show warning: "Too many requests, please wait 45 seconds"
}
```

### User Not Configured

**Response:**
```http
HTTP/1.1 412 Precondition Failed
Content-Type: application/json

{
  "error": "Not configured",
  "hint": "Please configure iTop integration in settings"
}
```

**Client Handling:**
```javascript
if (error.response.status === 412) {
  // Show settings link in picker UI
  this.showSettingsHint = true
}
```

## Testing

### Manual Testing

**Test Case 1: Basic Autocomplete**
1. Open Text app
2. Type `/itop laptop`
3. Verify suggestions appear
4. Select suggestion
5. Verify link inserted with correct format

**Test Case 2: Debouncing**
1. Type quickly: `l-a-p-t-o-p`
2. Verify only one API request sent (after 300ms pause)
3. Check browser network tab

**Test Case 3: Rate Limiting**
1. Make 25 requests in 30 seconds
2. Verify 429 error on request 21+
3. Wait 60 seconds
4. Verify requests work again

### Automated Tests

**Unit Tests:**
```php
class PickerControllerTest extends TestCase {
    public function testSearchReturnsResults() {
        $controller = new PickerController(...);
        $response = $controller->search('laptop');

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertIsArray($response->getData());
    }

    public function testSearchRequiresMinLength() {
        $response = $controller->search('l'); // 1 char

        $this->assertEquals([], $response->getData());
    }

    public function testRateLimitEnforced() {
        for ($i = 0; $i < 25; $i++) {
            $response = $controller->search('test');
        }

        $this->assertEquals(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
    }
}
```

**Integration Tests:**
```javascript
describe('Smart Picker', () => {
  it('debounces search input', async () => {
    const spy = jest.spyOn(axios, 'post')

    wrapper.vm.searchQuery = 'l'
    await wait(100)
    wrapper.vm.searchQuery = 'la'
    await wait(100)
    wrapper.vm.searchQuery = 'lap'
    await wait(400) // > debounce delay

    expect(spy).toHaveBeenCalledTimes(1)
  })
})
```

## Future Enhancements

### Phase 2 Additions

- [ ] CI picker support
- [ ] Class-specific trigger commands (`/pc`, `/ticket`)
- [ ] Recent items cache
- [ ] Favorites/pinned items

### Phase 3+ Features

- [ ] Template insertion (ticket templates, CI templates)
- [ ] Bulk link insertion (select multiple items)
- [ ] Smart compose (AI-powered suggestions)
- [ ] Contextual suggestions (based on current document)
- [ ] Keyboard shortcuts (arrow keys, Enter, Esc)

## References

- **API Integration:** [itop-api.md](itop-api.md)
- **Security:** [security-auth.md](security-auth.md)
- **Unified Search:** [unified-search.md](unified-search.md)
- **Rich Preview:** [rich-preview.md](rich-preview.md)
- **Nextcloud Autocomplete API:** https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/autocomplete.html
