# iTop Newsroom Mirroring - Implementation Plan

## Overview

This document outlines the implementation plan for mirroring iTop's Newsroom notifications to Nextcloud. Users will see their iTop newsroom items as Nextcloud notifications with a "Mark as read" action that updates the read status in iTop bidirectionally.

## Background & Research

### iTop Newsroom Feature
- **Class**: `EventNotificationNewsroom` - Stores newsroom notifications in iTop
- **Trigger**: `ActionNewsroom` creates newsroom items when events occur
- **Per-User**: Each notification tied to `contact_id` (Person)
- **Read Status**: Tracked with `read` field (yes/no) and `read_date`

### iTop Webhook Capabilities
**Investigation Result**: Despite documentation mentioning "Outgoing webhooks", the feature is **not yet implemented** in iTop 3.3.0-dev:
- `ActionWebhook` class mentioned in dictionaries but no implementation exists
- No webhook action available for triggers
- **Conclusion**: Must use polling approach

### Chosen Approach: Polling with Deduplication
Since webhooks aren't available, we'll poll iTop REST API periodically and use smart deduplication to avoid duplicate notifications.

## Architecture Design

### Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    Every 5 Minutes                          │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ BackgroundJob: NewsroomPollJob                             │
│  • For each connected user (has person_id)                  │
│  • Get last_newsroom_id from user settings                  │
│  • Query iTop: SELECT EventNotificationNewsroom            │
│    WHERE contact_id = :person_id                            │
│      AND id > :last_id                                      │
│      AND read = 'no'                                        │
│    ORDER BY id ASC                                          │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ For Each New Newsroom Item                                  │
│  • Check if Nextcloud notification already exists           │
│    (by object_id = itop_newsroom_id)                        │
│  • Skip if exists (deduplication)                           │
│  • Create Nextcloud notification:                           │
│    - app: integration_itop                                  │
│    - object: ('newsroom', itop_id)                          │
│    - subject: newsroom title                                │
│    - message: newsroom message                              │
│    - link: newsroom url                                     │
│    - action: "Mark as read" button                          │
│  • Update last_newsroom_id to highest processed             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ User Clicks "Mark as Read" in Nextcloud                     │
│  • Calls: POST /action/newsroom/mark-read                   │
│  • Parameters: nid (newsroom_id), sig (signature)           │
│  • Validates user owns this notification                    │
│  • Calls iTop API: core/update on EventNotificationNewsroom│
│    SET read = 'yes', read_date = NOW()                      │
│  • On success: Dismiss Nextcloud notification               │
│  • On failure: Keep notification, show error                │
└─────────────────────────────────────────────────────────────┘
```

### iTop Data Structure

From REST API response (`EventNotificationNewsroom`):
```json
{
  "key": "3",  // Used as object_id in Nextcloud notification
  "fields": {
    "title": "R-000012",
    "message": "You have been mentioned by My first name My last name",
    "date": "2025-11-03 09:21:55",
    "icon": { "data": "base64...", "mimetype": "image/svg+xml" },
    "priority": "3",  // 1=Critical, 2=Urgent, 3=Important, 4=Standard
    "url": "http://itop.../pages/UI.php?operation=details&class=UserRequest&id=12",
    "read": "no",
    "read_date": "",
    "contact_id": "1",
    "trigger_id": "2",
    "action_id": "2"
  }
}
```

## Deduplication Strategy (Hybrid Approach)

### Method 1: Track Last Processed ID (Primary)
**Storage**: Per-user setting `last_newsroom_id`
**Purpose**: Efficient querying - only fetch new items since last poll

```php
$lastProcessedId = (int) $config->getUserValue(
    $userId, 
    'integration_itop', 
    'last_newsroom_id', 
    '0'
);

// OQL Query
$oql = "SELECT EventNotificationNewsroom 
        WHERE contact_id = :person_id 
          AND id > :last_id 
          AND read = 'no'
        ORDER BY id ASC";
```

### Method 2: Check Existing Notifications (Secondary)
**Purpose**: Prevent duplicates if job fails/retries

```php
// Before creating notification
$existingNotifications = // Query Nextcloud's notification table
    WHERE user_id = $userId
      AND app = 'integration_itop'
      AND object_type = 'newsroom'
      AND object_id = $itopNewsroomId;

