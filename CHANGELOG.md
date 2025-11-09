# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.3.1] - 2025-11-09

### 🔒 Security Hardening

This release includes important security enhancements to strengthen the application's defenses.

### Changed
- Replaced direct cURL usage with Nextcloud's IClientService for proper SSL certificate verification
- Enhanced input validation and sanitization for OQL query parameters
- Improved data validation for numeric identifiers

### Security
- Resolved issues related to network communication security
- Strengthened protection against malicious input in database queries

---

## [1.3.0] - 2025-11-08

### ✨ Major New Feature: Agent Notifications System

Version 1.3.0 introduces a comprehensive notification system with **12 notification types** across two independent tracks: Portal (4 types) and Agent (8 types). The system provides smart, event-driven notifications with weekend-aware SLA warnings and zero duplicate detection.

### Added

#### Agent Notification System (8 Types)
- **Ticket Assignment Tracking**:
  - `ticket_assigned`: New tickets assigned to you from unassigned state
  - `ticket_reassigned`: Tickets reassigned to you from another agent
  - `team_unassigned_new`: New unassigned tickets appearing in your team's queue

- **SLA Management**:
  - `ticket_tto_warning`: Time To Own SLA warnings with escalating urgency (⏰ 24h → ⚠️ 12h → 🟠 4h → 🔴 1h)
  - `ticket_ttr_warning`: Time To Resolve SLA warnings with same escalation pattern
  - `ticket_sla_breach`: SLA deadline exceeded alerts (🚨 icon)
  - **Weekend-Aware Logic**: Friday uses 72h threshold, Saturday uses 48h threshold to catch Monday/Tuesday breaches

- **Priority & Comments**:
  - `ticket_priority_critical`: Automatic alerts when tickets escalate to critical priority (🔴 icon)
  - `ticket_comment`: New comments on your tickets (both public and private log types)

#### Portal Notification Enhancements
- Portal notifications (from v1.2.2) now fully integrated with agent system:
  - `ticket_status_changed`: Status transitions
  - `agent_responded`: Public comments from agents
  - `ticket_resolved`: Resolution notifications
  - `agent_assigned`: Agent assignment changes

#### Background Job Infrastructure
- **CheckAgentTicketUpdates**: New background job running every 5 minutes
  - Processes all 8 agent notification types
  - Portal-only filtering (skips users with `is_portal_only='1'`)
  - Per-user interval checking (5-1440 minutes configurable)
  - Query optimization (skips API calls when notification types disabled)
  - Rate limiting (max 20 notifications per user per run)

- **CheckPortalTicketUpdates**: Existing job (enhanced)
  - Improved query optimization
  - Better timestamp handling
  - Contact role filtering support

#### Admin Configuration
- **3-State Notification Control**: Configure each notification type independently
  - **Disabled**: Not available to users (hidden)
  - **Forced**: Mandatory for all users (no opt-out)
  - **User Choice**: Enabled by default, users can opt-out

- **Default Configuration**:
  - Portal: All 4 types set to "User Choice"
  - Agent: 6 types "User Choice", 2 types "Forced" (SLA breach, priority critical)

- **Configurable Intervals**:
  - Admin default: 5-1440 minutes (recommended: 15 minutes)
  - Per-user override available in personal settings

#### OCC Commands
- **Enhanced `itop:notifications:test-user`**:
  - New `--agent` flag for testing agent notifications
  - New `--portal` flag for testing portal notifications (default)
  - `--reset` flag to clear timestamps and force full re-check
  - Displays user configuration, enabled notifications, and next execution time

#### API & Services
- **New ItopAPIService Methods**:
  - `getAgentTicketIds()`: Get tickets assigned to agent
  - `getTicketsApproachingDeadline()`: SLA warning detection with crossing-time algorithm
  - `getTeamAssignmentChanges()`: Detect new unassigned team tickets
  - `applyCrossingTimeAlgorithm()`: Weekend-aware threshold calculation (private)

