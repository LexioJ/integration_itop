# iTop Notifications - Implementation Plan

## Overview

This document outlines the comprehensive implementation plan for a **smart notification system** for the iTop integration. The system provides **two independent notification tracks** (Portal Users and Agents/Fulfillers) with **minimal state storage** by leveraging iTop's built-in change tracking (`CMDBChangeOp`).

### Key Design Principles

1. **No duplicate notifications** - Event-time based detection using iTop's change log
2. **Minimal state storage** - Only timestamps, no per-ticket state
3. **Weekend-aware SLA warnings** - Friday: 72h, Saturday: 48h, otherwise: 24h window
4. **Escalating SLA alerts** - 24h → 12h → 4h → 1h before breach
5. **Granular user control** - Master toggle + per-notification-type preferences
6. **Admin-configurable intervals** - 5 minutes to 24 hours polling frequency
7. **Ongoing tickets only** - Filter by `operational_status='ongoing'`

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     Every 5 Minutes                             │
│   (TimedJob) CheckPortalTicketUpdates + CheckAgentTicketUpdates│
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Per-User Interval Check                                        │
│  • Skip if now - last_check < user_configured_interval         │
│  • Skip if disabled_portal/agent_notifications = 'all'         │
│  • Skip if no person_id                                        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Query Optimization Check                                       │
│  • Skip CaseLog query if 'agent_responded' disabled           │
│  • Skip Scalar query if all status/agent/resolved disabled    │
│  • Filter to operational_status='ongoing' (+ resolved if needed)│
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Query iTop for Changes Since Last Check                        │
│  • CMDBChangeOpSetAttributeScalar (if any scalar notif enabled)│
│  • CMDBChangeOpSetAttributeCaseLog (if agent_responded enabled)│
│  • Apply PHP-side timestamp filtering (OQL limitation)         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Detect & Classify Events                                       │
│  • Portal: status/agent_id changes, agent comments, resolved  │
│  • Agent: assignments, SLA warnings/breaches, priority, comments│
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Filter & Send Notifications                                    │
│  • Respect disabled_portal/agent_notifications array          │
│  • Skip if notification type in disabled array                 │
│  • Rate limit: max 20 notifications per user per run            │
│  • Use buildTicketUrl() for portal vs. agent UI routing         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Update last_check Timestamp                                    │
│  • notification_last_portal_check = time() (Unix timestamp)   │
│  • notification_last_agent_check = time() (Unix timestamp)    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Notification Types

**Config Value Format**: Short identifier without `notify_` prefix for storage efficiency

### Complete Notification Type Values

**Portal Notifications** (4 types - stored without prefix):
```
ticket_status_changed
agent_responded  
ticket_resolved
agent_assigned
```

**Agent Notifications** (8 types - stored without prefix):
```
ticket_assigned
ticket_reassigned
team_unassigned_new
ticket_tto_warning
ticket_ttr_warning
ticket_sla_breach
ticket_priority_critical
ticket_comment
```

**Note**: The `notify_` prefix is NOT stored in config values to reduce storage size. It's only used in PHP constant names for clarity.

### Portal User Notifications (4 types)

| Type | Config Value | Trigger | Detection Method |
|------|-------------|---------|------------------|
| **Ticket status changed** | `ticket_status_changed` | Status field changes (e.g., new→assigned) | CMDBChangeOp: `attcode='status'` |
| **Agent responded** | `agent_responded` | Public log entry added by agent | CMDBChangeOp: `attcode='public_log'` |
| **Ticket resolved** | `ticket_resolved` | Status becomes 'resolved' | CMDBChangeOp: `attcode='status'`, `newvalue='resolved'` |
| **Agent assigned changed** | `agent_assigned` | Agent assignment changes | CMDBChangeOp: `attcode='agent_id'` |

**Storage values**:
```php
Application::PORTAL_NOTIFICATION_TYPES = [
    'ticket_status_changed',
    'agent_responded',
    'ticket_resolved',
    'agent_assigned'
];
```

### Agent/Fulfiller Notifications (8 types)

| Type | Config Value | Trigger | Detection Method |
|------|-------------|---------|------------------|
| **Ticket assigned to me** | `ticket_assigned` | agent_id changes from NULL to me | CMDBChangeOp: `attcode='agent_id'`, `oldvalue IS NULL` |
| **Ticket reassigned to me** | `ticket_reassigned` | agent_id changes from other to me | CMDBChangeOp: `attcode='agent_id'`, `oldvalue != NULL` |
| **New unassigned in team** | `team_unassigned_new` | Ticket created in my team, no agent | CMDBChangeOp: ticket creation, `agent_id=NULL` |
| **TTO SLA warning** | `ticket_tto_warning` | Team ticket approaching TTO | Current state: deadline crossing (24h/12h/4h/1h) |
| **TTR SLA warning** | `ticket_ttr_warning` | My ticket approaching TTR | Current state: deadline crossing (24h/12h/4h/1h) |
| **SLA breach** | `ticket_sla_breach` | SLA exceeded | CMDBChangeOp: `attcode IN ('sla_tto_passed','sla_ttr_passed')` |
| **Priority critical** | `ticket_priority_critical` | Priority → 1 (critical) | CMDBChangeOp: `attcode='priority'`, `newvalue='1'` |
| **New comment** | `ticket_comment` | Public/private log entry | CMDBChangeOp: `attcode IN ('public_log','private_log')` |

**Storage values**:
```php
Application::AGENT_NOTIFICATION_TYPES = [
    'ticket_assigned',
    'ticket_reassigned',
    'team_unassigned_new',
    'ticket_tto_warning',
    'ticket_ttr_warning',
    'ticket_sla_breach',
    'ticket_priority_critical',
    'ticket_comment'
];
```

### Newsroom Mirroring (Opt-In)

| Type | Trigger | Detection Method | User Toggle |
|------|---------|------------------|-------------|
| **iTop Newsroom notifications** | New EventNotificationNewsroom items | Poll EventNotificationNewsroom class, track last_newsroom_id | `notify_newsroom_enabled` |

