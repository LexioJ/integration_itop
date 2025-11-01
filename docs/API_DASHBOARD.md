# Dashboard API Endpoints

This document describes the dashboard API endpoints used by the iTop integration's dashboard widgets.

## Overview

The dashboard provides two distinct widgets with separate API endpoints:

1. **Portal Widget** (`/dashboard`) - For all users (portal and agents)
2. **Agent Widget** (`/agent-dashboard`) - For agents only

Both endpoints are protected with `@NoAdminRequired` and require authenticated users.

---

## Portal Dashboard Endpoint

### GET `/apps/integration_itop/dashboard`

Retrieves dashboard data for the current user including their created tickets, status breakdown, and recent activity.

**Route Definition**: `lib/Controller/ItopAPIController.php::getDashboardData()`

**Authentication**: Required (Nextcloud user session)

**Access**: All authenticated users

### Request

```http
GET /apps/integration_itop/dashboard HTTP/1.1
Host: your-nextcloud.com
```

No query parameters required - automatically uses current authenticated user's context.

### Response

**Success (200 OK)**:

```json
{
  "counts": {
    "open": 3,
    "escalated": 1,
    "pending": 1,
    "resolved": 5
  },
  "stats": {
    "by_status": {
      "new": 1,
      "assigned": 1,
      "ongoing": 1,
      "pending": 1,
      "escalated_tto": 0,
      "escalated_ttr": 1,
      "resolved": 5,
      "closed": 0
    }
  },
  "tickets": [
    {
      "id": "1234",
      "ref": "I-000004",
      "title": "Incident by Bob",
      "type": "Incident",
      "status": "ongoing",
      "operational_status": "ongoing",
      "priority": "2",
      "start_date": "2025-10-30 14:23:00",
      "last_update": "2025-10-31 09:15:00",
      "description": "Network connectivity issues",
      "url": "https://itop.example.com/pages/UI.php?operation=details&class=Incident&id=1234"
    },
    {
      "id": "5678",
      "ref": "R-000007",
      "title": "Test Request",
      "type": "UserRequest",
      "status": "new",
      "operational_status": "new",
      "priority": "3",
      "start_date": "2025-10-31 10:00:00",
      "last_update": "2025-10-31 10:05:00",
      "description": "Software installation request",
      "url": "https://itop.example.com/pages/UI.php?operation=details&class=UserRequest&id=5678"
    }
  ],
  "recent_cis": [],
  "itop_url": "https://itop.example.com",
  "display_name": "ServicePoint"
}
```

**Error (500 Internal Server Error)**:

```json
{
  "error": "Failed to connect to iTop API"
}
```

**Error (401 Unauthorized)**:

```json
{
  "error": "Unauthorized"
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `counts` | Object | Ticket counts by status category |
| `counts.open` | Integer | Number of open tickets (new, assigned, ongoing) |
| `counts.escalated` | Integer | Number of escalated tickets (TTO/TTR breached) |
| `counts.pending` | Integer | Number of pending tickets |
| `counts.resolved` | Integer | Number of resolved tickets |
| `stats.by_status` | Object | Detailed breakdown by operational status |
| `tickets` | Array | List of recent tickets (most recent first, max 4) |
| `recent_cis` | Array | Reserved for future CI integration (currently empty) |
| `itop_url` | String | Base URL of the iTop instance |
| `display_name` | String | User-facing name (configured by admin, e.g., "ServicePoint") |

### Ticket Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | String | iTop internal ticket ID |
| `ref` | String | User-visible ticket reference (e.g., "I-000004", "R-000007") |
| `title` | String | Ticket title/summary |
| `type` | String | Ticket class: "Incident" or "UserRequest" |
| `status` | String | Ticket status (for filtering) |
| `operational_status` | String | Detailed operational status |
| `priority` | String | Priority level (1-4) |
| `start_date` | String | Ticket creation timestamp (ISO 8601) |
| `last_update` | String | Last modification timestamp (ISO 8601) |
| `description` | String | Ticket description (HTML may be included) |
| `url` | String | Direct link to ticket in iTop UI |

### Usage Example

```javascript
// In DashboardWidget.vue
async loadDashboard() {
  try {
    const response = await axios.get(
      generateUrl('/apps/integration_itop/dashboard')
    )
    this.counts = response.data.counts
    this.tickets = response.data.tickets
    this.itopUrl = response.data.itop_url
  } catch (error) {
    console.error('Failed to load dashboard', error)
  }
}
```

---

## Agent Dashboard Endpoint

### GET `/apps/integration_itop/agent-dashboard`

Retrieves agent-specific dashboard data including assigned tickets, team queues, SLA metrics, and upcoming changes.

**Route Definition**: `lib/Controller/ItopAPIController.php::getAgentDashboardData()`

**Authentication**: Required (Nextcloud user session)

**Access**: Agent users only (users with `is_portal_only = false` in iTop)

**Widget Visibility**: This widget only appears for users who are not portal-only users.

### Request

```http
GET /apps/integration_itop/agent-dashboard HTTP/1.1
Host: your-nextcloud.com
```

No query parameters required - automatically uses current authenticated user's context.

### Response

**Success (200 OK)**:

```json
{
  "myTickets": [
    {
      "id": "1234",
      "ref": "I-000004",
      "title": "Network connectivity issue",
      "type": "Incident",
      "status": "ongoing",
      "operational_status": "ongoing",
      "priority": "2",
      "start_date": "2025-10-30 14:23:00",
      "last_update": "2025-10-31 09:15:00",
      "url": "https://itop.example.com/pages/UI.php?operation=details&class=Incident&id=1234"
    }
  ],
  "teamTickets": [
    {
      "id": "5678",
      "ref": "I-000012",
      "title": "Server maintenance required",
      "type": "Incident",
      "status": "assigned",
      "operational_status": "assigned",
      "priority": "1",
      "start_date": "2025-10-31 08:00:00",
      "last_update": "2025-10-31 08:30:00",
      "url": "https://itop.example.com/pages/UI.php?operation=details&class=Incident&id=5678"
    }
  ],
  "upcomingChanges": [
    {
      "id": "999",
      "ref": "C-000010",
      "title": "Emergency Test Change",
      "type": "Emergency",
      "status": "planned",
      "start_date": "2025-11-01 16:13:00",
      "end_date": "2025-11-02 16:43:00",
      "url": "https://itop.example.com/pages/UI.php?operation=details&class=Change&id=999"
    }
  ],
  "counts": {
    "my_tickets": 5,
    "my_incidents": 3,
    "my_requests": 2,
    "team_tickets": 12,
    "team_incidents": 8,
    "team_requests": 4,
    "sla_warning_tto": 2,
    "sla_warning_ttr": 1,
    "sla_breaches_tto": 1,
    "sla_breaches_ttr": 3,
    "upcoming_changes": 2
  },
  "itop_url": "https://itop.example.com",
  "display_name": "ServicePoint"
}
```

**Error (500 Internal Server Error)**:

```json
{
  "error": "Failed to fetch team assignments"
}
```

**Error (401 Unauthorized)**:

```json
{
  "error": "Unauthorized"
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `myTickets` | Array | Tickets assigned to the current user (max 20) |
| `teamTickets` | Array | Tickets assigned to user's teams (max 20) |
| `upcomingChanges` | Array | Upcoming changes (max 10) |
| `counts` | Object | Detailed metrics for dashboard display |
| `counts.my_tickets` | Integer | Total tickets assigned to user |
| `counts.my_incidents` | Integer | Incidents assigned to user |
| `counts.my_requests` | Integer | User requests assigned to user |
| `counts.team_tickets` | Integer | Total tickets in team queue |
| `counts.team_incidents` | Integer | Incidents in team queue |
| `counts.team_requests` | Integer | User requests in team queue |
| `counts.sla_warning_tto` | Integer | Tickets approaching TTO deadline (not assigned) |
| `counts.sla_warning_ttr` | Integer | Tickets approaching TTR deadline (not resolved) |
| `counts.sla_breaches_tto` | Integer | Tickets breaching TTO SLA (not assigned) |
| `counts.sla_breaches_ttr` | Integer | Tickets breaching TTR SLA (not resolved) |
| `counts.upcoming_changes` | Integer | Number of upcoming changes |
| `itop_url` | String | Base URL of the iTop instance |
| `display_name` | String | User-facing name configured by admin |

### Change Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | String | iTop internal change ID |
| `ref` | String | Change reference (e.g., "C-000010") |
| `title` | String | Change title/summary |
| `type` | String | Change type: "Emergency", "Normal", or "Routine" |
| `status` | String | Change status |
| `start_date` | String | Planned start timestamp (ISO 8601) |
| `end_date` | String | Planned end timestamp (ISO 8601) |
| `url` | String | Direct link to change in iTop UI |

### SLA Metrics

**TTO (Time To Own)**: Measures how long until a ticket is assigned
- `sla_warning_tto`: Tickets approaching TTO deadline (within 24h)
- `sla_breaches_tto`: Tickets that breached TTO SLA (not assigned in time)

**TTR (Time To Resolve)**: Measures how long until a ticket is resolved
- `sla_warning_ttr`: Tickets approaching TTR deadline (within 24h)
- `sla_breaches_ttr`: Tickets that breached TTR SLA (not resolved in time)

### Usage Example

```javascript
// In AgentDashboardWidget.vue
async loadAgentDashboard() {
  try {
    const response = await axios.get(
      generateUrl('/apps/integration_itop/agent-dashboard')
    )
    this.counts = response.data.counts
    this.myTickets = response.data.myTickets
    this.teamTickets = response.data.teamTickets
    this.upcomingChanges = response.data.upcomingChanges
  } catch (error) {
    console.error('Failed to load agent dashboard', error)
  }
}
```

---

## Common Error Handling

Both endpoints follow the same error handling pattern:

### Authentication Errors

```json
{
  "error": "Unauthorized"
}
```

**HTTP Status**: 401 Unauthorized

**Cause**: User not authenticated or session expired

### API Connection Errors

```json
{
  "error": "Failed to connect to iTop API"
}
```

**HTTP Status**: 500 Internal Server Error

**Cause**: iTop server unreachable or invalid credentials

### Configuration Errors

```json
{
  "error": "iTop not configured for this user"
}
```

**HTTP Status**: 500 Internal Server Error

**Cause**: Admin token not configured or user not linked to iTop Person

---

## Implementation Notes

### Data Sources

Both endpoints retrieve data through `ItopAPIService.php`, which:
1. Uses admin application token for API authentication
2. Filters results by user's Person ID (security)
3. Caches user profile data for 30 minutes
4. Implements retry logic for failed requests

### Performance

- Portal dashboard: ~2-3 API calls to iTop
- Agent dashboard: ~5-7 API calls to iTop
- Response times: typically 200-500ms
- Caching: Profile data cached (30min TTL), ticket data NOT cached (real-time)

### Security

- All endpoints require Nextcloud authentication
- iTop API uses admin token (stored encrypted)
- Results filtered by user's Person ID
- No direct user credential exposure

### Widget Visibility

The agent widget visibility is controlled at the PHP level:

```php
// lib/Dashboard/ItopAgentWidget.php
public function isEnabled(): bool {
    $profile = $this->profileService->getUserProfile($this->userId);
    return $profile !== null && !($profile['is_portal_only'] ?? true);
}
```

Only non-portal users see the agent widget.

---

## Related Documentation

- [README.md](../README.md) - Dashboard feature overview
- [PLAN_DASHBOARD.md](./PLAN_DASHBOARD.md) - Implementation plan and status
- [lib/Controller/ItopAPIController.php](../lib/Controller/ItopAPIController.php) - Controller source
- [lib/Service/ItopAPIService.php](../lib/Service/ItopAPIService.php) - API service layer