- **Team Detection**:
  - Leverages existing `getUserTeams()` method
  - Tracks `team_id` changes via CMDBChangeOp
  - Verifies tickets remain unassigned (`agent_id = NULL/0`)

#### Documentation
- **New docs/NOTIFICATIONS.md**: Comprehensive 345-line user & admin guide
  - Complete notification type reference with examples
  - Setup instructions for users and administrators
  - Troubleshooting section with common issues
  - FAQ with 12 frequently asked questions
  - Technical architecture details

### Changed

#### Notification Algorithm Improvements
- **SLA Crossing-Time Algorithm**: Detects threshold crossings without storing per-ticket state
  - Eliminates duplicate warnings for same SLA level
  - Dynamically calculates when each threshold (24h/12h/4h/1h) will be crossed
  - Only notifies most urgent level if multiple thresholds crossed
  - Weekend expansion: Friday (72h), Saturday (48h) for 24h threshold

- **Notification Deduplication**:
  - Unique object keys: `ticket_id|subject|timestamp_hash`
  - Timestamp filtering prevents re-processing old changes
  - Leverages Nextcloud's native duplicate prevention

#### User Experience
- **Personal Settings UI**: Agent notifications section (only visible to non-portal users)
  - Master toggle for agent notifications
  - Granular per-type checkboxes (only "User Choice" types shown)
  - Info box listing "Forced" notifications that cannot be disabled
  - Integrated with existing portal notification settings

- **Notification Display**:
  - Escalating emoji icons based on urgency (SLA warnings)
  - Clear differentiation between public and private comments
  - Team name included in team ticket notifications
  - Clickable links route to appropriate iTop interface (portal vs admin UI)

### Technical Details

#### New Configuration Keys (oc_appconfig)
- `agent_notification_config`: JSON map of notification type → state (disabled/forced/user_choice)
- `default_notification_interval`: Default check interval for all users (minutes, default: 15)

#### New User Preferences (oc_preferences)
- `notification_last_agent_check`: Unix timestamp of last agent notification check
- `disabled_agent_notifications`: JSON array of disabled notification types OR "all"
- `notification_check_interval`: Per-user custom interval (minutes)

#### Architecture Highlights
- **Dual Background Jobs**: Portal and Agent jobs run independently
- **CMDBChangeOp Detection**: Leverages iTop's built-in change tracking (no external state)
- **Minimal State Storage**: Only timestamps per user (no per-ticket data)
- **Query Optimization**: Early exit if all notifications disabled, selective API calls
- **Portal-Only Filtering**: Agent job automatically skips portal-only users

#### Performance
- **Per-User Intervals**: Users only processed when their interval elapses (reduces API load)
- **Query Optimization**: Up to 100% reduction in API calls when notification types disabled
- **Rate Limiting**: Max 20 notifications per user per run prevents spam
- **Smart Caching**: Team memberships cached for 30 minutes, agent names for 24 hours

#### Translation Updates
- Updated en.json, de.json, de_DE.json, fr.json with agent notification types

### Breaking Changes
None - Existing portal notifications continue working without changes.

### Migration Notes
- **No user action required**: Portal notifications work as before
- **Agent detection automatic**: Users with `is_portal_only='0'` gain agent notification access

### See Also
- [Complete Notification Guide](docs/NOTIFICATIONS.md)

---

## [1.2.2] - 2025-11-02

### Changed
- **Dynamic URL Routing**: Enhanced `buildTicketUrl()` method in ItopAPIService to route users to appropriate iTop interface:
  - Portal-only users get portal URLs (`/pages/exec.php/object/edit/...`)
  - Power users (agents/admins) get admin UI URLs (`/pages/UI.php?operation=details...`)

---

## [1.2.1] - 2025-11-02

### Fixed
- **Connected Users Count**: Fixed database query and handle different column name formats across database drivers
- **Version Display**: Fixed constant by injecting `IAppManager` and using `Application::getVersion()` method
- **Search Time Formatting**: Corrected translation string placeholders to use proper `l10n->t()` format
- **Admin Settings**: Fixed version check endpoint

