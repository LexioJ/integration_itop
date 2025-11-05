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
│  • Skip if now - last_check < admin_configured_interval         │
│  • Skip if master toggle off or no person_id                    │
│  • Portal: skip if portal-only AND not portal job               │
│  • Agent: skip if is_portal_only='1'                            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Query iTop for Changes Since Last Check                        │
│  • CMDBChangeOp (change tracking) since last_check              │
│  • Current ticket state for SLA deadline calculations           │
│  • Filter to operational_status='ongoing'                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Detect & Classify Events                                       │
│  • Portal: status changes, agent comments, resolved             │
│  • Agent: assignments, SLA warnings/breaches, priority, comments│
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Create Nextcloud Notifications                                 │
│  • Respect user's granular toggles                              │
│  • Rate limit: max 20 notifications per user per run            │
│  • Use buildTicketUrl() for portal vs. agent UI routing         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Update last_check Timestamp                                    │
│  • last_portal_check or last_agent_check = NOW()                │
└─────────────────────────────────────────────────────────────────┘
```

---

## Notification Types

### Portal User Notifications

| Type | Trigger | Detection Method | User Toggle |
|------|---------|------------------|-------------|
| **Ticket status changed** | Status field changes (e.g., new→assigned, assigned→resolved) | CMDBChangeOp: `attcode='status'` on caller's tickets | `notify_ticket_status_changed` |
| **Agent responded** | Public log entry added by agent | CMDBChangeOp: `attcode='public_log'` with `user_login != ''` | `notify_agent_responded` |
| **Ticket resolved** | Status becomes 'resolved' | CMDBChangeOp: `attcode='status'`, `newvalue='resolved'` | `notify_ticket_resolved` |
| **Agent assigned changed** | Agent assignment changes | CMDBChangeOp: `attcode='agent_id'` with name resolution | `notify_agent_assigned` (via status_changed toggle) |

### Agent/Fulfiller Notifications

| Type | Trigger | Detection Method | User Toggle |
|------|---------|------------------|-------------|
| **Ticket assigned to me** | agent_id changes from NULL to my person_id | CMDBChangeOp: `attcode='agent_id'`, `oldvalue IS NULL`, `newvalue=person_id` | `notify_ticket_assigned` |
| **Ticket reassigned to me** | agent_id changes from another person to me | CMDBChangeOp: `attcode='agent_id'`, `oldvalue != NULL AND != person_id`, `newvalue=person_id` | `notify_ticket_reassigned` |
| **New unassigned ticket in my team** | Ticket created/updated in my teams with no agent | CMDBChangeOp: ticket creation or `agent_id=NULL` in team queue | `notify_team_unassigned_new` *(optional)* |
| **TTO SLA warning (escalating)** | Unassigned team ticket approaching TTO deadline | Current ticket state: `tto_escalation_deadline - now` crosses 24h/12h/4h/1h thresholds | `notify_ticket_tto_warning` |
| **TTR SLA warning (escalating)** | My assigned ticket approaching TTR deadline | Current ticket state: `ttr_escalation_deadline - now` crosses 24h/12h/4h/1h thresholds | `notify_ticket_ttr_warning` |
| **SLA breach (TTO/TTR)** | Ticket enters escalated status | CMDBChangeOp: `attcode IN ('sla_tto_passed','sla_ttr_passed')`, `newvalue='yes'` | `notify_ticket_sla_breach` |
| **Priority changed to Critical** | Priority escalates to level 1 (critical) | CMDBChangeOp: `attcode='priority'`, `newvalue='1'` | `notify_ticket_priority_critical` |
| **New comment on my ticket** | Public/private log entry added | CMDBChangeOp: `attcode IN ('public_log','private_log')` with `user_login != ''` | `notify_ticket_comment` |

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

### Polling Intervals

**Keys** (in `oc_appconfig`):
```
integration_itop.portal_notification_interval
integration_itop.agent_notification_interval
```

**Values**: Integer minutes
- **Default**: Portal: 15 min, Agent: 10 min
- **Min**: 5 minutes
- **Max**: 1440 minutes (24 hours)

**Behavior**:
- Background jobs run **every 5 minutes** (TimedJob)
- Per-user checks **skip** unless `(now - last_check) >= configured_interval`

**UI**: `templates/adminSettings.php`
```html
<input type="number" name="portal_notification_interval" 
       min="5" max="1440" value="15" />