**Implementation**: See `docs/PLAN_NEWSROOM_MIRRORING.md` for full specification.

---

## Change Detection Strategy

### Using iTop's CMDBChangeOp (Minimal State)

Instead of storing complete ticket state in Nextcloud, we leverage iTop's **built-in change tracking**:

#### CMDBChangeOp Structure
```
CMDBChange (parent)
├── id
├── date (timestamp)
├── userinfo (who made the change)
└── user_id

CMDBChangeOpSetAttributeScalar (child)
├── objclass (e.g., "UserRequest", "Incident")
├── objkey (ticket ID)
├── attcode (field name: "agent_id", "status", "priority", etc.)
├── oldvalue
└── newvalue
```

#### Query Pattern
```sql
SELECT CMDBChangeOpSetAttributeScalar
WHERE change->date > :last_check_timestamp
  AND objclass IN ('UserRequest', 'Incident')
  AND objkey IN (SELECT id FROM Ticket WHERE <relevance criteria>)
  AND attcode IN ('agent_id', 'status', 'priority', 'sla_tto_passed', 'sla_ttr_passed')
ORDER BY change->date ASC
```

**Benefits**:
- ✅ No ticket state storage in Nextcloud
- ✅ Natural deduplication (each change is unique)
- ✅ Accurate old→new value transitions
- ✅ User attribution (who made the change)

---

## SLA Warning Detection (Crossing-Time Algorithm)

### Problem: Detect When Tickets Cross Warning Thresholds

Instead of storing "last warning level" per ticket, we use a **threshold crossing time** calculation:

### Algorithm

```
For each ticket with deadline:
  For each threshold (24h, 12h, 4h, 1h):
    crossing_time = deadline - threshold
    
    IF last_check < crossing_time <= now THEN
      → This threshold was crossed since last check
      → Send notification for this level
```

### Weekend-Aware Thresholds

**Base thresholds**: 24h, 12h, 4h, 1h

**Weekend expansion** (to catch Monday breaches on Friday):
- **Friday**: Expand 24h threshold to 72h (catches Mon/Tue breaches)
- **Saturday**: Expand 24h threshold to 48h (catches Sun/Mon breaches)
- **Other days**: Use standard thresholds

**Implementation**:
```php
$dayOfWeek = (int)date('N', $now); // 1=Mon, 5=Fri, 6=Sat, 7=Sun

$thresholds = [24*3600, 12*3600, 4*3600, 1*3600]; // seconds

if ($dayOfWeek === 5) {
    // Friday: expand 24h to 72h
    $thresholds[0] = 72 * 3600;
} elseif ($dayOfWeek === 6) {
    // Saturday: expand 24h to 48h
    $thresholds[0] = 48 * 3600;
}

foreach ($myTickets as $ticket) {
    $deadline = strtotime($ticket['ttr_escalation_deadline']);
    
    foreach ($thresholds as $threshold) {
        $crossingTime = $deadline - $threshold;
        
        if ($lastCheck < $crossingTime && $crossingTime <= $now) {
            // Send notification for this threshold level
            notify($ticket, $threshold / 3600); // hours
        }
    }
}
```

**Deduplication**: If multiple thresholds crossed in same window (e.g., long downtime), **emit only the most urgent** (smallest threshold).

---

## Case Log Change Detection

### Challenge: Detect New Comments Without Storing Entry Counts

iTop stores case logs (`public_log`, `private_log`) as special `AttributeCaseLog` objects with entries.

#### CMDBChangeOpSetAttributeCaseLog

When a log entry is added, iTop creates a `CMDBChangeOpSetAttributeCaseLog` operation.

**Detection**:
```sql
SELECT CMDBChangeOpSetAttributeCaseLog
WHERE change->date > :last_check
  AND objclass IN ('UserRequest', 'Incident')
  AND objkey IN (my_relevant_tickets)
  AND attcode IN ('public_log', 'private_log')
  AND change->user_id != '' -- Exclude system-generated entries
```

**Filter out system entries**:
- Empty `user_login` field → automated system changes
- E.g., "Status changed from X to Y" with no human author

**For Portal Users**: Only `public_log`  
**For Agents**: Both `public_log` + `private_log`

---

## Admin Configuration

### 3-State Notification Configuration

Admins configure which notifications are available to users using a **3-state system** (mirroring CI class configuration):

**States**:
1. **`disabled`** - Notification type is completely disabled (not shown to users)
2. **`forced`** - Notification is mandatory for all users (enabled, no opt-out)
3. **`user_choice`** - Notification is enabled by default, users can opt-out in personal settings

**Keys** (in `oc_appconfig`):
```
integration_itop.portal_notification_config = JSON string
integration_itop.agent_notification_config = JSON string
integration_itop.default_notification_interval = integer (minutes)
```

**Portal Notification Types** (default config):
```json
{
  "ticket_status_changed": "user_choice",
  "agent_responded": "user_choice",
  "ticket_resolved": "user_choice",
  "agent_assigned": "user_choice"
}
```

**Agent Notification Types** (default config):
```json
{
  "ticket_assigned": "user_choice",
  "ticket_reassigned": "user_choice",
  "team_unassigned_new": "disabled",
  "ticket_tto_warning": "user_choice",
  "ticket_ttr_warning": "user_choice",
  "ticket_sla_breach": "forced",
  "ticket_priority_critical": "forced",
  "ticket_comment": "user_choice"
}
```

**Default Interval**:
- **Default**: 15 minutes
- **Min**: 5 minutes
- **Max**: 1440 minutes (24 hours)
- **Behavior**: Users inherit this value in `notification_check_interval` (can customize per-user)

**UI**: `templates/adminSettings.php` (similar to CI class configuration)
```html
<!-- Default Check Interval -->
<input type="number" name="default_notification_interval" 
       min="5" max="1440" value="15" />

<!-- 3-State Toggle Grid for Portal Notifications -->
<div class="notification-config-grid">
  <div class="notification-config-row">
    <span class="notification-label">Ticket status changed</span>
    <div class="state-toggle-group" data-notification="ticket_status_changed">
      <button data-state="disabled">🚫 Disable</button>
      <button data-state="forced">✓ Force Enable</button>
      <button data-state="user_choice" class="active">⚙️ User Choice</button>
    </div>
  </div>
  <!-- Repeat for other notification types -->
</div>
```