### Added
- **Version Check Endpoint**: New `/version-check` route that queries GitHub for latest releases
- **Local Access Rules Notice**: Added informational notice in admin settings for private IP addresses with command and documentation link for Nextcloud 30+
- **Time Format Translations**: Added German (informal/formal) and French translations for precise time formatting (`%dm ago`, `%dh ago`, `%1$dh %2$dm ago`, etc.)

### Changed
- **Admin Settings UI**: Enhanced Server URL field with helpful note about `allow_local_remote_servers` configuration for private networks
- **Translation Coverage**: Updated all localization files (de.json, de_DE.json, fr.json) with new time formatting and notice strings

---

## [1.2.0] - 2025-11-01

### ✨ Major New Feature: Dual Dashboard System

Version 1.2.0 introduces specialized dashboard widgets that adapt to user profiles, providing both end-user ticket tracking and comprehensive agent operational dashboards.

### Added

#### Dual Dashboard Widgets
- **Portal Widget ("iTop")**: Personal ticket tracking for all users
  - Compact status overview with badge breakdown (open, escalated, pending, resolved)
  - Recent ticket feed showing 4 most recent tickets sorted by last update
  - Visual status indicators: State-specific SVG icons for new, escalated, deadline, and closed tickets
  - Inline metadata: Status emoji (🆕👥⏳⚠️✅☑️❌), priority emoji (🔴🟠🟡🟢), relative timestamps
  - Rich hover details: Comprehensive ticket information including reference, dates, sanitized description
  - One-click access to tickets in iTop
  - Quick actions: Refresh and create new tickets
  - Mobile-optimized responsive design

- **Agent Widget ("iTop - Agent Dashboard")**: Operational metrics for agents
  - **My Work Section**: Count of assigned incidents and requests
  - **Team Queue Section**: Team-wide ticket counts for incidents and requests
  - **SLA Tracking**: Warnings (TTO/TTR approaching) and breaches (TTO/TTR exceeded)
  - **Change Management**: Upcoming changes with time windows (emergency/normal/routine)
  - Quick navigation links to filtered iTop views
  - Real-time ticket counts with visual indicators (info/warning/error)
  - Responsive 2x2 metrics grid layout

#### Widget Visibility Control
- **Profile-Based Display**: Portal users see only Portal Widget; agents see both widgets
- **Automatic Detection**: Widget availability controlled by `is_portal_only` flag from iTop profiles
- **Seamless User Experience**: No manual configuration required

#### Dashboard Backend
- **ItopWidget.php**: Main widget class with profile-aware widget loading
- **ItopAgentWidget.php**: Dedicated agent dashboard widget class
- **Dashboard API**: New endpoints for SLA tracking, change management, and team metrics
- **Efficient Queries**: Optimized OQL queries for dashboard data with minimal API calls

### Changed

#### Dashboard Improvements
- **Enhanced Ticket Display**: Improved visual hierarchy with better status differentiation
- **Better Error Handling**: Graceful fallback when API unavailable or user not configured
- **Performance**: Dashboard data cached separately from search results
- **Responsive Layout**: Mobile-first design with adaptive layouts for all screen sizes

#### API Enhancements
- **New ItopAPIService Methods**:
  - `getAgentDashboardMetrics()`: Fetch agent-specific operational data
  - `getSLAWarnings()`: Retrieve tickets approaching SLA thresholds
  - `getUpcomingChanges()`: Get planned changes with time windows
  - `getTeamQueue()`: Team-wide ticket statistics
- **Person ID Filtering**: All dashboard queries filtered by user's Person ID for data isolation

### Fixed
- **Ticket Timestamp Display**: Corrected timezone handling in dashboard relative timestamps
- **SVG Icon Rendering**: Fixed state icon display in dashboard ticket list
- **Dashboard Refresh**: Resolved cache invalidation issue causing stale data after updates
- **Mobile Layout**: Fixed overflow issues on small screens in dashboard widgets