if (!empty($existingNotifications)) {
    continue; // Skip, already created
}
```

### Method 3: Update Tracking After Processing
```php
// After all items processed
$config->setUserValue(
    $userId, 
    'integration_itop', 
    'last_newsroom_id', 
    (string) $highestIdProcessed
);
```

### Edge Case Handling

| Scenario | Behavior | Acceptable? |
|----------|----------|-------------|
| User dismisses in Nextcloud, not read in iTop | Won't recreate (ID tracked) | ✅ Good |
| Marked read in iTop directly | Won't appear in query (`read='no'`) | ✅ Good |
| Job fails midway | Next run resumes from last ID | ✅ No gaps |
| User has both email & newsroom actions | Two separate notifications in Nextcloud | ✅ Expected |
| iTop ID resets/wraps | Might miss items (rare) | ⚠️ Acceptable risk |

## Technical Implementation

### Component Overview

```
lib/BackgroundJob/NewsroomPollJob.php    - Timed job (5 min interval)
lib/Controller/NewsroomController.php    - Mark-as-read endpoint
lib/Service/NewsroomService.php          - Business logic
lib/Notification/Notifier.php            - Format notifications (enhance existing)
appinfo/info.xml                         - Register background job
appinfo/routes.php                       - Register mark-read route
```

### 1. Background Job: NewsroomPollJob.php

**Location**: `lib/BackgroundJob/NewsroomPollJob.php`

**Purpose**: Poll iTop every 5 minutes for new newsroom items

```php
<?php
namespace OCA\Itop\BackgroundJob;

use OCA\Itop\Service\NewsroomService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

class NewsroomPollJob extends TimedJob {
    
    public function __construct(
        ITimeFactory $time,
        private NewsroomService $newsroomService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // Poll every 5 minutes
        $this->setInterval(5 * 60);
        // Not time-sensitive (can be delayed during high load)
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }
    
    protected function run($argument): void {
        try {
            $processedCount = $this->newsroomService->pollAndCreateNotifications();
            
            if ($processedCount > 0) {
                $this->logger->info(
                    "Newsroom poll completed: {count} new notifications created",
                    ['app' => 'integration_itop', 'count' => $processedCount]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Newsroom poll failed: {message}',
                ['app' => 'integration_itop', 'exception' => $e]
            );
        }
    }
}
```

### 2. Newsroom Service: NewsroomService.php

**Location**: `lib/Service/NewsroomService.php`

**Purpose**: Core business logic for fetching, deduplicating, and creating notifications

**Key Methods**:
```php
class NewsroomService {
    
    /**
     * Poll iTop for all users and create notifications
     * @return int Number of notifications created
     */
    public function pollAndCreateNotifications(): int;
    
    /**
     * Get connected users (have person_id configured)
     * @return array [['user_id' => 'john', 'person_id' => '42'], ...]
     */
    private function getConnectedUsers(): array;
    
    /**
     * Fetch unread newsroom items from iTop for a user
     * @param string $userId
     * @param string $personId
     * @return array Newsroom items from iTop
     */
    private function fetchNewsroomItems(string $userId, string $personId): array;
    
    /**
     * Check if notification already exists in Nextcloud
     * @param string $userId
     * @param string $newsroomId iTop EventNotificationNewsroom ID
     * @return bool
     */
    private function notificationExists(string $userId, string $newsroomId): bool;
    
    /**
     * Create Nextcloud notification from iTop newsroom item
     * @param string $userId
     * @param array $newsroomItem
     * @return void
     */
    private function createNotification(string $userId, array $newsroomItem): void;
    
