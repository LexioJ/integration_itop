# Dashboard Widget Enhancement - Implementation Plan

## Overview

This document outlines the implementation plan for enhancing the existing iTop Dashboard widget in the Nextcloud integration. The widget currently displays only assigned tickets but needs to be expanded to show a comprehensive view of user tickets and configuration items.

## Current State Analysis

### Existing Implementation
- **Location**: `lib/Dashboard/ItopWidget.php`
- **Current Features**:
  - Shows assigned tickets only (via `getAssignedTickets()`)
  - Limited to 7 tickets
  - Basic ticket information (title, status, caller, creation date)
  - Priority-based overlay icons
  - Links to iTop ticket details
- **Registration**: Widget is registered in `Application.php` during bootstrap
- **Authentication**: Checks for token presence (legacy model - needs update for dual-token)

### Issues with Current Implementation
1. **Authentication Model**: Uses deprecated `token` field instead of dual-token architecture (application_token + person_id)
2. **Limited Scope**: Only shows assigned tickets, not user-created tickets
3. **No CI Display**: Configuration Items are not shown despite being core feature in Phase 3-5
4. **Missing Method**: Calls `getAssignedTickets()` which doesn't exist in `ItopAPIService`
5. **No Ticket Type Support**: Doesn't distinguish between UserRequest and Incident
6. **No Status Filtering**: Shows all tickets without status-based filtering

## Project Scope

### ✅ In Scope
- **Ticket Summary View**: Display user's created tickets (UserRequest + Incident)
- **Status-Based Sections**: Group tickets by status (open, escalated, pending, etc.)
- **Priority Indicators**: Visual priority badges/icons
- **Ticket Counts**: Show counts by status and priority
- **Quick Actions**: Links to iTop ticket details and create new ticket
- **Configuration Item Highlights**: Show recently accessed/relevant CIs
- **Dual-Token Authentication**: Migrate to person_id based authentication
- **Profile-Aware Display**: Portal users see only their tickets, power users see assigned + created

### ❌ Out of Scope (Future)
- Ticket creation directly from dashboard
- Bulk ticket operations
- Advanced filtering/sorting controls
- Ticket status updates/comments
- CI relationship management
- Custom dashboard layouts

## Technical Architecture

### Backend Enhancement

#### 1. Update ItopAPIService.php
Add new methods to support dashboard requirements:

```php
/**
 * Get tickets created by user grouped by status
 * @param string $userId
 * @return array ['open' => [...], 'pending' => [...], 'escalated' => [...], 'resolved' => [...]]
 */
public function getUserTicketsByStatus(string $userId): array

/**
 * Get recently accessed or relevant CIs for user
 * @param string $userId
 * @param int $limit
 * @return array
 */
public function getUserRecentCIs(string $userId, int $limit = 5): array
```