### Technical Details

#### New Configuration Keys
- `dashboard_cache_ttl`: Dashboard data cache TTL (default: 120s / 2min)
- `agent_dashboard_enabled`: Global toggle for agent dashboard (default: true)

#### New Dashboard Files
- `lib/Dashboard/ItopWidget.php`: Portal widget (enhanced)
- `lib/Dashboard/ItopAgentWidget.php`: Agent dashboard widget (new)
- `lib/Service/DashboardService.php`: Dashboard data aggregation service (new)

#### Translation Updates
- Added 45 new translatable strings for dashboard widgets
- Updated en.json, de.json, de_DE.json, fr.json with dashboard translations

#### Migration Notes
- **No user action required**: Existing users see Portal Widget immediately
- **Automatic agent detection**: Agents automatically gain access to Agent Widget
- **Cache warmup**: First dashboard load may be slower as metrics cache populates

---

## [1.1.0] - 2025-10-28

### ✨ Major New Feature: Configuration Item (CI) Browsing

Version 1.1.0 adds complete CMDB integration, allowing users to search, browse, and preview Configuration Items alongside tickets.

### Added

#### Core CI Functionality
- **Configuration Item Browsing**: Search and preview 11 CI classes (PC, Phone, IPPhone, MobilePhone, Tablet, Printer, Peripheral, PCSoftware, OtherSoftware, WebApplication, Software)
- **Unified Search for CIs**: Extended search bar to find CIs in addition to tickets with smart ranking (exact match → class priority → recency)
- **Smart Picker for CIs**: Insert CI references in Talk, Text app, and comments with intelligent suggestions
- **Rich CI Previews**: Paste iTop CI URLs for detailed hardware/software previews showing specs, contacts, and installed software

#### New Backend Services
- **ProfileService**: Automatic detection of Portal vs Power users with configurable caching (default: 30min)
  - Portal users: See only CIs where they are listed as contacts
  - Power users: Full CMDB access within iTop ACL
- **PreviewMapper**: Dedicated service for transforming iTop CI data to preview DTOs with PhysicalDevice field alignment
- **CacheService**: Distributed caching layer with 5 configurable TTL types for optimal performance

#### Admin Features
- **CI Class Configuration**: Enable/disable CI classes with 3-state control:
  - **Disabled**: Hidden from all users
  - **Forced**: Enabled for all users (no opt-out)
  - **User Choice**: Enabled but users can opt-out in personal settings
- **Cache Management UI**: Configure cache TTLs for all cache types (CI Preview, Ticket Info, Search, Picker, Profile)
  - TTL ranges: 10s-1h for previews, 10s-5min for search/picker
  - "Clear All Cache" button for immediate refresh
  - Recommended settings for different deployment sizes

#### Internationalization
- **French Translation**: Complete fr.json with all 280 strings (formal vous-form)
- **German Translations**: Both informal (de.json - Du-form) and formal (de_DE.json - Sie-form) variants
- **280 Translatable Strings**: Comprehensive coverage of all UI elements, error messages, and settings

#### Visual Enhancements
- **11 CI Icons**: Class-specific SVG icons for all CI types (PC, phone, printer, tablet, software, etc.)
- **State-Specific Ticket Icons**: Dynamic icons for closed, escalated, and deadline tickets
- **Improved Mobile Responsiveness**: Better layout for CI previews on small screens

### Changed

#### Performance Improvements
- **60-80% Reduction in API Calls**: Multi-layer caching with intelligent TTL management
- **Optimized Search Queries**: Class-specific OQL optimization (e.g., Software exact→wildcard, WebApplication includes URL)
- **Distributed Caching**: Redis-compatible caching for high-traffic deployments