<input type="number" name="agent_notification_interval" 
       min="5" max="1440" value="10" />
```

**Validation**: In `lib/Settings/Admin.php`
```php
$portalInterval = max(5, min(1440, (int)$_POST['portal_notification_interval']));
$agentInterval = max(5, min(1440, (int)$_POST['agent_notification_interval']));
```

---

## Personal Settings

### Configuration Keys (per user in `oc_preferences`)

**Master toggle**:
```
integration_itop.notification_enabled = '1' | '0'
```

**Portal notifications** (always visible):
```
integration_itop.notify_ticket_status_changed = '1' | '0'
integration_itop.notify_agent_responded = '1' | '0'
integration_itop.notify_ticket_resolved = '1' | '0'
```

**Agent notifications** (visible when `is_portal_only='0'`):
```
integration_itop.notify_ticket_assigned = '1' | '0'
integration_itop.notify_ticket_reassigned = '1' | '0'
integration_itop.notify_team_unassigned_new = '1' | '0'  # Optional
integration_itop.notify_ticket_tto_warning = '1' | '0'
integration_itop.notify_ticket_ttr_warning = '1' | '0'
integration_itop.notify_ticket_sla_breach = '1' | '0'
integration_itop.notify_ticket_priority_critical = '1' | '0'
integration_itop.notify_ticket_comment = '1' | '0'
```

**Newsroom** (opt-in for all users):
```
integration_itop.notify_newsroom_enabled = '1' | '0'
```

### UI Layout (`templates/personalSettings.php`)

```html
<h3>iTop Notifications</h3>

<!-- Master Toggle -->
<label>
  <input type="checkbox" name="notification_enabled" value="1" <?= $enabled ? 'checked' : '' ?> />
  Enable iTop Notifications
</label>

<!-- Portal Section (always visible) -->
<h4>My Tickets</h4>
<label>
  <input type="checkbox" name="notify_ticket_status_changed" <?= ... ?> />
  Ticket status changed
</label>
<label>
  <input type="checkbox" name="notify_agent_responded" <?= ... ?> />
  Agent responded to my ticket
</label>
<label>
  <input type="checkbox" name="notify_ticket_resolved" <?= ... ?> />
  Ticket resolved
</label>

<!-- Agent Section (only if is_portal_only='0') -->
<?php if (!$is_portal_only): ?>
<h4>Agent Work (My Assignments & Team Queue)</h4>
<label>
  <input type="checkbox" name="notify_ticket_assigned" <?= ... ?> />
  New ticket assigned to me
</label>
<label>
  <input type="checkbox" name="notify_ticket_reassigned" <?= ... ?> />
  Ticket reassigned to me
</label>
<label>
  <input type="checkbox" name="notify_ticket_tto_warning" <?= ... ?> />
  SLA warning: TTO approaching (escalating alerts)
</label>
<label>
  <input type="checkbox" name="notify_ticket_ttr_warning" <?= ... ?> />
  SLA warning: TTR approaching (escalating alerts)
</label>
<label>
  <input type="checkbox" name="notify_ticket_sla_breach" <?= ... ?> />
  SLA breach (TTO/TTR exceeded)
</label>
<label>
  <input type="checkbox" name="notify_ticket_priority_critical" <?= ... ?> />
  Priority escalated to Critical
</label>
<label>
  <input type="checkbox" name="notify_ticket_comment" <?= ... ?> />
  New comments on my tickets
</label>
<?php endif; ?>

<!-- Newsroom Opt-In -->
<h4>Experimental Features</h4>
<label>
  <input type="checkbox" name="notify_newsroom_enabled" <?= ... ?> />
  Enable iTop Newsroom sync (beta)