    /**
     * Generate signed token for mark-as-read action
     * @param string $newsroomId
     * @param string $userId
     * @param int $expiresIn Seconds until expiry (default: 30 days)
     * @return string
     */
    private function generateActionToken(
        string $newsroomId, 
        string $userId, 
        int $expiresIn = 2592000
    ): string;
}
```

**Implementation Notes**:
- Use `IUserManager` to get all users
- Filter to those with `person_id` user setting
- Use `ItopAPIService` for REST calls
- Use `INotificationManager` to create notifications
- Use `IConfig` for tracking `last_newsroom_id`

### 3. Mark-as-Read Controller

**Location**: `lib/Controller/NewsroomController.php`

**Purpose**: Handle user clicking "Mark as read"

```php
<?php
namespace OCA\Itop\Controller;

use OCA\Itop\AppInfo\Application;
use OCA\Itop\Service\NewsroomService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class NewsroomController extends Controller {
    
    public function __construct(
        string $appName,
        IRequest $request,
        private NewsroomService $newsroomService,
        private ?string $userId
    ) {
        parent::__construct($appName, $request);
    }
    
    /**
     * Mark newsroom item as read in iTop and dismiss Nextcloud notification
     * 
     * @NoAdminRequired
     * 
     * @param string $nid Newsroom ID (EventNotificationNewsroom key)
     * @param string $sig Signature for verification
     * @return DataResponse
     */
    public function markAsRead(string $nid, string $sig): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(
                ['error' => 'Unauthorized'], 
                Http::STATUS_UNAUTHORIZED
            );
        }
        