**Validation**: In `lib/Settings/Admin.php`
```php
$defaultInterval = max(5, min(1440, (int)$_POST['default_notification_interval']));

// Validate portal notification config
$portalConfig = json_decode($_POST['portal_notification_config'], true);
foreach (Application::PORTAL_NOTIFICATION_TYPES as $type) {
    if (!isset($portalConfig[$type]) || 
        !in_array($portalConfig[$type], ['disabled', 'forced', 'user_choice'])) {
        $portalConfig[$type] = 'disabled'; // Safe default
    }
}
```

---

## Personal Settings

### Configuration Keys (per user in `oc_preferences`)

**Master toggle behavior**:
- When user disables all portal notifications: store `disabled_portal_notifications = "all"`
- When user disables all agent notifications: store `disabled_agent_notifications = "all"`
- Background job skips users with `"all"` value (optimization)

**Disabled notification arrays** (stores opt-outs only):
```
integration_itop.disabled_portal_notifications = JSON array
integration_itop.disabled_agent_notifications = JSON array
integration_itop.notification_check_interval = integer (minutes)
```

**Example - User disables specific portal notifications**:
```json
{
  "disabled_portal_notifications": ["agent_responded", "ticket_resolved"],
  "disabled_agent_notifications": [],
  "notification_check_interval": 30
}
```

**Example - User disables all portal notifications** (master toggle off):
```json
{
  "disabled_portal_notifications": "all",
  "disabled_agent_notifications": [],
  "notification_check_interval": 15
}
```

**Logic**:
1. **Disabled** by admin → Not shown in UI, never sent
2. **Forced** by admin → Not shown in UI (always enabled), always sent
3. **User Choice** by admin → Shown in UI with checkbox
   - Unchecked → Added to `disabled_*_notifications` array
   - Checked → Not in array (enabled)

**Check Interval**:
- **Default**: Inherits from `default_notification_interval` (admin setting)
- **Range**: 5-1440 minutes
- **Per-user**: Users can customize their own check frequency

### UI Layout (`templates/personalSettings.php`)

**Similar to CI class configuration** - only show "User Choice" notifications:

```html
<h4>Notification Settings</h4>

<!-- Check Interval (user-customizable) -->
<div class="field">
  <label for="notification-check-interval">
    Check interval (minutes)
  </label>
  <input type="number" id="notification-check-interval" 
         value="<?= $_['notification_check_interval'] ?>" 
         min="5" max="1440" />
  <p class="hint">How often to check for ticket updates (default: <?= $_['default_notification_interval'] ?> min)</p>
</div>

<!-- Portal Notifications (only show user_choice types) -->
<?php if (!empty($_['user_choice_portal_notifications'])): ?>
<h5>My Tickets</h5>
<div class="notification-toggles">
  <?php foreach ($_['user_choice_portal_notifications'] as $notifType): ?>
  <div class="notification-toggle">
    <input type="checkbox" 
           id="<?= $notifType ?>" 
           name="portal_notification" 
           value="<?= $notifType ?>"
           <?= !in_array($notifType, $_['user_disabled_portal_notifications']) ? 'checked' : '' ?> />
    <label for="<?= $notifType ?>"><?= $notificationLabels[$notifType] ?></label>
  </div>
  <?php endforeach; ?>
</div>

<!-- Master toggle for portal (disable all) -->
<div class="field">
  <input type="checkbox" id="enable-all-portal" 
         <?= $_['disabled_portal_notifications'] !== 'all' ? 'checked' : '' ?> />
  <label for="enable-all-portal"><strong>Enable portal notifications</strong></label>
</div>
<?php endif; ?>

<!-- Agent Notifications (only if is_portal_only='0' AND has user_choice types) -->
<?php if (!$_['is_portal_only'] && !empty($_['user_choice_agent_notifications'])): ?>
<h5>Agent Work (My Assignments & Team Queue)</h5>
<div class="notification-toggles">
  <?php foreach ($_['user_choice_agent_notifications'] as $notifType): ?>
  <div class="notification-toggle">
    <input type="checkbox" 
           id="<?= $notifType ?>" 
           name="agent_notification" 
           value="<?= $notifType ?>"
           <?= !in_array($notifType, $_['user_disabled_agent_notifications']) ? 'checked' : '' ?> />
    <label for="<?= $notifType ?>"><?= $notificationLabels[$notifType] ?></label>
  </div>
  <?php endforeach; ?>
</div>

<!-- Master toggle for agent (disable all) -->
<div class="field">
  <input type="checkbox" id="enable-all-agent" 
         <?= $_['disabled_agent_notifications'] !== 'all' ? 'checked' : '' ?> />
  <label for="enable-all-agent"><strong>Enable agent notifications</strong></label>
</div>
<?php endif; ?>

<!-- Info box about forced notifications -->
<?php if (!empty($_['forced_portal_notifications']) || !empty($_['forced_agent_notifications'])): ?>
<div class="info-box">
  <strong>ℹ️ Note:</strong> Some notifications are mandatory and cannot be disabled:
  <ul>
    <?php foreach (array_merge($_['forced_portal_notifications'], $_['forced_agent_notifications']) as $forced): ?>
    <li><?= $notificationLabels[$forced] ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
```

**JavaScript behavior**:
```javascript
// When "Enable all portal" unchecked → set disabled_portal_notifications = "all"
// When any individual checkbox changes → update disabled_portal_notifications array
// Similar for agent notifications
```

---

## State Storage (Minimal)

### Per-User Timestamps Only

**Keys** (in `oc_preferences`):
```
integration_itop.notification_last_portal_check = integer (Unix timestamp)
integration_itop.notification_last_agent_check  = integer (Unix timestamp)
```

