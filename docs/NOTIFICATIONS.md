# iTop Notifications Guide

Complete guide to the smart notification system for Nextcloud iTop integration.

## Overview

The iTop integration provides **two independent notification tracks** to keep you informed about ticket updates:

- **Portal Notifications** (4 types) - For all users about their tickets
- **Agent Notifications** (8 types) - For IT agents about assignments, SLA warnings, and team work

### Key Features

✅ **Smart Detection** - Event-time based detection using iTop's change tracking (no duplicate notifications)  
✅ **Minimal State** - Only timestamps stored, no per-ticket state  
✅ **Weekend-Aware SLA** - Friday gets 72h warning, Saturday gets 48h warning  
✅ **Escalating Alerts** - SLA warnings at 24h → 12h → 4h → 1h before breach  
✅ **Granular Control** - Master toggle + per-notification-type preferences  
✅ **Flexible Intervals** - Admin configurable polling (5 minutes to 24 hours)  

---

## For Users

### Portal Notifications (My Tickets)

These notifications keep you updated about tickets you created or are involved with:

| Notification | When You Get It | Example |
|--------------|-----------------|---------|
| **Ticket Status Changed** | Status changes (new→assigned, assigned→pending, etc.) | "Ticket R-000123: Status changed - new → assigned" |
| **Agent Responded** | Agent adds a public comment | "Agent responded to your ticket - John Doe added a response" |
| **Ticket Resolved** | Your ticket is marked as resolved | "Ticket resolved - Your ticket has been resolved" |
| **Assigned Agent Changed** | Agent assignment changes | "Assigned agent changed - John Doe → Jane Smith" |

### Agent Notifications (My Work)

If you're an IT agent, you receive additional notifications about your workload:

| Notification | When You Get It | Example |
|--------------|-----------------|---------|
| **Ticket Assigned** | A new ticket is assigned to you | "Ticket assigned to you - A new ticket has been assigned to you" |
| **Ticket Reassigned** | A ticket is reassigned from another agent to you | "Ticket reassigned to you - A ticket has been reassigned to you" |
| **Team Unassigned New** | New ticket appears in your team's queue (no agent yet) | "New unassigned ticket in Help Desk - A new ticket needs assignment in Help Desk" |
| **TTO SLA Warning** | Team ticket approaching Time To Own deadline | "⏰ TTO SLA warning: 24h remaining - Ticket needs assignment within 24 hours" |
| **TTR SLA Warning** | Your ticket approaching Time To Resolve deadline | "🔴 TTR SLA warning: 1h remaining - Ticket needs resolution within 1 hour" |
| **SLA Breach** | SLA deadline exceeded | "🚨 TTO SLA breached - Ticket has breached TTO SLA deadline" |
| **Priority Critical** | Ticket priority escalated to critical | "🔴 Ticket escalated to CRITICAL - Ticket priority changed to critical" |
| **New Comment** | Someone adds a comment (public or private) to your ticket | "New comment on your ticket - Jane Doe added a comment" |

### Setup Your Notifications

1. **Go to Personal Settings** → **iTop Integration**
2. **Enable Notifications** - Toggle the master switch
3. **Choose Check Interval** - How often to check for updates (default: 15 minutes)
4. **Select Notification Types** - Check/uncheck specific notification types
5. **Save** - Your preferences are saved automatically

#### Master Toggle Behavior

- **Portal Notifications OFF** - You won't receive any notifications about your tickets
- **Agent Notifications OFF** - You won't receive any agent-specific notifications (if you're an agent)
- Individual notification types can be enabled/disabled when master toggle is ON

#### Understanding SLA Warnings

SLA warnings use **escalating urgency levels**:

- **⏰ 24 hours** - Early warning (or 72h on Friday, 48h on Saturday)
- **⚠️ 12 hours** - Increased urgency
- **🟠 4 hours** - High urgency
- **🔴 1 hour** - Critical - immediate action needed

**Weekend-Aware Logic**:
- **Friday**: Get 72-hour warning (catches Monday/Tuesday breaches)
- **Saturday**: Get 48-hour warning (catches Sunday/Monday breaches)
- **Other days**: Standard 24/12/4/1 hour warnings

You'll only get **one notification per ticket** at the most urgent level since your last check.

---

## For Administrators

### Initial Setup