#### UI/UX Enhancements
- **Admin Settings Refactored**: Converted from JavaScript-rendered to PHP-rendered HTML for better translation support and maintainability
  - Reduced JavaScript from 1,234 to 561 lines (54% reduction)
  - Server-side translations using `<?php p($l->t('...')); ?>`
  - Better theme compatibility and accessibility
- **Search Result Ranking**: Enhanced algorithm with exact-match priority, class weighting, and recency scoring
- **Reference Provider**: Extended URL detection to support CI URLs in addition to ticket URLs

#### API & Architecture
- **ItopAPIService**: New methods for CI queries (`searchCIs()`, `getCIPreview()`) with Person ID filtering
- **ItopSearchProvider**: Extended to search both tickets and CIs with profile-aware filtering
- **ItopReferenceProvider**: Enhanced to detect and render rich previews for CI URLs
- **Application.php**: Centralized `SUPPORTED_CI_CLASSES` constant and CI class state management methods

### Fixed
- **CI Field Alignment**: Corrected field lists to match iTop data model schema (removed invalid `last_update` field)
- **Vite Output Directory**: Fixed build configuration to correctly output to `js/` instead of `js/vue/`
- **Translation Loading**: Resolved issue where JavaScript translations weren't loading in admin settings

### Technical Details

#### New Configuration Keys
- `cache_ttl_ci_preview`: CI preview cache TTL (default: 60s)
- `cache_ttl_profile`: Profile cache TTL (default: 1800s / 30min)
- `cache_ttl_search`: Search results cache TTL (default: 30s)
- `cache_ttl_picker`: Smart picker cache TTL (default: 60s)
- `ci_class_config`: JSON array of CI class states (disabled/forced/user_choice)

#### Database Changes
None - All new configuration stored in existing `oc_appconfig` table.

#### Migration Notes
- **No user action required**: Existing users continue to work with ticket-only functionality
- **Admin opt-in**: CI classes are disabled by default; admins must explicitly enable them
- **Cache warmup**: First CI search may be slower as caches populate

---

## [1.0.0] - 2025-10-18

### Initial Release

Complete iTop ITSM integration for Nextcloud with ticket management functionality.

### Added
- **Dynamic Reference Provider**: Rich link previews for iTop ticket URLs across all Nextcloud apps
- **Unified Search Integration**: Search iTop tickets (UserRequest, Incident) from Nextcloud search bar
- **Smart Picker**: Insert ticket references in Text app, Talk, and comments with suggestions
- **Dashboard Widget**: View ticket counts and recent tickets at a glance
- **Notification System**: Optional notifications for new ticket assignments with background job
- **Admin Settings Panel**: Configure iTop URL, display name, and application token with connectivity testing
- **Personal Settings Panel**: One-time personal token validation, user profile display, and feature toggles
- **Dual-Token Security Architecture**:
  - Application token (admin-configured, encrypted)
  - Personal token (user-provided, one-time verification, never stored)
  - Person ID mapping for data isolation
- **State-Specific Ticket Icons**: Visual indicators for ticket status (open, closed, escalated, deadline)
- **Profile Detection**: Automatic filtering based on user's iTop profile (Portal vs Power user)
- **English Localization**: Base translation support (119 strings)

### Security
- Encrypted token storage using Nextcloud ICrypto service
- Personal tokens never persisted (one-time verification only)
- All API queries filtered by Person ID to prevent data leakage
- Portal user support via dual-token architecture

### Technical Details
- Nextcloud 30.0+ compatibility
- PHP 8.1+ required
- iTop 3.0+ with REST API enabled
- Vue.js 3 for rich preview widgets
- Comprehensive error handling and logging

---

## Legend
- **Added**: New features and functionality
- **Changed**: Changes to existing functionality
- **Fixed**: Bug fixes
- **Security**: Security improvements
- **Deprecated**: Features marked for removal
- **Removed**: Removed features

---

For detailed technical documentation, see [docs/](docs/).

For migration guides and breaking changes, see [docs/changelog-notes.md](docs/changelog-notes.md).