**Format**: Unix timestamp (seconds since epoch)
- **Harmonized with**: `profiles_last_check` format
- **Storage**: Integer value, e.g., `1730880000`
- **PHP**: `time()` to get current timestamp
- **Comparison**: Simple integer arithmetic: `time() - $lastCheck >= $interval`

**Example**:
```php
// Store
$this->config->setUserValue($userId, Application::APP_ID, 'notification_last_portal_check', (string)time());

// Retrieve
$lastCheck = (int)$this->config->getUserValue($userId, Application::APP_ID, 'notification_last_portal_check', '0');
$now = time();
$interval = 15 * 60; // 15 minutes in seconds

if (($now - $lastCheck) >= $interval) {
    // Check for notifications
}
```

**No per-ticket state needed** because:
1. **Change events**: CMDBChangeOp provides old→new transitions directly
2. **SLA warnings**: Crossing-time algorithm eliminates need for "last warned level"
3. **Comments**: CMDBChangeOp tracks log additions with timestamps
4. **Timestamps**: Unix format simplifies interval calculations and eliminates timezone issues

---

## Implementation Components

### 1. Background Jobs

#### `lib/BackgroundJob/CheckPortalTicketUpdates.php`

```php
class CheckPortalTicketUpdates extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private ItopAPIService $itopService,
        private IUserManager $userManager,
        private IConfig $config,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(5 * 60); // Run every 5 minutes
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }
    
    protected function run($argument): void {
        $this->userManager->callForAllUsers(function (IUser $user) {
            $userId = $user->getUID();
            
            // Skip if not configured or all notifications disabled
            if (!$this->shouldCheckUser($userId, 'portal')) {
                return;
            }
            
            $this->checkPortalNotifications($userId);
        });
    }
    
    private function shouldCheckUser(string $userId, string $type): bool {
        // Check if all notifications disabled (master toggle off)
        $disabledKey = "disabled_{$type}_notifications";
        $disabled = $this->config->getUserValue($userId, Application::APP_ID, $disabledKey, '');
        if ($disabled === 'all') return false;
        
        // Check person_id configured
        $personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
        if (empty($personId)) return false;
        
        // Check interval (user-specific or default)
        $userInterval = (int)$this->config->getUserValue($userId, Application::APP_ID, 'notification_check_interval', '0');
        if ($userInterval === 0) {
            // Use admin default
            $userInterval = (int)$this->config->getAppValue(Application::APP_ID, 'default_notification_interval', '15');
        }
        $interval = $userInterval * 60; // Convert to seconds
        
        $lastCheckKey = "notification_last_{$type}_check";
        $lastCheck = (int)$this->config->getUserValue($userId, Application::APP_ID, $lastCheckKey, '0');
        
        if ($lastCheck === 0) {
            return true; // First run
        }
        
        $now = time();
        return ($now - $lastCheck) >= $interval;
    }
    
    private function checkPortalNotifications(string $userId): void {
        // Get user's disabled notifications
        $disabledJson = $this->config->getUserValue($userId, Application::APP_ID, 'disabled_portal_notifications', '');
        $disabledNotifications = [];
        if ($disabledJson !== '' && $disabledJson !== 'all') {
            $disabledNotifications = json_decode($disabledJson, true) ?? [];
        }
        
        // Query optimization: determine which queries to run
        $needCaseLogQuery = !in_array('agent_responded', $disabledNotifications);
        $needScalarQuery = !in_array('ticket_status_changed', $disabledNotifications) ||
                          !in_array('ticket_resolved', $disabledNotifications) ||
                          !in_array('agent_assigned', $disabledNotifications);
        
        // Get last check timestamp
        $lastCheck = (int)$this->config->getUserValue($userId, Application::APP_ID, 'notification_last_portal_check', '0');
        if ($lastCheck === 0) {
            $lastCheck = time() - (30 * 24 * 3600); // 30 days ago for first run
        }
        
        $notificationCount = 0;
        
        // Query and process changes
        if ($needScalarQuery) {
            $ticketIds = $this->itopService->getUserTicketIds($userId, true, true);
            if (!empty($ticketIds)) {
                $changes = $this->itopService->getChangeOps($userId, $ticketIds, $lastCheck, ['status', 'agent_id']);
                // Process status/agent changes
                // Send notifications respecting $disabledNotifications
                $notificationCount += $this->processScalarChanges($userId, $changes, $disabledNotifications);
            }
        }
        
        if ($needCaseLogQuery && $notificationCount < 20) {
            $ticketIds = $this->itopService->getUserTicketIds($userId, true, false);
            if (!empty($ticketIds)) {
                $changes = $this->itopService->getCaseLogChanges($userId, $ticketIds, $lastCheck, ['public_log']);
                // Process case log changes
                $notificationCount += $this->processCaseLogChanges($userId, $changes, $disabledNotifications);
            }
        }
        
        // Update timestamp
        $this->config->setUserValue(
            $userId, 
            Application::APP_ID, 
            'notification_last_portal_check', 
            (string)time()
        );
    }
}
```

#### `lib/BackgroundJob/CheckAgentTicketUpdates.php`

Similar structure to portal job, but:
- Check `is_portal_only='0'` before processing
- Use `last_agent_check` timestamp
- Implement agent-specific notification logic

### 2. ItopAPIService Extensions

Add methods to `lib/Service/ItopAPIService.php`:

```php
/**
 * Get CMDBChangeOp records since a timestamp
 * 
 * @param string $userId Nextcloud user ID
 * @param array $classes Filter by object classes ['UserRequest', 'Incident']
 * @param string $since ISO 8601 timestamp
 * @param array $attcodes Filter by attribute codes ['agent_id', 'status', ...]
 * @return array Change operations
 */
public function getChangeOps(
    string $userId, 
    array $classes, 
    string $since, 
    array $attcodes
): array {
    // Query CMDBChangeOpSetAttributeScalar via REST API
    $oql = "SELECT CMDBChangeOpSetAttributeScalar 
            WHERE objclass IN ('" . implode("','", $classes) . "')
              AND attcode IN ('" . implode("','", $attcodes) . "')
              AND change->date > '$since'
            ORDER BY change->date ASC";
    
    $result = $this->request($userId, [
        'operation' => 'core/get',
        'class' => 'CMDBChangeOpSetAttributeScalar',
        'key' => $oql,
        'output_fields' => '*'
    ]);
    
    return $result['objects'] ?? [];
}

/**
 * Get case log changes (comments) since timestamp
 * 
 * @param string $userId Nextcloud user ID
 * @param array $ticketIds Limit to these ticket IDs
 * @param string $since ISO 8601 timestamp
 * @param array $logAttributes ['public_log', 'private_log']
 * @return array Change operations with user_login != ''
 */
public function getCaseLogChanges(
    string $userId, 
    array $ticketIds, 
    string $since, 
    array $logAttributes
): array {
    // Similar query but for CMDBChangeOpSetAttributeCaseLog
    // Filter out system entries (empty user_login)
}

/**
 * Get tickets approaching SLA deadlines (crossing-time detection)
 * 
 * @param string $userId Nextcloud user ID
 * @param string $type 'tto' or 'ttr'
 * @param string $scope 'my' (assigned to me) or 'team_unassigned'
 * @param string $since Last check timestamp
 * @param string $now Current timestamp
 * @return array Tickets with crossed thresholds
 */
public function getTicketsApproachingDeadline(
    string $userId,
    string $type, // 'tto' or 'ttr'
    string $scope, // 'my' or 'team_unassigned'
    string $since,
    string $now
): array {
    // Fetch tickets with deadline fields
    // Apply crossing-time algorithm
    // Return with threshold level (24/12/4/1)
}

/**
 * Get user's team memberships (cached 30 min)
 * 
 * @param string $userId Nextcloud user ID
 * @return array Team IDs
 */
public function getUserTeams(string $userId): array {
    // Query lnkPersonToTeam or Team memberships
    // Cache result for 30 minutes
}
```

### 3. Notifier Extension

Add cases to `lib/Notification/Notifier.php::prepare()`:

```php
switch ($notification->getSubject()) {
    // Portal
    case 'ticket_status_changed':
        $params = $notification->getSubjectParameters();
        $notification->setParsedSubject(
            $l->t('Ticket %s: Status changed', [$params['ref']])
        );
        $notification->setParsedMessage(
            $l->t('%s → %s', [$params['old_status'], $params['new_status']])
        );
        $notification->setLink($params['url']);
        $notification->setIcon($this->url->imagePath(Application::APP_ID, 'app.svg'));
        return $notification;
    
    case 'agent_responded':
        // ...
    
    case 'ticket_resolved':
        // ...
    
    // Agent
    case 'ticket_assigned':
        // ...
    
    case 'ticket_tto_warning':
        $params = $notification->getSubjectParameters();
        $level = $params['level']; // 24, 12, 4, or 1 (hours)
        $icon = match($level) {
            24 => '⏰',
            12 => '⚠️',
            4 => '🟠',
            1 => '🔴',
        };
        $notification->setParsedSubject(
            $icon . ' ' . $l->t('SLA Warning: %s needs assignment in %dh', [$params['ref'], $level])
        );
        // ...
    
    case 'ticket_sla_breach':
        // ...
    
    // ... other cases
}
```

### 4. Deprecate Old System

**Remove from `appinfo/info.xml`**:
```xml
<!-- REMOVE -->
<job>OCA\Itop\BackgroundJob\CheckOpenTickets</job>
```

**Mark as deprecated** in `lib/Service/ItopAPIService.php`:
```php
/**
 * @deprecated Use CheckPortalTicketUpdates and CheckAgentTicketUpdates instead
 */
public function checkOpenTickets(): void {
    // Keep for backward compatibility but log deprecation warning
    $this->logger->warning('checkOpenTickets() is deprecated');
}
```

---

## Testing Strategy

### Unit Tests

**File**: `tests/Unit/Service/SLAWarningDetectorTest.php`

```php
public function testCrossingTimeDetection() {
    $now = strtotime('2025-11-03 10:00:00');
    $lastCheck = strtotime('2025-11-03 09:00:00');
    
    // Ticket with deadline in 6 hours
    $deadline = strtotime('2025-11-03 16:00:00');
    
    // 24h threshold crossing time = 16:00 - 24h = Nov 2 16:00 (already crossed)
    // 12h threshold crossing time = 16:00 - 12h = Nov 3 04:00 (already crossed)
    // 4h threshold crossing time = 16:00 - 4h = Nov 3 12:00 (future)
    
    $detector = new SLAWarningDetector();
    $result = $detector->getThresholdsToNotify($deadline, $lastCheck, $now);
    
    // Should only notify 12h (crossed since last check)
    $this->assertEquals([12], $result);
}

public function testWeekendExpansion() {
    // Friday 10:00
    $now = strtotime('2025-11-07 10:00:00'); // Friday
    $lastCheck = strtotime('2025-11-07 09:00:00');
    
    // Ticket due Monday 12:00 (74 hours away)
    $deadline = strtotime('2025-11-10 12:00:00');
    
    // On Friday, 24h expands to 72h
    // Crossing time = Mon 12:00 - 72h = Fri 12:00 (future, but within expanded window)
    
    $detector = new SLAWarningDetector();
    $result = $detector->getThresholdsToNotify($deadline, $lastCheck, $now, true);
    
    // Should NOT notify yet (crossing at 12:00, now is 10:00)
    $this->assertEmpty($result);
}
```

### Integration Tests (Manual)

**Checklist**:
1. ☐ Create test ticket in iTop
2. ☐ Manually trigger background job: `occ background:job:execute 'OCA\\Itop\\BackgroundJob\\CheckAgentTicketUpdates'`
3. ☐ Verify notification appears in Nextcloud
4. ☐ Change ticket status in iTop
5. ☐ Trigger job again → verify status change notification
6. ☐ Add comment in iTop → verify comment notification
7. ☐ Assign ticket to agent → verify assignment notification
8. ☐ Test SLA warning by creating ticket with near-future deadline
9. ☐ Verify no duplicates on subsequent job runs
10. ☐ Test weekend-aware logic on Friday/Saturday