1. **Configure iTop Connection**
   - Go to **Admin Settings** → **iTop Integration**
   - Set iTop instance URL
   - Configure application token (admin-level access)

2. **Set Default Interval**
   - Default check interval for all users (5-1440 minutes)
   - Recommended: 15 minutes
   - Users can customize their own interval

3. **Configure Notification Types**
   - Choose which notification types are available to users
   - Three states: **Disabled**, **Forced**, **User Choice**

### 3-State Configuration

Each notification type can be configured with one of three states:

| State | Icon | Behavior | User Control |
|-------|------|----------|--------------|
| **Disabled** | 🚫 | Not available, never sent | Hidden from users |
| **Forced** | ✓ | Always enabled, mandatory | Cannot be disabled by users |
| **User Choice** | ⚙️ | Enabled by default | Users can opt-out |

#### Default Configuration

**Portal Notifications:**
- Ticket Status Changed: **User Choice**
- Agent Responded: **User Choice**
- Ticket Resolved: **User Choice**
- Assigned Agent Changed: **User Choice**

**Agent Notifications:**
- Ticket Assigned: **User Choice**
- Ticket Reassigned: **User Choice**
- Team Unassigned New: **User Choice**
- TTO SLA Warning: **User Choice**
- TTR SLA Warning: **User Choice**
- SLA Breach: **Forced** (mandatory, cannot be disabled)
- Priority Critical: **Forced** (mandatory, cannot be disabled)
- Comment: **User Choice**

### Background Jobs

The notification system uses two background jobs that run every 5 minutes:

- **CheckPortalTicketUpdates** - Processes portal notifications
- **CheckAgentTicketUpdates** - Processes agent notifications

#### Manual Initialization (First Time Setup)

After installing or enabling the app, manually trigger each job once to initialize:

```bash
# Find job IDs
occ background-job:list | grep -E "(CheckPortal|CheckAgent)"

# Initialize portal job
occ background-job:execute <portal-job-id> --force-execute

# Initialize agent job  
occ background-job:execute <agent-job-id> --force-execute
```

After initialization, cron will automatically execute the jobs every 5 minutes.

### Performance Tuning

#### Per-User Interval

Each user checks for notifications based on their configured interval (or admin default). If a user sets a 60-minute interval, they're only processed once per hour, reducing API load.

#### Query Optimization

The system automatically skips unnecessary API calls:
- If all notification types are disabled for a user, no queries are made
- If specific types are disabled, related queries are skipped
- Portal-only users are automatically skipped for agent notifications

#### Rate Limiting

- **Max 20 notifications per user per run** - Prevents spam
- Exceeding users get their 20 most recent/urgent notifications

### Monitoring

Check background job logs:

```bash
# View recent notification job completions
tail -100 /var/nextcloud-data/nextcloud.log | grep "notification check completed"

# Test specific user
occ itop:notifications:test-user username --portal
occ itop:notifications:test-user username --agent
```

---

## Troubleshooting

### No Notifications Received

**Check user configuration:**
```bash
occ itop:notifications:test-user username --portal
```

Common issues:
- ❌ **Notifications disabled** - Master toggle is OFF
- ❌ **No person_id** - User hasn't configured their iTop personal token
- ❌ **Portal-only user** - Won't receive agent notifications
- ❌ **All types disabled** - User opted out of all notification types
- ❌ **Interval not elapsed** - Check when next run is scheduled

**Check background jobs:**
```bash
# List jobs
occ background-job:list | grep itop

# Check if jobs are running
tail -50 /var/nextcloud-data/nextcloud.log | grep "notification check completed"
```

### Duplicate Notifications

The system prevents duplicates through:
- **Unique object keys** - Each notification has unique identifier (ticket_id|subject|timestamp_hash)
- **Timestamp filtering** - Only changes since last check are processed
- **SLA crossing-time algorithm** - Only most urgent threshold notified

If you see duplicates:
1. Check if multiple users have the same `person_id` configured
2. Verify background jobs aren't manually triggered during normal operation
3. Check iTop server time is synchronized

### Missing Notifications

**For specific event types:**

1. **Status Changes Not Detected**
   - Verify `ticket_status_changed` is enabled
   - Check if status actually changed in iTop (CMDBChangeOp records)

2. **Comments Not Notified**
   - Public comments only for portal users
   - Both public and private for agents
   - System-generated comments are filtered out

