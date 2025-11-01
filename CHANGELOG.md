# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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