### OCC Helper Commands

**`lib/Command/NotificationsTestUser.php`**:
```bash
# Dry-run: show what would be notified
occ itop:notifications:test-user john --portal
occ itop:notifications:test-user jane --agent

# Reset timestamps (for testing)
occ itop:notifications:reset-checks john
```

---

## Performance Considerations

### Query Optimization

1. **Limit time window**: Only fetch changes from last 30 days
   ```sql
   WHERE change->date > GREATEST(:last_check, DATE_SUB(NOW(), INTERVAL 30 DAY))
   ```

2. **Batch ticket IDs**: Pre-fetch user's relevant ticket IDs, then filter CMDBChangeOp
   ```sql
   WHERE objkey IN (SELECT id FROM UserRequest WHERE caller_id = :person_id)
   ```

3. **Cache user metadata**:
   - Team memberships: 30 min TTL
   - `is_portal_only` flag: 30 min TTL
   - iTop base URL: 1 hour TTL

4. **Pagination**: If iTop supports limits, process in chunks of 100 changes per query

### Rate Limiting

- **Max 20 notifications per user per run**: Aggregate excess into summary
- **Max 100 users processed per job run**: Continue in next cycle if needed
- **Skip users with stale configurations**: No person_id or iTop unreachable

### Logging

```php
$this->logger->info('Portal notification check completed', [
    'app' => Application::APP_ID,
    'users_processed' => $userCount,
    'notifications_sent' => $notificationCount,
    'skipped_users' => $skippedCount,
    'duration_ms' => $duration,
]);
```

---

## Migration Path

### Phase 1: Portal Notifications (Priority 1) ✅ **COMPLETE**
**Actual Time**: 16 hours
**Branch**: `feature/notification-system` (33 commits)