3. **SLA Warnings Not Received**
   - Verify ticket has `tto_escalation_deadline` or `ttr_escalation_deadline` set
   - Check if threshold was already crossed before last check
   - Weekend-aware: Friday/Saturday thresholds are expanded

4. **Team Tickets Not Notified**
   - Verify user is member of the team (`lnkPersonToTeam`)
   - Check if ticket's `team_id` changed since last check
   - Verify ticket is still unassigned (`agent_id = NULL`)

### Reset Timestamps

To force notifications to check last 30 days of changes:

```bash
occ itop:notifications:test-user username --reset
```

---

## FAQ

**Q: How often are notifications checked?**  
A: Every user has their own interval (5-1440 minutes, default 15 minutes). Background jobs run every 5 minutes but only process users whose interval has elapsed.

**Q: Can I disable specific notification types?**  
A: Yes, unless the administrator has marked them as "Forced". Check your personal settings to see available options.

**Q: Will I get notified about my own comments?**  
A: No, the system filters out self-notifications to prevent spam.

**Q: What happens if I miss notifications during downtime?**  
A: Next check will include all changes since last successful check (up to 30 days back on first run).

**Q: Do portal users receive agent notifications?**  
A: No, users marked as `is_portal_only` only receive portal notifications about their own tickets.

**Q: How are SLA warnings calculated?**  
A: Using a "crossing-time" algorithm: For each threshold (24h, 12h, 4h, 1h), calculate when it will be crossed. If that time falls between your last check and now, you get notified.

**Q: Why do I get 72-hour warnings on Friday?**  
A: Weekend-aware logic expands the 24-hour threshold to catch Monday/Tuesday breaches so you can take action before the weekend.

**Q: Can I change my check interval?**  
A: Yes, in Personal Settings → iTop Integration → Notification Check Interval (5-1440 minutes).

**Q: What's the difference between TTO and TTR?**  
A:
- **TTO (Time To Own)**: Deadline for assigning an agent to the ticket
- **TTR (Time To Resolve)**: Deadline for resolving the ticket

**Q: Why are some notifications marked as "Forced"?**  
A: Critical notifications (SLA breaches, priority escalations) are often forced by administrators to ensure important issues aren't missed.

---

## Technical Details

### Architecture

- **Dual Background Jobs**: Portal and Agent jobs run independently every 5 minutes
- **CMDBChangeOp Detection**: Leverages iTop's built-in change tracking
- **Minimal State**: Only stores last check timestamps per user
- **Weekend-Aware SLA**: Dynamic threshold calculation based on day of week
- **Query Optimization**: Skips unnecessary API calls based on user preferences

### Data Storage

**Per-User Settings** (oc_preferences):
- `notification_enabled` - Master toggle (0/1)
- `notification_check_interval` - Custom interval in minutes
- `notification_last_portal_check` - Unix timestamp
- `notification_last_agent_check` - Unix timestamp
- `disabled_portal_notifications` - JSON array or "all"
- `disabled_agent_notifications` - JSON array or "all"

**App Configuration** (oc_appconfig):
- `default_notification_interval` - Default interval for all users
- `portal_notification_config` - JSON map of type → state
- `agent_notification_config` - JSON map of type → state

### API Queries

**Portal Notifications**:
- `CMDBChangeOpSetAttributeScalar` - Status, agent_id changes
- `CMDBChangeOpSetAttributeCaseLog` - Public log entries

**Agent Notifications**:
- `CMDBChangeOpSetAttributeScalar` - agent_id, team_id, priority, SLA flags
- `CMDBChangeOpSetAttributeCaseLog` - Public and private log entries
- Ticket queries for SLA deadline calculations

### Security

- **Data Isolation**: All queries filtered by user's person_id
- **Application Token**: Admin-level token for API access (encrypted storage)
- **No User Credentials**: Personal tokens used once for verification, then discarded
- **Rate Limiting**: Max 20 notifications per user per run
- **Self-Notification Filtering**: User's own changes excluded

---

## Support

For issues or questions:
1. Check this guide's Troubleshooting section
2. Test with OCC commands to diagnose issues
3. Check Nextcloud logs for error messages
4. Review iTop CMDBChangeOp records to verify change tracking
5. Open an issue on GitHub: https://github.com/lexioj/integration_itop/issues