#### 2. Update ItopWidget.php
**Current Issues to Fix**:
- Replace `token` check with `person_id` check (dual-token model)
- Remove `getAssignedTickets()` call (method doesn't exist)
- Use `getUserCreatedTickets()` or new `getUserTicketsByStatus()` method
- Add CI display using existing CI preview infrastructure

**New Structure**:
```php
public function getItems(string $userId): array {
    // Check person_id instead of token
    if (!$this->hasPersonId($userId)) {
        return [];
    }
    
    // Get ticket summary
    $ticketStats = $this->getUserTicketsByStatus($userId);
    
    // Get recent CIs (if enabled)
    $recentCIs = $this->getUserRecentCIs($userId, 3);
    
    // Format dashboard items
    return $this->formatDashboardItems($ticketStats, $recentCIs);
}
```

### Frontend Enhancement

#### 1. Dashboard Script (js/integration_itop-dashboard.js)
**Current State**: File needs to be created or updated
**Purpose**: Handle dashboard widget interactivity and data display

**Features to Implement**:
- Fetch ticket summary data via API endpoint
- Display ticket counts by status with color coding
- Show priority distribution
- Render CI preview cards
- Implement refresh mechanism
- Handle error states gracefully

#### 2. Dashboard Styles (css/dashboard.css)
**New File**: Create dedicated styles for dashboard widget

**Components**:
- Ticket status badges (color-coded)
- Priority indicators (high/medium/low)
- CI preview cards (compact version of rich preview)
- Empty state messages
- Loading spinners
- Responsive layout for mobile

### API Routes

#### New Endpoints in appinfo/routes.php
```php
[
    'name' => 'ItopAPI#getDashboardData',
    'url' => '/dashboard',
    'verb' => 'GET'
],
[
    'name' => 'ItopAPI#getRecentCIs',
    'url' => '/recent-cis',
    'verb' => 'GET'
]
```

#### Controller Method (lib/Controller/ItopAPIController.php)
```php
/**
 * Get dashboard data for current user
 * @NoAdminRequired
 */
public function getDashboardData(): DataResponse {
    // Get ticket statistics
    $ticketStats = $this->itopAPIService->getUserTicketsByStatus($this->userId);
    
    // Get ticket counts
    $counts = $this->itopAPIService->getUserCreatedTicketsCount($this->userId);
    
    // Get recent CIs if enabled
    $recentCIs = [];
    if ($this->isCIBrowsingEnabled()) {
        $recentCIs = $this->itopAPIService->getUserRecentCIs($this->userId, 5);
    }
    
    return new DataResponse([
        'stats' => $ticketStats,
        'counts' => $counts,
        'recent_cis' => $recentCIs
    ]);
}
```

## Implementation Phases

### Phase 1: Backend Fixes ✅ DONE
**Priority**: Critical - Widget currently non-functional

**Tasks**:
1. [x] Analyze existing `ItopAPIService` methods
   - `getUserCreatedTickets()` exists ✅
   - `getUserCreatedTicketsCount()` exists ✅
   - No need for `getAssignedTickets()` - method doesn't exist
2. [x] Update `ItopWidget.php` authentication check
   - Replace `token` with `person_id` check
   - Update `load()` method to use person_id
   - Update `getItems()` to use person_id
3. [x] Fix `getItems()` method
   - Replace `getAssignedTickets()` with `getUserCreatedTickets()`
   - Add error handling for missing person_id
   - Update ticket formatting for both UserRequest and Incident
4. [x] Add dashboard data endpoint
   - Create `getDashboardData()` in ItopAPIController
   - Return ticket counts and status breakdown
5. [ ] Test with real iTop instance
   - Verify ticket display works
   - Test with portal-only and power users

**Files to Modify**:
- `lib/Dashboard/ItopWidget.php` - Fix authentication and data fetching
- `lib/Controller/ItopAPIController.php` - Add dashboard endpoint
- `appinfo/routes.php` - Register new routes

**Expected Outcome**: Dashboard widget displays user's created tickets correctly
**Status Update (2025-10-29)**: Implemented person_id-based checks and switched to `getUserCreatedTickets()`; added `/dashboard` endpoint and route returning counts and by-status breakdown.

### Phase 2: Enhanced Ticket Display ✅ COMPLETED
**Priority**: High - Improve user experience

**Tasks**:
1. [x] Implement status-based grouping
   - Add `getUserTicketsByStatus()` method in ItopAPIService
   - Group tickets: open, escalated, pending, resolved, closed
   - Add ticket type distinction (UserRequest vs Incident)
2. [x] Create dashboard JavaScript bundle
   - Setup Vite config for dashboard entry point
   - Fetch data from new API endpoint
   - [x] Implement interactive ticket list (top 4 recent tickets)
   - [x] Add status filter toggles (My Open, class filters, recently updated)
3. [x] Design dashboard UI
   - [x] Status cards with counts (compact badges)
   - [x] Color coding and badges
   - [x] SVG icons per ticket state (new, escalated, deadline, closed)
   - [x] Quick action buttons (Refresh, New Ticket)
   - [x] Responsive layout (breakpoints for cards and statuses)
4. [x] Add dashboard styles
   - [x] Create inline dashboard styles in DashboardWidget.vue
   - [x] Status badge colors matching iTop
   - [x] Priority emoji indicators (🔴🟠🟡🟢)
   - [x] Status emoji indicators (🆕👥⏳⚠️✅☑️❌)
   - [x] Mobile-responsive grid
5. [x] Enhanced UX features
   - [x] Tooltips on status emoji (shows status label)
   - [x] Tooltips on priority emoji (shows priority level)
   - [x] Tooltips on ticket title (shows full ticket details with sanitized description)
   - [x] Tooltips on relative time (shows full timestamp with "Last updated:" prefix)
   - [x] Click-to-open ticket functionality (removed hover effects)
   - [x] Inline metadata display (status • priority • time)

**Files Created/Modified**:
- `src/dashboard.js` - Main dashboard Vue app [created]
- `src/views/DashboardWidget.vue` - Dashboard component with full feature set [created]
- `vite.config.ts` - Add dashboard build entry [updated]
- `lib/Controller/ItopAPIController.php` - Dashboard data endpoint [updated]

**Expected Outcome**: Rich, interactive dashboard with status-based ticket organization
**Status Update (2025-11-01)**: ✅ FULLY IMPLEMENTED AND WORKING

- ✅ Dual-widget design
- ✅ Compact status badges showing ticket counts
- ✅ most recent tickets displayed with type icons and status indicators
- ✅ Agent widget: My Work, Team Queue, SLA tracking
- ✅ Change management section
- ✅ One Change displayed with time window
- ✅ Refresh and action buttons
- ✅ Priority indicators (red/orange/green) on tickets
- ✅ Status badges (Ongoing, Resolved) with relative timestamps
- ✅ Responsive layout

**Key Implementation Highlights**:
- Agent widget displays comprehensive metrics: My Work, Team Queue, SLA Warnings/Breaches
- Change widget integration showing emergency change with start/end times
- Clean, professional UI

### Phase 3: CI Integration ⏭️ SKIPPED
**Priority**: ~~Medium~~ Deferred - Widget space constraints
**Status**: SKIPPED - No space available in current widget layout

**Reason for Skipping**:
Due to space constraints in the dashboard widget (limited to 4 tickets + action buttons), there is insufficient room to add CI integration while maintaining a clean, usable interface. The widget is optimized for ticket viewing, and adding CI cards would create information overload.

**Alternative Solutions**:
1. **Agent Widget**: CI browsing could be added to the proposed Agent Dashboard widget (Phase 5)
2. **Dedicated CI Widget**: Create a separate "iTop Configuration Items" dashboard widget
3. **Smart Picker Integration**: CIs are already accessible via the existing Smart Picker feature

**Original Tasks** (deferred):
- [ ] Implement recent CI tracking via `getUserRecentCIs()` method
- [ ] Create CI dashboard cards (compact version of rich preview)
- [ ] Add "Recently Viewed CIs" section to dashboard
- [ ] Test CI display with different CI classes

**Status Update (2025-10-31)**: Skipped to maintain clean, focused dashboard UI

### Phase 4: Performance & Polish 🔄 TODO
**Priority**: Low - Optimization and refinement

**Tasks**:
1. [ ] Implement dashboard caching
   - Cache dashboard data with 60s TTL
   - Add refresh button for manual reload
   - Use existing CacheService infrastructure
2. [ ] Add loading states
   - Skeleton loaders for tickets and CIs
   - Smooth transitions
   - Error state handling
3. [ ] Optimize API calls
   - Batch ticket and CI requests
   - Minimize redundant queries
   - Use existing query cache
4. [ ] Accessibility improvements
   - Keyboard navigation
   - Screen reader support
   - High contrast mode
5. [ ] Add dashboard configuration
   - Personal settings toggle for dashboard
   - Choose what to display (tickets only, tickets + CIs, etc.)
   - Set refresh interval

**Files to Modify**:
- `lib/Service/CacheService.php` - Add dashboard cache TTL
- `src/views/DashboardWidget.vue` - Loading states and a11y
- `lib/Settings/Personal.php` - Dashboard preferences
- `templates/personalSettings.php` - Dashboard settings UI

**Expected Outcome**: Fast, accessible, configurable dashboard experience

### Phase 5: Dual Widget Architecture ✅ COMPLETED
**Priority**: Medium - Enhanced agent experience
**Status**: ✅ FULLY IMPLEMENTED

**Completed Tasks**:
1. [x] Fully read and understand ## PROPOSED: Dual Dashboard Widgets (Portal + Agent)
2. [x] Create `ItopAgentWidget.php` implementing `IConditionalWidget`
3. [x] Add team membership query methods to `ItopAPIService`
4. [x] Add agent-specific ticket query methods
5. [x] Create `/agent-dashboard` API endpoint
6. [x] Create `AgentDashboardWidget.vue` component
7. [x] Add agent dashboard build entry to vite.config
8. [x] Register both widgets in `Application.php`
9. [x] Test conditional visibility (portal vs agent users)
10. [x] Test agent-specific data fetching
11. [x] Add translations for agent widget strings - ✅ COMPLETED
12. [x] Update README with dual-widget documentation - ✅ COMPLETED

**Translation Status (Task 11 - ✅ COMPLETED)**:
- ✅ Added 35+ translation strings to all 4 language files (en, de, de_DE, fr)
- ✅ Fixed all hardcoded strings in DashboardWidget.vue (status labels, priorities, tooltips, relative time)
- ✅ Fixed all hardcoded strings in AgentDashboardWidget.vue (change status labels, template text)
- ✅ Fixed template to use getStatusLabel() for dynamic status display
- ✅ Added Vue.prototype.t/n/OC in dashboard.js and agentDashboard.js
- ✅ Registered IL10N service in Application.php for proper translation injection
- ✅ Built successfully with npm run build
- ✅ Translations working in browser (verified with French/German locales)
- ✅ Additional improvements:
  - Shortened French translations to prevent text overflow ("Créer" instead of "Nouveau ticket")
  - Optimized German translations ("SLA-Warnung" singular form)
  - Updated app icons (app.svg, app-dark.svg) to match Nextcloud design system

**Files to Create**:
- `lib/Dashboard/ItopPortalWidget.php` (rename from ItopWidget.php)
- `lib/Dashboard/ItopAgentWidget.php` (new)
- `src/views/AgentDashboardWidget.vue` (new)
- `src/agentDashboard.js` (new)

**Files to Modify**:
- `lib/Service/ItopAPIService.php` - Add agent query methods
- `lib/Controller/ItopAPIController.php` - Add getAgentDashboardData()
- `appinfo/routes.php` - Add /agent-dashboard route
- `lib/AppInfo/Application.php` - Register both widgets
- `vite.config.ts` - Add agent dashboard build entry
- `l10n/en.json` - Add agent widget strings

**Expected Outcome**:
- Portal users see "iTop - My Tickets" widget only
- Agent users see both "iTop - My Tickets" and "iTop - Agent Dashboard" widgets
- Agent widget shows assigned tickets, team queue, escalations, and upcoming changes
- Conditional visibility works correctly based on user profile

### Phase 6: Testing & Documentation 📝 TODO

**Tasks**:
1. [ ] Write PHPUnit tests
   - Test `getUserTicketsByStatus()` method
   - Test `getUserRecentCIs()` method
   - Test dashboard data endpoint
   - Mock iTop API responses
2. [ ] Manual testing
   - Test with portal-only user
   - Test with power user
   - Test with no tickets/CIs
   - Test error scenarios (no person_id, API errors)
3. [~] Update documentation - IN PROGRESS
   - [x] Add dashboard section to README (dual-widget architecture fully documented)
   - [x] Document dashboard API endpoints (API_DASHBOARD.md created)
   - [x] Update PLAN_CI_BROWSING.md with dashboard status
   - [ ] Add dashboard screenshots to docs/ (optional - requires real iTop instance)
4. [x] Update translations - ✅ COMPLETED
   - [x] Add dashboard strings to l10n/en.json (35+ strings added)
   - [x] Translate to German (de.json, de_DE.json)
   - [x] Translate to French (fr.json)
   - [x] Refactor all hardcoded strings to use t() function
   - [x] Add Vue.prototype.t/n/OC to dashboard entry points
   - [x] Register IL10N service in Application.php for proper injection
   - [x] Translations working correctly in all languages
   - [x] Text optimization (shortened French/German strings for better UI fit)

**Files to Create/Modify**:
- `tests/unit/Service/ItopAPIServiceDashboardTest.php` - New test file
- `tests/unit/Controller/ItopAPIControllerTest.php` - Update existing
- `docs/dashboard.md` - New documentation
- `l10n/*.json` - Translation updates
- `README.md` - Feature documentation

**Expected Outcome**: Fully tested, documented dashboard feature

## Data Structure

### Dashboard Data Response
```json
{
  "stats": {
    "open": 5,
    "escalated": 2,
    "pending": 3,
    "resolved": 10,
    "closed": 45
  },
  "counts": {
    "incidents": 8,
    "requests": 12,
    "total": 20
  },
  "tickets": {
    "open": [
      {
        "type": "UserRequest",
        "id": 123,
        "ref": "R-000123",
        "title": "Cannot access email",
        "status": "new",
        "priority": "high",
        "created": "2025-01-15T10:30:00Z"
      }
    ],
    "escalated": [...],
    "pending": [...]
  },
  "recent_cis": [
    {
      "class": "PC",
      "id": 45,
      "name": "LAPTOP-001",
      "status": "production",
      "org_name": "IT Department",
      "url": "http://itop/pages/UI.php?operation=details&class=PC&id=45"
    }
  ]
}
```

## UI Mockup (Actual Implementation - Phase 2)

```
┌──────────────────────────────────────────────────────────┐
│  🗂️  <DisplayName>                                        │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  5 Tickets  ( 3 Open )  ( 1 Pending )  ( 1 Resolved )   │
│                                                          │
├──────────────────────────────────────────────────────────┤
│  💬  R-000007:  Test Request                            │
│      Ongoing  •  🟢  •  🕐 11 hours ago                  │
├──────────────────────────────────────────────────────────┤
│  🔴  I-000004:  Incident by Bo...                  [NEW] │
│      Ongoing  •  🔴  •  🕐 3 days ago                    │
├──────────────────────────────────────────────────────────┤
│  ⚠️  I-000003:  Incident opene...                       │
│      Ongoing  •  🟠  •  🕐 2 weeks ago                   │
├──────────────────────────────────────────────────────────┤
│  ✅  R-000002:  Test Request...                         │
│      Resolved  •  🟢  •  🕐 2 weeks ago                  │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  [ 🔄 Refresh ]          [ ➕ New Ticket ]               │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

**Key Features Implemented:**
- **Compact Header**: Shows total ticket count with status badge breakdown
- **Status Badges**: Pill-style badges for Open, Pending, Resolved counts
- **Ticket Cards**: 4 most recent tickets with:
  - Type-specific icons (💬 UserRequest, 🔴/⚠️ Incident)
  - Reference and truncated title
  - Status emoji indicators (🆕👥⏳⚠️✅)
  - Priority emoji indicators (🔴🟠🟡🟢)
  - Relative timestamps with tooltips
  - "NEW" badge for recently created tickets
- **Action Buttons**: Refresh and New Ticket at the bottom
- **Responsive Design**: Mobile-friendly layout with proper breakpoints
- **No CI Section**: Space constraints prevent CI integration in this widget

## Configuration

### Admin Settings
- Enable/disable dashboard widget globally
- Set default ticket limit for dashboard
- Configure dashboard refresh interval
- Toggle CI display on/off

### Personal Settings
- Choose dashboard sections to display
- Set personal ticket limit
- Configure refresh behavior
- Select which ticket statuses to show

## Performance Targets

- **Dashboard Load Time**: <500ms (cached)
- **API Response Time**: <300ms for dashboard data
- **Refresh Interval**: 60s default (configurable)
- **Cache TTL**: 60s for dashboard data, 5 minutes for CI list
- **Memory Usage**: <2MB additional for dashboard widget

## Translation Strings

### New Strings for l10n/en.json
```json
{
  "Your Tickets": "Your Tickets",
  "Open Tickets": "Open Tickets",
  "Escalated": "Escalated",
  "Pending": "Pending",
  "Resolved": "Resolved",
  "Recent Tickets": "Recent Tickets",
  "Recently Viewed CIs": "Recently Viewed CIs",
  "Create New Ticket": "Create New Ticket",
  "View All in iTop": "View All in iTop",
  "No tickets found": "No tickets found",
  "No CIs accessed recently": "No CIs accessed recently",
  "Dashboard refresh failed": "Dashboard refresh failed",
  "Loading dashboard...": "Loading dashboard..."
}
```

## Security Considerations

1. **Authentication**: Use dual-token model (person_id based)
2. **Data Isolation**: All queries filtered by person_id
3. **Profile-Aware**: Portal users see only their created tickets
4. **No Write Operations**: Dashboard is read-only
5. **Rate Limiting**: Dashboard refresh limited to 1 request per 30 seconds
6. **Cache Security**: Dashboard data cached per-user, not shared

## Success Metrics

- **User Engagement**: >60% of configured users view dashboard within 7 days
- **Performance**: 95th percentile load time <1s
- **Reliability**: <0.5% error rate for dashboard data fetching
- **User Satisfaction**: Positive feedback on ticket overview functionality

## Dependencies

### Required
- Phase 3-5 CI browsing infrastructure (already implemented)
- Dual-token authentication model (already implemented)
- Existing ItopAPIService methods:
  - `getUserCreatedTickets()` ✅
  - `getUserCreatedTicketsCount()` ✅
  - `getCurrentUser()` ✅

### New Dependencies
- None - uses existing Nextcloud Dashboard API

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|---------|------------|
| Dashboard widget conflicts with existing dashboards | Low | Test thoroughly with default Nextcloud widgets |
| Performance issues with many tickets | Medium | Implement pagination and limit default display to 10 tickets |
| API rate limiting with frequent refreshes | Low | Default refresh to 60s, cache aggressively |
| CI data stale in dashboard | Low | Show "last updated" timestamp, add manual refresh |

## Testing Plan

### Unit Tests
- [x] `ItopAPIService::getUserCreatedTickets()` - Exists
- [x] `ItopAPIService::getUserCreatedTicketsCount()` - Exists
- [ ] `ItopAPIService::getUserTicketsByStatus()` - To be implemented
- [ ] `ItopAPIService::getUserRecentCIs()` - To be implemented
- [ ] `ItopAPIController::getDashboardData()` - To be implemented

### Integration Tests
- [ ] Dashboard widget loads correctly
- [ ] Ticket data fetches and displays
- [ ] CI preview cards render
- [ ] Portal-only user sees filtered data
- [ ] Power user sees full data
- [ ] Error states handled gracefully

### Manual Testing Checklist
- [ ] Dashboard displays on Nextcloud home
- [ ] Ticket counts match reality
- [ ] Status badges correct colors
- [ ] Links to iTop work
- [ ] CI cards display properly
- [ ] Refresh button works
- [ ] Empty states display correctly
- [ ] Mobile responsive layout
- [ ] Dark mode compatible

## Rollout Plan

1. **Phase 1**: ✅ COMPLETED - Backend fixes deployed (fixes broken widget)
2. **Phase 2**: ✅ COMPLETED - Enhanced ticket display fully implemented
3. **Phase 3**: ⏭️ SKIPPED - CI integration deferred due to space constraints
4. **Phase 4**: 🔄 IN PROGRESS - Performance optimization, caching, accessibility improvements
5. **Phase 5**: 🔄 IN PROGRESS - Dual widget architecture implemented (Portal + Agent widgets working)
6. **Phase 6**: 🔄 NEXT - Testing, optimization, and documentation

**Current Status (2025-11-01)**:
- ✅ Core dashboard functionality complete and working
- ✅ Dual widget architecture live (Portal + Agent widgets)
- 🔄 Translation integration in progress (blocking issue with Vue i18n)
- 🔄 Ready for testing phase once translations resolved
- 📝 Documentation updates pending (README dual-widget section)

## Future Enhancements

### Post-v1 Features
- Create tickets directly from dashboard
- Quick status updates (mark as resolved, etc.)
- Ticket assignment changes
- Add/remove watchers
- Inline comment threads
- Custom dashboard layouts
- Widget size options (small/medium/large)
- Multiple widget instances with filters

---

## PROPOSED: Dual Dashboard Widgets (Portal + Agent)

### Overview
Based on investigation, it is **fully feasible** to deliver two separate dashboard widgets following the pattern used by the Nextcloud Deck app.

### Architecture Findings

#### Multiple Widgets Are Supported
Nextcloud allows multiple dashboard widgets from a single app. The Deck app demonstrates this pattern:
- [DeckWidgetUpcoming.php](/Users/lexioj/github/deck/lib/Dashboard/DeckWidgetUpcoming.php)
- [DeckWidgetToday.php](/Users/lexioj/github/deck/lib/Dashboard/DeckWidgetToday.php)
- [DeckWidgetTomorrow.php](/Users/lexioj/github/deck/lib/Dashboard/DeckWidgetTomorrow.php)

Each widget is registered separately in [Application.php:130-132](/Users/lexioj/github/deck/lib/AppInfo/Application.php#L130-L132):
```php
$context->registerDashboardWidget(DeckWidgetUpcoming::class);
$context->registerDashboardWidget(DeckWidgetToday::class);
$context->registerDashboardWidget(DeckWidgetTomorrow::class);
```

#### Conditional Visibility Is Supported
The `IConditionalWidget` interface allows widgets to control their visibility per-user via an `isEnabled()` method. This is perfect for showing the Agent widget only to non-portal users.

```php
interface IConditionalWidget extends IWidget {
    public function isEnabled(): bool;
}
```

### Proposed Widget Design

#### Widget 1: iTop Portal Widget (General - already exists)
**Target Audience**: All users (portal-only and agents)
**Widget ID**: `integration_itop_portal`
**Title**: `{DisplayName}`

**Features**:
- User's created tickets (UserRequest + Incident)
- Status breakdown (Open, Pending, Escalated, Resolved)
- Recent 4 tickets with quick links
- Recently viewed CIs (optional)
- "Create New Ticket" button
- Matches current Phase 2 implementation

**Visibility**: Always enabled for users with `person_id` configured

#### Widget 2: iTop Agent Widget (Power Users)
**Target Audience**: iTop Agents only (users with `is_portal_only = false`)
**Widget ID**: `integration_itop_agent`
**Title**: `{DisplayName} - Agent`

**Features**:
1. **My Open Tickets**
   - Tickets assigned to me (`agent_id = person_id`)
   - Count and list view
   - Priority breakdown

2. **Team Tickets**
   - Open tickets assigned to groups/teams I belong to
   - Requires querying `Team` memberships via iTop API
   - Group-level assignment tracking

3. **Escalated Tickets**
   - Escalated tickets in my teams
   - Filter by `status = escalated` within team scope
   - Priority indicators

4. **Upcoming Changes**
   - Change tickets with upcoming implementation dates
   - Query `Change` class where `start_date` is near-future
   - Filter by team membership or CI ownership

5. **Additional Suggestions**:
   - **Recently Closed by Me**: Tickets I recently resolved (last 7 days)
   - **Pending Customer Response**: Tickets waiting on caller reply
   - **SLA Breaches**: Tickets approaching or exceeding SLA deadlines
   - **Recently Viewed CIs**: CIs I've accessed (reuse from portal widget)

**Visibility**: Only enabled if `ProfileService->isPortalOnly($userId) === false`

### 📊 Visibility Logic Summary

How widgets appear for different user types:

| User Type | person_id | is_portal_only | iTop Widget | iTop - Agent Widget |
|-----------|-----------|----------------|-------------|---------------------|
| Unconfigured | ❌ No | N/A | ❌ Hidden | ❌ Hidden |
| Portal User | ✅ Yes | ✅ true | ✅ Visible | ❌ Hidden |
| Agent User | ✅ Yes | ❌ false | ✅ Visible | ✅ Visible |

**Logic Details**:
- **ItopWidget** (portal widget):
  - Checks `person_id` via `isEnabled()` method
  - Hidden if user hasn't configured iTop connection
  - Visible once `person_id` is set (regardless of portal/agent status)

- **ItopAgentWidget** (agent widget):
  - Checks both `person_id` AND `ProfileService->isPortalOnly()` via `isEnabled()` method
  - Hidden if no `person_id` configured
  - Hidden if user is portal-only (`is_portal_only = true`)
  - Visible only for agents (`is_portal_only = false`)

**Implementation**:
Both widgets implement `IConditionalWidget` interface with `isEnabled(): bool` method that controls visibility automatically.

### Implementation Requirements

#### New Backend Components

##### 1. New Widget Classes
```
lib/Dashboard/
├── ItopWidget.php              (existing, implements IConditionalWidget look for person_id)
└── ItopAgentWidget.php         (agent-only widget, implements IConditionalWidget)
```

##### 2. New ItopAPIService Methods
```php
// Agent-specific ticket queries
public function getMyAssignedTickets(string $userId): array
public function getTeamAssignedTickets(string $userId): array
public function getEscalatedTicketsForMyTeams(string $userId): array
public function getUpcomingChanges(string $userId): array

// Team membership queries
public function getUserTeams(string $userId): array
public function getTeamTickets(string $userId, array $teamIds, string $status = ''): array

// SLA and metrics
public function getTicketsNearingSLA(string $userId, array $teamIds): array
public function getPendingCustomerTickets(string $userId): array
```

##### 3. New API Controller Endpoints
```php
// lib/Controller/ItopAPIController.php
public function getAgentDashboardData(): DataResponse {
    // Returns:
    // - myTickets: assigned to me
    // - teamTickets: assigned to my teams
    // - escalated: escalated in my teams
    // - upcomingChanges: relevant changes
    // - counts: ticket counts by category
}
```

##### 4. New Routes
```php
// appinfo/routes.php
['name' => 'ItopAPI#getAgentDashboardData', 'url' => '/agent-dashboard', 'verb' => 'GET'],
```

#### Frontend Components

##### 1. New Vue Components
```
src/views/
├── DashboardWidget.vue         (existing - portal widget)
├── AgentDashboardWidget.vue    (new - agent widget)
```

##### 2. New Build Entries
```typescript
// vite.config.ts
build: {
  rollupOptions: {
    input: {
      dashboard: './src/dashboard.js',           // existing
      agentDashboard: './src/agentDashboard.js', // new
    }
  }
}
```

##### 3. New JavaScript Entry Points
```
src/
├── dashboard.js           (existing - portal widget)
└── agentDashboard.js      (new - agent widget)
```

#### Registration Changes

##### Application.php Updates
```php
// lib/AppInfo/Application.php
public function register(IRegistrationContext $context): void {
    // Register both widgets
    $context->registerDashboardWidget(ItopPortalWidget::class);
    $context->registerDashboardWidget(ItopAgentWidget::class);

    // ... existing registrations
}
```

##### ItopAgentWidget.php Implementation
```php
namespace OCA\Itop\Dashboard;

use OCA\Itop\Service\ProfileService;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IWidget;

class ItopAgentWidget implements IWidget, IConditionalWidget {

    public function __construct(
        private ProfileService $profileService,
        private ?string $userId,
        // ... other dependencies
    ) {}

    public function getId(): string {
        return 'integration_itop_agent';
    }

    public function getTitle(): string {
        return $this->l10n->t('%s - Agent Dashboard', [$displayName]);
    }

    public function isEnabled(): bool {
        if ($this->userId === null) {
            return false;
        }

        // Only show to non-portal users (agents)
        try {
            return !$this->profileService->isPortalOnly($this->userId);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function load(): void {
        if ($this->userId !== null && $this->isEnabled()) {
            Util::addScript(Application::APP_ID, 'integration_itop-agent-dashboard');
        }
    }
}
```

### iTop API Considerations

#### Team Membership Query
To get teams a user belongs to:
```php
$params = [
    'operation' => 'core/get',
    'class' => 'Team',
    'key' => "SELECT Team AS t JOIN lnkPersonToTeam AS l ON l.team_id = t.id WHERE l.person_id = {$personId}"
];
```

#### Team Ticket Query
To get tickets assigned to specific teams:
```php
// For UserRequest
$params = [
    'operation' => 'core/get',
    'class' => 'UserRequest',
    'key' => "SELECT UserRequest WHERE team_id IN (" . implode(',', $teamIds) . ") AND status != 'closed'"
];

// For Incident
$params = [
    'operation' => 'core/get',
    'class' => 'Incident',
    'key' => "SELECT Incident WHERE team_id IN (" . implode(',', $teamIds) . ") AND status != 'closed'"
];
```

#### Upcoming Changes Query
```php
$params = [
    'operation' => 'core/get',
    'class' => 'Change',
    'key' => "SELECT Change WHERE start_date > NOW() AND start_date < DATE_ADD(NOW(), INTERVAL 7 DAY) AND status IN ('approved', 'planned')",
    'output_fields' => 'id,ref,title,description,start_date,status,impact,priority'
];
```

### UX Design Considerations

#### Agent Widget (New)
```
┌────────────────────────────────────────────────┐
│ <DisplayName> - Agent                          │
├────────────────────────────────────────────────┤
│ My Work      Team Queue    Escalated           │
│ 👤 8 tickets 👥 16 tickets ⚠️ 1 tickets         │
│                                                │
│  👤 My Work                 👥  Team Queue.     │
│ ┌──────────────────────┐  ┌──────────────────┐ │
│ │ 5 Incidents          │  │  12 Incidents    │ │
│ │ 3 User Request       │  │  4 User Request  │ │
│ └──────────────────────┘  └──────────────────┘ │
│  ⚠️ Escalated              🚨 SLA Breaches      │
│ ┌──────────────────────┐  ┌──────────────────┐ │
│ │ 1 Incidents          │  │  2 Incidents     │ │
│ │ 0 User Request       │  │  0 User Request  │ │
│ └──────────────────────┘  └──────────────────┘ │
│ Upcoming Changes (3)                           │
│ ┌────────────────────────────────────────────┐ │
│ │ C-000023: Firewall update                  │ │
│ │ 📅 Tomorrow 2:00 AM                        │ │
│ └────────────────────────────────────────────┘ │
│                                                │
│ [ Refresh ] [ View All Tickets ]               │
└────────────────────────────────────────────────┘
```

### Translation Strings

#### New Strings for l10n/en.json
```json
{
  "Agent Dashboard": "Agent Dashboard",
  "%s - Agent Dashboard": "%s - Agent Dashboard",
  "My Assigned Tickets": "My Assigned Tickets",
  "Team Queue": "Team Queue",
  "Escalated Tickets": "Escalated Tickets",
  "Upcoming Changes": "Upcoming Changes",
  "My Work": "My Work",
  "Recently Closed by Me": "Recently Closed by Me",
  "Pending Customer Response": "Pending Customer Response",
  "SLA Breaches": "SLA Breaches",
  "unassigned": "unassigned",
  "assigned %s ago": "assigned %s ago",
  "Team %s": "Team %s",
  "No assigned tickets": "No assigned tickets",
  "No team tickets": "No team tickets",
  "No upcoming changes": "No upcoming changes",
  "View All Tickets": "View All Tickets"
}
```

### Implementation Phases

#### Phase 6: Dual Widget Architecture 🔄 TODO
**Priority**: Medium - Enhanced agent experience
**Depends on**: Phase 1-2 completion

**Tasks**:
1. [ ] Rename existing `ItopWidget.php` to `ItopPortalWidget.php`
2. [ ] Create `ItopAgentWidget.php` implementing `IConditionalWidget`
3. [ ] Add team membership query methods to `ItopAPIService`
4. [ ] Add agent-specific ticket query methods
5. [ ] Create `/agent-dashboard` API endpoint
6. [ ] Create `AgentDashboardWidget.vue` component
7. [ ] Add agent dashboard build entry to vite.config
8. [ ] Register both widgets in `Application.php`
9. [ ] Test conditional visibility (portal vs agent users)
10. [ ] Test agent-specific data fetching
11. [ ] Add translations for agent widget strings
12. [ ] Update README with dual-widget documentation

**Files to Create**:
- `lib/Dashboard/ItopPortalWidget.php` (rename from ItopWidget.php)
- `lib/Dashboard/ItopAgentWidget.php` (new)
- `src/views/AgentDashboardWidget.vue` (new)
- `src/agentDashboard.js` (new)

**Files to Modify**:
- `lib/Service/ItopAPIService.php` - Add agent query methods
- `lib/Controller/ItopAPIController.php` - Add getAgentDashboardData()
- `appinfo/routes.php` - Add /agent-dashboard route
- `lib/AppInfo/Application.php` - Register both widgets
- `vite.config.ts` - Add agent dashboard build entry
- `l10n/en.json` - Add agent widget strings

**Expected Outcome**:
- Portal users see "iTop - My Tickets" widget only
- Agent users see both "iTop - My Tickets" and "iTop - Agent Dashboard" widgets
- Agent widget shows assigned tickets, team queue, escalations, and upcoming changes
- Conditional visibility works correctly based on user profile

### Benefits of Dual Widget Approach

1. **Clear Separation of Concerns**
   - Portal widget: End-user ticket tracking
   - Agent widget: Operational agent workflows

2. **User Choice**
   - Users can enable/disable widgets independently
   - Agents can choose to show one or both widgets
   - Follows Nextcloud's user-centric design philosophy

3. **Performance**
   - Agent widget only loads when needed
   - Portal users don't pay performance cost for unused agent features
   - Separate API endpoints prevent over-fetching

4. **Maintainability**
   - Clear code separation
   - Easier to extend agent features without affecting portal widget
   - Independent testing and debugging

5. **Scalability**
   - Can add more specialized widgets in future (e.g., "iTop - Manager Dashboard")
   - Each widget can have its own update cadence
   - Widget-specific caching strategies

### Risks and Mitigations

| Risk | Impact | Mitigation |
|------|---------|------------|
| Performance degradation with team queries | Medium | Implement aggressive caching for team memberships (1hr TTL), use query optimization |
| iTop API doesn't support team membership queries | High | Validate API capabilities before implementation; fallback to agent_id only if needed |
| Complex permission logic | Medium | Reuse existing ProfileService; add comprehensive unit tests |
| User confusion with two widgets | Low | Clear naming and descriptions; documentation with screenshots |

### Testing Requirements

#### Unit Tests
- [ ] `ProfileService::isPortalOnly()` validation
- [ ] `ItopAPIService::getMyAssignedTickets()`
- [ ] `ItopAPIService::getTeamAssignedTickets()`
- [ ] `ItopAPIService::getUserTeams()`
- [ ] `ItopAgentWidget::isEnabled()` conditional logic

#### Integration Tests
- [ ] Portal user sees only portal widget
- [ ] Agent sees both widgets
- [ ] Agent widget disabled when ProfileService indicates portal-only
- [ ] Team ticket queries return correct results
- [ ] Upcoming changes query works

#### Manual Testing
- [ ] Test with real iTop instance (portal user)
- [ ] Test with real iTop instance (agent user)
- [ ] Test with user having multiple teams
- [ ] Test with user having no teams (fallback to agent_id only)
- [ ] Test widget visibility toggling in dashboard settings

---

**Status**: Planning phase complete, ready for implementation
**Next Step**: Begin Phase 1 backend fixes
**Owner**: To be assigned
**Target Completion**: TBD based on priority

**Dual Widget Status**: Feasibility confirmed, awaiting Phase 1-2 completion before implementation