        try {
            // Validate signature and mark as read
            $result = $this->newsroomService->markAsRead(
                $this->userId, 
                $nid, 
                $sig
            );
            
            if ($result['success']) {
                return new DataResponse(['status' => 'ok']);
            } else {
                return new DataResponse(
                    ['error' => $result['error']], 
                    Http::STATUS_BAD_REQUEST
                );
            }
            
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()], 
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
```

### 4. Update Notifier for Newsroom

**Location**: `lib/Notification/Notifier.php` (existing file)

**Add new case** to `prepare()` method:

```php
public function prepare(INotification $notification, string $languageCode): INotification {
    if ($notification->getApp() !== 'integration_itop') {
        throw new InvalidArgumentException();
    }
    
    $l = $this->factory->get('integration_itop', $languageCode);
    
    switch ($notification->getSubject()) {
        case 'new_open_tickets':
            // ... existing code ...
            
        case 'newsroom_item':  // NEW CASE
            $params = $notification->getSubjectParameters();
            $title = $params['title'] ?? 'iTop Notification';
            $message = $params['message'] ?? '';
            $priority = (int) ($params['priority'] ?? 4);
            
            // Set icon based on priority
            $icon = match($priority) {
                1 => '🔴', // Critical
                2 => '🟠', // Urgent
                3 => '🟡', // Important
                default => '🔵', // Standard
            };
            
            $notification->setParsedSubject($icon . ' ' . $title);
            $notification->setParsedMessage($message);
            $notification->setIcon($this->url->getAbsoluteURL(
                $this->url->imagePath(Application::APP_ID, 'app.svg')
            ));
            
            // Link to iTop newsroom item
            if (!empty($params['url'])) {
                $notification->setLink($params['url']);
            }
            
            return $notification;
            
        default:
            throw new InvalidArgumentException();
    }
}
```

### 5. ItopAPIService Enhancement

**Location**: `lib/Service/ItopAPIService.php` (existing file)

**Add new methods**:

```php
/**
 * Fetch unread newsroom items for a contact
 * 
 * @param string $userId Nextcloud user ID
 * @param string $personId iTop Person ID
 * @param int $sinceId Only fetch items with id > sinceId
 * @return array Array of newsroom items
 */
public function getNewsroomItems(
    string $userId, 
    string $personId, 
    int $sinceId = 0
): array {
    $oql = "SELECT EventNotificationNewsroom 
            WHERE contact_id = :person_id 
              AND id > :last_id 
              AND read = 'no'
            ORDER BY id ASC";
    
    $params = [
        'person_id' => $personId,
        'last_id' => $sinceId
    ];
    
    $result = $this->makeItopRequest('core/get', [
        'class' => 'EventNotificationNewsroom',
        'key' => $oql,
        'output_fields' => '*'
    ], $params);
    
    if ($result['code'] !== 0) {
        throw new \Exception('iTop API error: ' . $result['message']);
    }
    
    return $result['objects'] ?? [];
}

/**
 * Mark a newsroom item as read in iTop
 * 
 * @param string $userId Nextcloud user ID
 * @param string $newsroomId EventNotificationNewsroom ID
 * @return bool Success
 */
public function markNewsroomAsRead(string $userId, string $newsroomId): bool {
    $credentials = $this->getCredentials($userId);
    
    if (!$credentials) {
        throw new \Exception('No iTop credentials configured');
    }
    
    $result = $this->makeItopRequest('core/update', [
        'class' => 'EventNotificationNewsroom',
        'key' => $newsroomId,
        'fields' => [
            'read' => 'yes'
            // read_date is auto-set by iTop
        ],
        'output_fields' => 'id'
    ]);
    
    return $result['code'] === 0;
}
```

### 6. Routes Configuration

**Location**: `appinfo/routes.php`

**Add route**:

```php
[
    'name' => 'newsroom#markAsRead',
    'url' => '/newsroom/mark-read',
    'verb' => 'POST'
],
```

### 7. Register Background Job

**Location**: `appinfo/info.xml`

**Add to `<background-jobs>` section**:

```xml
<background-jobs>
    <job>OCA\Itop\BackgroundJob\CheckOpenTickets</job>
    <job>OCA\Itop\BackgroundJob\NewsroomPollJob</job>
</background-jobs>
```

## Security Considerations

### Action Token Generation

Use HMAC-SHA256 to sign the mark-as-read action URL:

```php
private function generateActionToken(
    string $newsroomId, 
    string $userId, 
    int $expiresIn = 2592000  // 30 days default
): string {
    $expires = time() + $expiresIn;
    $message = "{$newsroomId}:{$userId}:{$expires}";
    
    $secret = $this->config->getAppValue(
        Application::APP_ID, 
        'newsroom_secret', 
        bin2hex(random_bytes(32))
    );
    
    $signature = hash_hmac('sha256', $message, $secret);
    
    return base64_encode("{$signature}:{$expires}");
}

private function validateActionToken(
    string $token, 
    string $newsroomId, 
    string $userId
): bool {
    $decoded = base64_decode($token);
    [$signature, $expires] = explode(':', $decoded, 2);
    
    // Check expiry
    if (time() > (int) $expires) {
        return false;
    }
    
    // Reconstruct message
    $message = "{$newsroomId}:{$userId}:{$expires}";
    $secret = $this->config->getAppValue(
        Application::APP_ID, 
        'newsroom_secret'
    );
    
    $expectedSignature = hash_hmac('sha256', $message, $secret);
    
    return hash_equals($expectedSignature, $signature);
}
```

### Rate Limiting

```php
// In NewsroomService
private function checkRateLimit(string $userId): bool {
    $cache = $this->cacheFactory->createDistributed('itop_newsroom');
    $key = "poll_count_{$userId}";
    
    $count = (int) $cache->get($key) ?? 0;
    
    if ($count > 100) {  // Max 100 polls per hour
        $this->logger->warning(
            'Rate limit exceeded for user {user}',
            ['app' => 'integration_itop', 'user' => $userId]
        );
        return false;
    }
    
    $cache->set($key, $count + 1, 3600);  // 1 hour TTL
    return true;
}
```

## Testing Strategy

### Unit Tests

**Test File**: `tests/Unit/Service/NewsroomServiceTest.php`

**Test Cases**:
1. `testPollAndCreateNotifications_CreatesNewNotifications()`
2. `testPollAndCreateNotifications_SkipsDuplicates()`
3. `testPollAndCreateNotifications_UpdatesLastProcessedId()`
4. `testMarkAsRead_ValidToken_UpdatesiTop()`
5. `testMarkAsRead_ExpiredToken_ReturnsError()`
6. `testGenerateActionToken_CreatesValidToken()`

### Integration Tests

**Manual Testing Checklist**:
- [ ] Create newsroom item in iTop via ActionNewsroom trigger
- [ ] Wait 5 minutes or trigger job manually: `occ background:job:execute 'OCA\\Itop\\BackgroundJob\\NewsroomPollJob'`
- [ ] Verify Nextcloud notification appears
- [ ] Click "Mark as read" in Nextcloud
- [ ] Verify notification dismissed in Nextcloud
- [ ] Verify `read='yes'` in iTop (check newsroom or EventNotificationNewsroom table)
- [ ] Create another newsroom item with same content - should create new notification
- [ ] Verify no duplicate notifications after multiple job runs

### Edge Case Tests

1. **Missing person_id**: User without person_id shouldn't be processed
2. **Invalid iTop credentials**: Should log error, skip user, continue to next
3. **iTop API timeout**: Should log error, retry next poll
4. **Notification already dismissed**: Shouldn't cause errors
5. **Large number of items**: Should handle 100+ newsroom items efficiently

## Performance Considerations

### Optimization Strategies

1. **Batch Processing**: Process up to 50 users per job run, continue in next run
2. **Query Optimization**: Use indexed `id` and `contact_id` fields
3. **Caching**: Cache iTop connection status per user (5 min TTL)
4. **Incremental Processing**: Only query items since last_newsroom_id

### Monitoring Metrics

Track in logs:
- Job execution time
- Number of users processed
- Number of notifications created
- iTop API errors
- Mark-as-read success/failure rate

```php
$this->logger->info('Newsroom poll stats', [
    'app' => 'integration_itop',
    'duration_ms' => $duration,
    'users_processed' => $userCount,
    'notifications_created' => $notificationCount,
    'api_errors' => $errorCount
]);
```

## Implementation Phases

### Phase 1: Core Polling Infrastructure ✅
**Priority**: High
**Estimated Time**: 4-6 hours

**Tasks**:
1. [ ] Create `NewsroomService.php` with core logic
2. [ ] Create `NewsroomPollJob.php` background job
3. [ ] Add `getNewsroomItems()` to `ItopAPIService.php`
4. [ ] Register job in `info.xml`
5. [ ] Add unit tests for service layer
6. [ ] Manual testing with test iTop instance

**Acceptance Criteria**:
- Job runs every 5 minutes
- Fetches newsroom items from iTop
- Creates Nextcloud notifications
- No duplicate notifications created

### Phase 2: Mark-as-Read Functionality ✅
**Priority**: High
**Estimated Time**: 3-4 hours

**Tasks**:
1. [ ] Create `NewsroomController.php` with `markAsRead()` endpoint
2. [ ] Add `markNewsroomAsRead()` to `ItopAPIService.php`
3. [ ] Implement token generation and validation
4. [ ] Add route in `routes.php`
5. [ ] Update `Notifier.php` to add "Mark as read" action
6. [ ] Test bidirectional sync

**Acceptance Criteria**:
- User can click "Mark as read" in Nextcloud
- Notification dismissed in Nextcloud
- Status updated to `read='yes'` in iTop
- Invalid/expired tokens rejected

### Phase 3: Enhanced Notifier & UX ✅
**Priority**: Medium
**Estimated Time**: 2-3 hours

**Tasks**:
1. [ ] Add `newsroom_item` case to `Notifier.php`
2. [ ] Format notifications with priority icons
3. [ ] Add rich subjects with placeholders
4. [ ] Handle icon display from iTop
5. [ ] Add proper error messages
6. [ ] Test with different notification types

**Acceptance Criteria**:
- Notifications display title, message, and priority
- Links work correctly
- Icons render properly
- Different priorities visually distinguishable

### Phase 4: Polish & Documentation ✅
**Priority**: Low
**Estimated Time**: 2 hours

**Tasks**:
1. [ ] Add admin setting for poll interval (optional)
2. [ ] Add user setting to disable newsroom sync (optional)
3. [ ] Write user documentation
4. [ ] Write admin documentation (iTop trigger setup)
5. [ ] Add logging for troubleshooting
6. [ ] Performance testing with large datasets

**Acceptance Criteria**:
- Comprehensive documentation exists
- Logging helpful for debugging
- No performance issues with 1000+ newsroom items

## Admin Configuration

### iTop Setup (User Documentation)

**Creating ActionNewsroom Trigger**:

1. Navigate to **Admin Tools** → **Notifications** → **Actions**
2. Click **New** → **ActionNewsroom**
3. Configure:
   - **Name**: "Notify via Nextcloud"
   - **Status**: Enabled
   - **Recipients**: `SELECT Person WHERE id = :this->agent_id` (or custom OQL)
   - **Title**: `$this->friendlyname$`
   - **Message**: Custom message with placeholders
   - **Priority**: 3 (Important)
4. Click **Apply**
5. Go to **Triggers** tab and link to desired trigger (e.g., TriggerOnReachingState)

**Example Use Cases**:
- Ticket assigned to agent
- Mentioned in case log
- SLA threshold reached
- Change request approved

### Nextcloud Setup (Admin Documentation)

**Checking Background Jobs**:
```bash
# List jobs
occ background-job:list | grep Newsroom

# Execute manually for testing
occ background:job:execute 'OCA\Itop\BackgroundJob\NewsroomPollJob'

# Check last run
occ config:app:get integration_itop newsroom_last_run
```

**Monitoring**:
```bash
# Check logs
tail -f /var/www/nextcloud/data/nextcloud.log | grep integration_itop

# User-specific tracking
occ config:user:get <userid> integration_itop last_newsroom_id
```

## Future Enhancements (Out of Scope)

### Real-Time Webhooks (When Available)
Once iTop implements `ActionWebhook`:
1. Keep polling as fallback
2. Add webhook endpoint: `POST /apps/integration_itop/webhook/newsroom`
3. Implement signature validation
4. Process webhook payload immediately
5. Disable polling per user if webhook active

### Notification Preferences
Allow users to configure:
- Which priority levels to receive (e.g., only Critical/Urgent)
- Notification frequency (immediate vs. digest)
- Mute specific newsroom types

### Bulk Actions
Add notification action to "Mark all as read"

## Appendix

### iTop OQL Examples

**Get all unread newsroom for contact**:
```sql
SELECT EventNotificationNewsroom 
WHERE contact_id = 1 
  AND read = 'no'
```

**Get by ID range**:
```sql
SELECT EventNotificationNewsroom 
WHERE contact_id = 1 
  AND id > 5 
  AND read = 'no'
ORDER BY id ASC
```

**Get by date**:
```sql
SELECT EventNotificationNewsroom 
WHERE contact_id = 1 
  AND date > '2025-11-01 00:00:00'
  AND read = 'no'
```

### Nextcloud Notification API Reference

**Create notification**:
```php
$notification = $this->notificationManager->createNotification();
$notification->setApp('integration_itop')
    ->setUser($userId)
    ->setDateTime(new \DateTime($timestamp))
    ->setObject('newsroom', $newsroomId)
    ->setSubject('newsroom_item', [
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'url' => $url
    ]);

// Add action
$action = $notification->createAction();
$action->setLabel('mark_read')
    ->setLink('/apps/integration_itop/newsroom/mark-read?nid=' . $newsroomId . '&sig=' . $signature, 'POST');
$notification->addAction($action);

$this->notificationManager->notify($notification);
```

**Dismiss notification**:
```php
$notification = $this->notificationManager->createNotification();
$notification->setApp('integration_itop')
    ->setUser($userId)
    ->setObject('newsroom', $newsroomId);
    
$this->notificationManager->markProcessed($notification);
```

### Database Schema (No Changes Required)

This feature uses existing tables:
- `oc_notifications` - Nextcloud's notification table
- `oc_preferences` - For storing `last_newsroom_id` per user
- iTop's `priv_event_notification_newsroom` - Source data

No new tables needed!

## Conclusion

This implementation provides a robust, polling-based solution for mirroring iTop newsroom notifications to Nextcloud. The hybrid deduplication strategy ensures reliability, and the mark-as-read functionality provides full bidirectional sync.

**Estimated Total Implementation Time**: 11-15 hours

**Key Benefits**:
- ✅ No iTop code changes required
- ✅ Uses existing iTop newsroom infrastructure
- ✅ Full bidirectional sync (mark as read works)
- ✅ Smart deduplication prevents spam
- ✅ Scales to thousands of users
- ✅ Extensible for future webhook support