#### Implementation Summary
- ✅ **3-State Admin Configuration** (disabled/forced/user_choice) with visual grid layout matching CI class configuration
- ✅ **Admin settings** for portal & agent notifications with default interval (5-1440 min, default 15)
- ✅ **Personal settings UI** with master toggle, granular per-notification-type checkboxes, custom intervals
- ✅ **Custom notification icons** (user-request-deadline.svg, discussion-forum.svg, checkmark.svg, customer.svg)
- ✅ **Section structure** with emojis (🔔 Notification Settings, 🔎 Search Settings, 🎯 CI Classes)
- ✅ **Master toggle behavior** hides/shows notification settings when enabled/disabled
- ✅ **Client-side validation** for notification check interval (5-1440 minutes)
- ✅ **Background job** `CheckPortalTicketUpdates` (runs every 5 min, respects user intervals)
- ✅ **ItopAPIService methods**: `getChangeOps()`, `getCaseLogChanges()`, `getUserTicketIds()`, `resolveUserNames()`
- ✅ **Notifier**: 4 portal notification types (ticket_status_changed, agent_responded, ticket_resolved, agent_assigned)
- ✅ **Contact role filtering** - notifications sent to direct callers AND contacts with role_code IN ('manual', 'computed')
- ✅ **Timezone handling** (uses Nextcloud's default_timezone config)
- ✅ **Self-notification filtering** (no notifications for own comments)
- ✅ **Agent name resolution** with 24-hour caching
- ✅ **Unix timestamp filtering fix** (is_numeric() check before strtotime())
- ✅ **Unique notification object keys** (ticket_id|subject|timestamp_hash) prevent duplicates
- ✅ **Query optimization** - skips API calls when notification types disabled
- ✅ **OCC command**: `itop:notifications:test-user` with portal/agent flags
- ✅ **Translations**: EN, DE (informal + formal), FR
- ✅ **Manual testing** with OrbStack dev environment

#### Technical Highlights
1. **3-State Configuration System**:
   - Admin: `portal_notification_config`, `agent_notification_config` (JSON)
   - States: `disabled` (hidden), `forced` (always on), `user_choice` (user decides)
   - User: `disabled_portal_notifications`, `disabled_agent_notifications` (JSON arrays)
   - Special case: `notification_enabled = '0'` acts as master toggle

2. **Contact Role Filtering** (Three-query approach):
   - Query 1: Direct UserRequest/Incident tickets (`caller_id = person_id`)
   - Query 2: Contact-linked tickets (`lnkContactToTicket WHERE contact_id = person_id AND role_code IN ('manual', 'computed')`)
   - Query 3: Status filtering on contact-linked tickets (query Ticket parent class)
   - Excludes: `role_code = 'do_not_notify'`
   - Deduplication: `array_unique()` on final ticket ID list

3. **Query Optimization**:
   - Early exit if all notifications disabled (no API calls)
   - Skip `getCaseLogChanges()` if `agent_responded` disabled
   - Skip `getChangeOps()` if all status/agent/resolved notifications disabled
   - Only queries for enabled notification types

4. **Timestamp Handling**:
   - Storage: Unix timestamps (integer strings)
   - Filtering: Proper is_numeric() check before strtotime() conversion
   - Comparison: `strtotime($changeDate) > $sinceTimestamp`

5. **Deduplication**:
   - Unique object keys: `ticket_id|subject|timestamp_hash`
   - Nextcloud's notification system handles duplicate object_id prevention
   - Timestamp filtering prevents re-processing old changes

**Acceptance Criteria**: ✅ ALL MET
- ✅ Portal users receive notifications for status changes, agent responses, resolutions, agent assignments
- ✅ Notifications sent to direct callers AND contacts (role_code IN ('manual', 'computed'))
- ✅ Users with role_code = 'do_not_notify' excluded from notifications
- ✅ No duplicate notifications (unique object keys + timestamp filtering)
- ✅ Master toggle works (notification_enabled)
- ✅ Granular toggles respected (disabled_portal_notifications array)
- ✅ Correct timezone handling (Europe/Vienna)
- ✅ No self-notifications (user_id filtering in agent_responded)
- ✅ Rate limiting (max 20 per run)
- ✅ Resolved ticket detection works (include_resolved parameter)
- ✅ Query optimization (zero API calls when all disabled)
- ✅ Client-side validation (5-1440 minute range)
- ✅ Custom icons per notification type
- ✅ Visual consistency with CI class configuration

### Phase 2: Agent Notifications (Priority 2) ✅ **COMPLETE**
**Actual Time**: 14 hours
**Completion Date**: 2025-11-08

#### Implementation Summary
- ✅ **CheckAgentTicketUpdates background job** - Runs every 5 minutes, processes 8 agent notification types
- ✅ **3-State Admin Configuration** - disabled/forced/user_choice for all 8 notification types
- ✅ **ItopAPIService methods**: `getAgentTicketIds()`, `getTicketsApproachingDeadline()`, `getTeamAssignmentChanges()`, `applyCrossingTimeAlgorithm()`
- ✅ **SLA crossing-time algorithm** - Weekend-aware thresholds (Friday: 72h, Saturday: 48h, otherwise: 24h/12h/4h/1h)
- ✅ **Notifier**: All 8 agent notification types with escalating emoji icons
- ✅ **OCC command**: Updated with `--agent` flag for testing
- ✅ **Team detection**: Fully implemented `team_unassigned_new` with team_id change tracking
- ✅ **Default configuration**: team_unassigned_new enabled as user_choice, SLA breach/priority critical forced

#### Technical Highlights
1. **8 Agent Notification Types** (all fully implemented):
   - `ticket_assigned` - New assignments (agent_id: NULL → person_id)
   - `ticket_reassigned` - Reassignments (agent_id: other → person_id)
   - `team_unassigned_new` - New unassigned tickets in user's teams
   - `ticket_tto_warning` - Time To Own SLA warnings (team tickets)
   - `ticket_ttr_warning` - Time To Resolve SLA warnings (my tickets)
   - `ticket_sla_breach` - SLA breaches (TTO/TTR)
   - `ticket_priority_critical` - Priority escalations to critical
   - `ticket_comment` - Comments (public + private for agents)

2. **SLA Crossing-Time Algorithm**:
   - Detects threshold crossings without storing per-ticket state
   - Weekend expansion: Friday (72h), Saturday (48h)
   - Escalating icons: ⏰ (24h) → ⚠️ (12h) → 🟠 (4h) → 🔴 (1h)
   - Only sends most urgent notification per ticket

3. **Team Ticket Detection**:
   - Tracks `team_id` changes via CMDBChangeOp
   - Verifies tickets remain unassigned (agent_id = NULL/0)
   - Includes team name in notifications

4. **Portal-Only Filtering**:
   - Agent job skips users with `is_portal_only='1'`
   - Proper separation of portal vs agent notification tracks

**Acceptance Criteria**: ✅ ALL MET
- ✅ Agents receive all 8 notification types
- ✅ Assignments and reassignments detected correctly
- ✅ SLA warnings use weekend-aware logic (Friday 72h, Saturday 48h)
- ✅ SLA crossing-time algorithm prevents duplicate warnings
- ✅ Comments distinguish public vs. private log types
- ✅ Team unassigned tickets detected via team_id changes
- ✅ Rate limiting (max 20 notifications per user per run)
- ✅ Query optimization (skips API calls when types disabled)
- ✅ Portal-only users correctly excluded from agent notifications

### Phase 3: Newsroom Mirroring (Priority 3, Opt-In) ⏳ **DEFERRED**
**Status**: Feature request documented in [GitHub Issue #3](https://github.com/LexioJ/integration_itop/issues/3)  
**Estimated Time**: 6-8 hours

**Planned Features**:
- ⏳ Implement per `docs/PLAN_NEWSROOM_MIRRORING.md`
- ⏳ `NewsroomPollJob`, `NewsroomService`, `NewsroomController`
- ⏳ Personal setting: `notify_newsroom_enabled`
- ⏳ Notifier: `newsroom_item` case
- ⏳ Mark-as-read bidirectional sync

**Acceptance Criteria**:
- Users who opt-in receive iTop newsroom notifications
- Mark-as-read syncs to iTop
- Independent from ticket notifications

**Note**: This is a nice-to-have enhancement scheduled for a future release (v1.4.0 or later).

### Phase 4: Polish & Documentation (Priority 4) ✅ **COMPLETE**
**Actual Time**: 3 hours
**Completion Date**: 2025-11-08

#### Implementation Summary
- ✅ **docs/NOTIFICATIONS.md** - Comprehensive 345-line user & admin guide covering all 12 notification types
- ✅ **CHANGELOG.md** - Detailed v1.3.0 entry with technical details and breaking changes section
- ✅ **README.md** - Updated with notification system overview in features section
- ✅ **Translations** - Added 21 new strings for agent notification messages (EN, DE, DE_DE, FR)
- ✅ **Version bump** - Updated version badge from 1.2.0 to 1.3.0

#### Documentation Highlights
1. **NOTIFICATIONS.md**:
   - Complete notification type reference with examples
   - Setup instructions for users and administrators
   - Troubleshooting section with background job initialization
   - FAQ with 12 Q&A items
   - Technical architecture details

2. **CHANGELOG.md**:
   - Major feature announcement for v1.3.0
   - Detailed breakdown of 8 agent + 4 portal notification types
   - Technical details (config keys, user preferences, architecture)
   - Performance metrics and migration notes

3. **Translation Completeness**:
   - All 8 agent notification subject lines
   - All 21 notification message strings
   - Admin configuration labels
   - User settings labels
   - Coverage: EN, DE (informal), DE_DE (formal), FR

**Acceptance Criteria**: ✅ ALL MET
- ✅ Complete user and admin documentation
- ✅ CHANGELOG.md updated with v1.3.0 entry
- ✅ README.md highlights notification system
- ✅ All notification strings translated (EN, DE, DE_DE, FR)
- ✅ Documentation covers troubleshooting and FAQs
- ✅ `.warp.md` project guide (add to `.gitignore`)
- ✅ OCC commands: `itop:notifications:test-user`, `itop:notifications:reset-checks`
- ✅ Performance testing with 100+ tickets
- ✅ Accessibility review

---

## Documentation Deliverables

### 1. `docs/NOTIFICATIONS.md` (User & Admin Guide)

**Sections**:
- Overview of notification types
- Admin setup: polling intervals
- User setup: personal preferences
- Understanding SLA warnings (escalating alerts, weekend-aware)
- Troubleshooting: no notifications, duplicates, OCC commands
- FAQ

### 2. `.warp.md` (Development Guide)

**Sections**:
- Project purpose: smart notifications for iTop integration
- Environment: Nextcloud + iTop, OrbStack dev setup
- Testing: how to trigger background jobs manually, create test scenarios
- Key files and architecture
- Add to `.gitignore`

### 3. Update Existing Docs

- `docs/README.md`: Add link to NOTIFICATIONS.md
- `CHANGELOG.md`: Add entry for new notification system
- `README.md`: Mention notification features

---

## Security Considerations

### 1. User Data Isolation

- Only process tickets where user is **caller** (portal) or **agent** (agent notifications)
- Respect iTop's per-user permissions via application token
- Never expose ticket content to unauthorized users

### 2. Rate Limiting

- Max 20 notifications per user per run (prevent spam)
- Admin-configurable intervals prevent DoS on iTop API
- Fail gracefully if iTop unreachable (skip user, log error)

### 3. Token Security

- Application token stored encrypted in `oc_appconfig`
- No user credentials stored (dual-token architecture)
- All API calls use HTTPS

### 4. Notification Integrity

- Use `buildTicketUrl()` to ensure correct portal vs. agent UI routing
- Validate person_id before processing notifications
- Filter out system-generated changes (empty `user_login`)

---

## Success Metrics

### Key Performance Indicators (KPIs)

1. **Zero duplicate notifications** for same event
2. **< 1 second** per user processing time
3. **95%+ accuracy** for SLA crossing detection
4. **< 5 minute** notification delay (with 5min polling)
5. **< 10 API calls** per user per check (portal and agent combined)

### User Satisfaction

- Users report timely awareness of ticket changes
- No spam complaints (respect granular toggles)
- SLA warnings help prioritize work (especially Friday alerts for Monday breaches)

---

## Appendix

### A. iTop REST API Examples

#### Query CMDBChangeOp

**IMPORTANT**: iTop OQL does **not** support comparison operators (`>`, `<`, `=`) on external key attributes like `change->date`. Attempting to use `change->date > 'timestamp'` will result in an `OQLParserSyntaxErrorException`.

**Solution**: Fetch all relevant CMDBChangeOp records and filter by timestamp in PHP.

```json
{
  "operation": "core/get",
  "class": "CMDBChangeOpSetAttributeScalar",
  "key": "SELECT CMDBChangeOpSetAttributeScalar WHERE objclass='UserRequest' AND attcode='status' AND objkey IN (123,456,789)",
  "output_fields": "*"
}
```

**Note**: The `change->date > '2025-11-03 09:00:00'` filter is **removed** from the OQL query and applied in PHP:

```php
$sinceTimestamp = strtotime($since);
foreach ($result['objects'] as $changeOp) {
    $fields = $changeOp['fields'] ?? [];
    $changeDate = $fields['date'] ?? '';
    
    if (!empty($changeDate) && strtotime($changeDate) > $sinceTimestamp) {
        // Process this change
    }
}
```

#### Response
```json
{
  "objects": {
    "CMDBChangeOpSetAttributeScalar::123": {
      "key": "123",
      "fields": {
        "objclass": "UserRequest",
        "objkey": "456",
        "attcode": "status",
        "oldvalue": "new",
        "newvalue": "assigned",
        "date": "2025-11-03 09:30:00",
        "userinfo": "John Doe",
        "user_id": "42"
      }
    }
  }
}
```

**Note**: The `change` object is not directly accessible in the API response. The `date`, `userinfo`, and `user_id` are available as top-level fields.

### B. Nextcloud Notification API

#### Create Notification
```php
$notification = $this->notificationManager->createNotification();
$notification->setApp('integration_itop')
    ->setUser($userId)
    ->setDateTime(new \DateTime($timestamp))
    ->setObject('ticket', $ticketId)
    ->setSubject('ticket_assigned', [
        'ref' => 'R-000123',
        'title' => 'Printer not working',
        'url' => 'https://itop.example.com/...'
    ]);

$this->notificationManager->notify($notification);
```

### C. Weekend-Aware Threshold Calculation

```php
function getThresholdsForDay(int $dayOfWeek): array {
    // Base: 24h, 12h, 4h, 1h (in seconds)
    $thresholds = [24*3600, 12*3600, 4*3600, 1*3600];
    
    if ($dayOfWeek === 5) {
        // Friday: expand 24h to 72h
        $thresholds[0] = 72 * 3600;
    } elseif ($dayOfWeek === 6) {
        // Saturday: expand 24h to 48h
        $thresholds[0] = 48 * 3600;
    }
    
    return $thresholds;
}
```

---

## Conclusion

This implementation plan provides a **comprehensive, scalable, and maintainable** notification system for the iTop integration. By leveraging iTop's built-in change tracking (`CMDBChangeOp`), we achieve:

✅ **Zero duplicate notifications** through event-time based detection  
✅ **Minimal state storage** (only timestamps, no per-ticket data)  
✅ **Weekend-aware SLA warnings** to help agents prioritize Friday work  
✅ **Escalating alerts** (24h → 12h → 4h → 1h) for proactive SLA management  
✅ **Granular user control** with master + per-type toggles  
✅ **Admin flexibility** with configurable polling intervals  

**Total Estimated Implementation Time**: 30-42 hours across 4 phases.

**Next Steps**: Review this plan, confirm approach, then proceed with Phase 1 (Portal Notifications).
