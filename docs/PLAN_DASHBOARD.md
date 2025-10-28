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

### Phase 1: Backend Fixes ✅ TODO
**Priority**: Critical - Widget currently non-functional

**Tasks**:
1. [x] Analyze existing `ItopAPIService` methods
   - `getUserCreatedTickets()` exists ✅
   - `getUserCreatedTicketsCount()` exists ✅
   - No need for `getAssignedTickets()` - method doesn't exist
2. [ ] Update `ItopWidget.php` authentication check
   - Replace `token` with `person_id` check
   - Update `load()` method to use person_id
   - Update `getItems()` to use person_id
3. [ ] Fix `getItems()` method
   - Replace `getAssignedTickets()` with `getUserCreatedTickets()`
   - Add error handling for missing person_id
   - Update ticket formatting for both UserRequest and Incident
4. [ ] Add dashboard data endpoint
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

### Phase 2: Enhanced Ticket Display 🔄 TODO
**Priority**: High - Improve user experience

**Tasks**:
1. [ ] Implement status-based grouping
   - Add `getUserTicketsByStatus()` method in ItopAPIService
   - Group tickets: open, escalated, pending, resolved, closed
   - Add ticket type distinction (UserRequest vs Incident)
2. [ ] Create dashboard JavaScript bundle
   - Setup Vite config for dashboard entry point
   - Fetch data from new API endpoint
   - Implement interactive ticket list
   - Add status filter toggles
3. [ ] Design dashboard UI
   - Status cards with counts and color coding
   - Priority distribution chart
   - Quick action buttons
   - Responsive layout
4. [ ] Add dashboard styles
   - Create `css/dashboard.css`
   - Status badge colors matching iTop
   - Priority indicators (red/yellow/green)
   - Mobile-responsive grid

**Files to Create/Modify**:
- `lib/Service/ItopAPIService.php` - Add getUserTicketsByStatus()
- `src/dashboard.js` - Main dashboard Vue app
- `src/views/DashboardWidget.vue` - Dashboard component
- `css/dashboard.css` - Dashboard styles
- `vite.config.ts` - Add dashboard build entry

**Expected Outcome**: Rich, interactive dashboard with status-based ticket organization

### Phase 3: CI Integration 🔄 TODO
**Priority**: Medium - Leverage existing CI preview infrastructure

**Tasks**:
1. [ ] Implement recent CI tracking
   - Add `getUserRecentCIs()` method
   - Track CI access in session/cache
   - Return CIs with preview data
2. [ ] Create CI dashboard cards
   - Compact version of rich preview
   - Show CI icon, name, status
   - Link to full preview on click
3. [ ] Add CI section to dashboard
   - "Recently Viewed CIs" section
   - Display up to 5 CIs
   - Empty state when no CIs accessed
4. [ ] Test CI display
   - Verify preview data loads correctly
   - Test with different CI classes
   - Ensure portal-only filtering applies

**Files to Modify**:
- `lib/Service/ItopAPIService.php` - Add getUserRecentCIs()
- `src/views/DashboardWidget.vue` - Add CI section
- `css/dashboard.css` - CI card styles

**Expected Outcome**: Dashboard shows relevant CIs alongside tickets

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

### Phase 5: Testing & Documentation 📝 TODO

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
3. [ ] Update documentation
   - Add dashboard section to README
   - Document dashboard API endpoints
   - Update PLAN_CI_BROWSING.md with dashboard status
   - Add dashboard screenshots to docs/
4. [ ] Update translations
   - Add dashboard strings to l10n/en.json
   - Translate to German (de.json, de_DE.json)
   - Translate to French (fr.json)

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

## UI Mockup

```
┌─────────────────────────────────────────────────────┐
│ iTop Dashboard                           [Refresh]  │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Your Tickets                                        │
│  ┌──────────┬──────────┬──────────┬──────────┐    │
│  │ Open (5) │Escalated │Pending(3)│Resolved  │    │
│  │   🔴     │   (2)    │   ⏸️     │  (10)    │    │
│  └──────────┴──────────┴──────────┴──────────┘    │
│                                                      │
│  Recent Tickets                                      │
│  ┌───────────────────────────────────────────┐     │
│  │ 🎫 R-000123: Cannot access email         │     │
│  │    Status: New • Priority: High           │     │
│  │    Created: 2 hours ago                   │     │
│  ├───────────────────────────────────────────┤     │
│  │ 🎫 I-000045: Network outage               │     │
│  │    Status: Escalated • Priority: Critical │     │
│  │    Created: 1 day ago                     │     │
│  └───────────────────────────────────────────┘     │
│                                                      │
│  Recently Viewed CIs                                 │
│  ┌─────────┬─────────┬─────────┬─────────┐        │
│  │ 💻      │ 🖨️      │ 📱      │ 🌐      │        │
│  │ PC-001  │ PRN-034 │ MOB-012 │ APP-005 │        │
│  └─────────┴─────────┴─────────┴─────────┘        │
│                                                      │
│  [Create New Ticket] [View All in iTop]            │
└─────────────────────────────────────────────────────┘
```

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

1. **Phase 1**: Deploy backend fixes to production (fixes broken widget)
2. **Phase 2**: Beta test enhanced ticket display with small group
3. **Phase 3**: Roll out CI integration to all users
4. **Phase 4**: Gather feedback and iterate on polish items
5. **Phase 5**: Document and announce complete dashboard feature

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

**Status**: Planning phase complete, ready for implementation
**Next Step**: Begin Phase 1 backend fixes
**Owner**: To be assigned
**Target Completion**: TBD based on priority