</label>
```

---

## State Storage (Minimal)

### Per-User Timestamps Only

**Keys** (in `oc_preferences`):
```
integration_itop.last_portal_check   = '2025-11-03 09:30:00'
integration_itop.last_agent_check    = '2025-11-03 09:30:00'
```

**No per-ticket state needed** because:
1. **Change events**: CMDBChangeOp provides old→new transitions directly
2. **SLA warnings**: Crossing-time algorithm eliminates need for "last warned level"
3. **Comments**: CMDBChangeOp tracks log additions with timestamps

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
        $configuredInterval = (int)$this->config->getAppValue(
            Application::APP_ID, 
            'portal_notification_interval', 
            '15'
        ) * 60; // Convert to seconds
        
        $this->userManager->callForAllUsers(function (IUser $user) use ($configuredInterval) {
            $userId = $user->getUID();
            
            // Skip if not configured
            if (!$this->shouldCheckUser($userId, 'portal', $configuredInterval)) {
                return;
            }
            
            $this->checkPortalNotifications($userId);
        });
    }
    
    private function shouldCheckUser(string $userId, string $type, int $interval): bool {
        // Check master toggle
        $enabled = $this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1';
        if (!$enabled) return false;
        
        // Check person_id configured
        $personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
        if (empty($personId)) return false;
        
        // Check interval
        $lastCheckKey = "last_{$type}_check";
        $lastCheck = $this->config->getUserValue($userId, Application::APP_ID, $lastCheckKey, '');
        
        if (empty($lastCheck)) {
            return true; // First run
        }
        
        $lastCheckTime = strtotime($lastCheck);
        $now = time();
        
        return ($now - $lastCheckTime) >= $interval;
    }
    
    private function checkPortalNotifications(string $userId): void {
        // Implementation: query CMDBChangeOp, detect events, send notifications
        // (See detailed flow below)
        
        // Update timestamp
        $this->config->setUserValue(
            $userId, 
            Application::APP_ID, 
            'last_portal_check', 
            date('Y-m-d H:i:s')
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
**Actual Time**: 12 hours

- ✅ Admin settings for `portal_notification_interval` (5-1440 min, default 15)
- ✅ Personal settings UI (portal section with 3 toggles + master)
- ✅ `CheckPortalTicketUpdates` background job (runs every 5 min)
- ✅ ItopAPIService: `getChangeOps()`, `getCaseLogChanges()`, `getUserTicketIds()`, `resolveUserNames()`
- ✅ Notifier: 4 portal notification types (status_changed, agent_responded, ticket_resolved, agent_assigned)
- ✅ Timezone handling (uses Nextcloud's default_timezone config)
- ✅ Self-notification filtering (no notifications for own comments)
- ✅ Agent name resolution with 24-hour caching
- ✅ OQL limitation workaround (PHP-side timestamp filtering)
- ✅ OCC command: `itop:notifications:test-user` with reset functionality
- ✅ Manual testing with OrbStack dev environment

**Acceptance Criteria**: ✅ ALL MET
- ✅ Portal users receive notifications for status changes, agent responses, resolutions, agent assignments
- ✅ No duplicate notifications (timestamp-based deduplication)
- ✅ Master toggle works
- ✅ Granular toggles respected
- ✅ Correct timezone handling (Europe/Vienna)
- ✅ No self-notifications
- ✅ Rate limiting (max 20 per run)
- ✅ Resolved ticket detection works

### Phase 2: Agent Notifications (Priority 2)
**Estimated Time**: 12-16 hours

- ✅ Admin settings for `agent_notification_interval`
- ✅ Personal settings UI (agent section, visibility gated)
- ✅ `CheckAgentTicketUpdates` background job
- ✅ ItopAPIService: `getTicketsApproachingDeadline()`, `getUserTeams()`, `getSLABreaches()`
- ✅ SLA crossing-time algorithm with weekend-aware thresholds
- ✅ Notifier: agent notification types
- ✅ Unit tests for SLA warnings, assignment detection
- ✅ Manual testing with SLA scenarios

**Acceptance Criteria**:
- Agents receive assignment, reassignment, SLA warnings (escalating 24/12/4/1h), SLA breaches, priority changes, comments
- Weekend-aware logic works (Friday 72h, Saturday 48h)
- No duplicate warnings for same SLA level
- Comments distinguish public vs. private for agents

### Phase 3: Newsroom Mirroring (Priority 3, Opt-In)
**Estimated Time**: 6-8 hours

- ✅ Implement per `docs/PLAN_NEWSROOM_MIRRORING.md`
- ✅ `NewsroomPollJob`, `NewsroomService`, `NewsroomController`
- ✅ Personal setting: `notify_newsroom_enabled`
- ✅ Notifier: `newsroom_item` case
- ✅ Mark-as-read bidirectional sync

**Acceptance Criteria**:
- Users who opt-in receive iTop newsroom notifications
- Mark-as-read syncs to iTop
- Independent from ticket notifications

### Phase 4: Polish & Documentation (Priority 4)
**Estimated Time**: 4-6 hours

- ✅ i18n: EN, DE, FR translations
- ✅ Documentation: `docs/NOTIFICATIONS.md`, update README, CHANGELOG
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
