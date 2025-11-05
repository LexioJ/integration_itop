# Integration iTop - Documentation

This directory contains comprehensive documentation for the iTop Integration app for Nextcloud, with a focus on the Configuration Item (CI) browsing and management feature.

## Documentation Structure

### 📋 Planning & Architecture
- **[PLAN_CI_BROWSING.md](PLAN_CI_BROWSING.md)** - Master implementation plan and project roadmap for CI browsing
- **[PLAN_DASHBOARD.md](PLAN_DASHBOARD.md)** - Dashboard widgets implementation plan (dual-widget architecture)
- **[architecture.md](architecture.md)** - High-level app architecture and data flow diagrams
- **[security-auth.md](security-auth.md)** - Dual-token flow, storage, permissions model, profile checks

### 🔌 API & Data Integration
- **[itop-api.md](itop-api.md)** - REST endpoints, OQL queries, pagination/limits, error semantics
- **[API_DASHBOARD.md](API_DASHBOARD.md)** - Dashboard API endpoints (portal & agent), request/response formats
- **[class-mapping.md](class-mapping.md)** - CI classes, fields, preview mapping, icons, search weights

### 🔍 Feature Specifications
- **[unified-search.md](unified-search.md)** - Search provider spec, response format, result limits, i18n
- **[smart-picker.md](smart-picker.md)** - Suggestion provider spec, UX, throttling, access rules
- **[rich-preview.md](rich-preview.md)** - Reference provider spec, preview templates, assets, fallbacks
- **[DASHBOARD_IMPLEMENTATION_SUMMARY.md](DASHBOARD_IMPLEMENTATION_SUMMARY.md)** - Dashboard widgets implementation summary, achievements, metrics

### ⚙️ Configuration & Setup
- **[configuration.md](configuration.md)** - Admin and personal settings, validation, feature flags
- **[testing.md](testing.md)** - OrbStack dev environment, manual and automated test scripts, QA matrix

### 🎨 User Experience
- **[UX-widgets.md](UX-widgets.md)** - Widget wireframes, states, and responsive design guidelines

### 🚀 Performance & Operations
- **[caching-performance.md](caching-performance.md)** - Client-side and server-side caching, ETags, TTLs
- **[observability.md](observability.md)** - Logging, metrics, debug switches

### 🌍 Localization
- **[l10n.md](l10n.md)** - Translation guide, German informal/formal variants, community contributions

### 📝 Release Management
- **[changelog-notes.md](changelog-notes.md)** - Placeholders for CHANGELOG.md entries and info.xml notes
- **[edge-cases.md](edge-cases.md)** - Timeouts, partial data, missing rights, mixed locales, error handling

## Current Status

### CI Browsing Feature

**Phase 1-7**: Foundation through Localization ✅ **COMPLETE**
- [x] Comprehensive planning and scope definition
- [x] Technical specifications and contracts
- [x] Core infrastructure (ItopClient, ProfileService, PreviewMapper, CacheService)
- [x] Rich preview widget with unified PhysicalDevice layout
- [x] Unified search integration with profile-aware permissions
- [x] Smart picker provider for Text, Talk, and file comments
- [x] Configuration & settings (admin and personal)
- [x] Full localization (280+ strings in 3 languages: EN, DE, FR)

**Phase 8-9**: Testing & Release 🔄 **IN PROGRESS**
- [~] Testing and QA
- [ ] User documentation and changelog
- [ ] Release preparation

### Dashboard Widgets Feature ✅ **COMPLETE**

**Status**: Fully implemented dual-widget architecture
- [x] Portal widget for all users (personal ticket queue)
- [x] Agent widget for IT agents (team metrics, SLA tracking, changes)
- [x] Backend API endpoints (`/dashboard`, `/agent-dashboard`)
- [x] Conditional widget visibility based on user profile
- [x] Comprehensive translations (35+ strings in 4 languages: EN, DE, FR formal/informal)
- [x] API documentation ([API_DASHBOARD.md](API_DASHBOARD.md))
- [x] Implementation summary ([DASHBOARD_IMPLEMENTATION_SUMMARY.md](DASHBOARD_IMPLEMENTATION_SUMMARY.md))

See [PLAN_DASHBOARD.md](PLAN_DASHBOARD.md) for complete implementation details.

### Portal Notifications Feature (Phase 1) ✅ **COMPLETE**

**Status**: Fully implemented and tested smart notification system for portal users
- [x] Admin settings: Configurable check interval (5-1440 minutes, default 15)
- [x] Personal settings: Master toggle + 3 granular notification type toggles
- [x] Change detection: CMDBChangeOp-based tracking (status, agent_id, case logs)
- [x] Background job: `CheckPortalTicketUpdates` runs every 5 minutes with per-user interval checking
- [x] Notifier extensions: Four portal notification types with localized messages
- [x] OCC test command: `occ itop:notifications:test-user` for testing and timestamp reset
- [x] Rate limiting: Max 20 notifications per user per run
- [x] Timezone handling: Uses Nextcloud's `default_timezone` config for correct relative timestamps
- [x] Self-notification filtering: No notifications for user's own comments
- [x] Agent name resolution: Cached lookups (24h TTL) to minimize API calls
- [x] **Zero duplicate notifications** through event-time based detection
- [x] **Minimal state storage** (only timestamps, no per-ticket data)
- [x] **OQL limitation workaround**: PHP-side timestamp filtering (iTop OQL doesn't support comparison operators on external keys)

**Notification Types**:
1. **Ticket status changed**: Notifies when ticket status changes (new→assigned, pending→assigned, etc.)
2. **Agent responded**: Notifies when agent adds public_log entry (filters out self-comments)
3. **Ticket resolved**: Notifies when ticket status becomes 'resolved'
4. **Agent assigned changed**: Notifies when assigned agent changes with resolved names (e.g., "John Doe → Jane Smith")

**Technical Implementation**:
- **ItopAPIService methods**: `getChangeOps()`, `getCaseLogChanges()`, `getUserTicketIds()`, `resolveUserNames()`
- **Name resolution**: Queries Person class (not User) with 24-hour cache via Nextcloud distributed cache
- **Timestamp consistency**: All timestamps stored/compared in server timezone (not PHP default UTC)
- **iTop OQL limitation**: Cannot use `change->date > 'timestamp'` - queries fetch all records, filter in PHP
- **Resolution detection**: Includes resolved tickets in query (`operational_status IN ('ongoing','resolved')`)

**Testing Results** (OrbStack nextcloud-dev VM):
- ✅ Successfully detects status changes via CMDBChangeOpSetAttributeScalar
- ✅ Successfully detects agent responses via CMDBChangeOpSetAttributeCaseLog
- ✅ Successfully detects agent assignment changes with name resolution
- ✅ Successfully detects ticket resolution events
- ✅ Notifications sent with correct timing (relative timestamps: "vor X Minuten")
- ✅ No duplicate notifications across multiple job runs
- ✅ No self-notifications when user comments on own ticket
- ✅ Clickable links route correctly to portal/agent UI based on user profile

**Next Steps**: Phase 2 (Agent Notifications) - SLA warnings, assignments, team queue, priority escalations

## Key Features Overview

### 📊 Dashboard Widgets ✅
Two specialized dashboard widgets with automatic visibility control:

**Portal Widget** - For all users:
- Personal ticket queue with status breakdown
- 4 most recent tickets (Incidents + UserRequests)
- Visual status indicators and priority badges
- Quick actions: Refresh, Create New Ticket
- Responsive design for mobile/desktop

**Agent Widget** - For IT agents only:
- **My Work**: Assigned incidents and requests
- **Team Queue**: Team-wide ticket distribution
- **SLA Tracking**: TTO/TTR warnings and breaches
- **Change Management**: Upcoming changes with time windows
- Real-time metrics with clickable navigation

See [PLAN_DASHBOARD.md](PLAN_DASHBOARD.md) and [API_DASHBOARD.md](API_DASHBOARD.md) for details.

### 🔍 Unified Search Integration
Search across Configuration Items directly from Nextcloud's global search bar. Results include CI name, organization, status, and direct links to iTop.

### 🎯 Smart Picker Suggestions
When composing text in Talk, Text app, or comments, get intelligent CI suggestions that insert as clickable links with rich previews.

### 🖼️ Rich Link Previews
Paste iTop CI URLs anywhere in Nextcloud to get rich previews showing key information like hardware specs, software versions, or contact details.

### 🔐 Profile-Aware Permissions
Portal users see only CIs they're related to, while power users get full CMDB access within their iTop permissions - all secured via dual-token architecture.

## Target Classes

**End User Devices**: PC, Phone, IPPhone, MobilePhone, Tablet, Printer, Peripheral
**Software & Applications**: PCSoftware, OtherSoftware, WebApplication

## For Developers

### Getting Started
1. Review the [master implementation plan](PLAN_CI_BROWSING.md)
2. Use the existing [OrbStack test environment](testing.md)
3. Understand the [security model](security-auth.md) and dual-token approach
4. Study the [API integration patterns](itop-api.md) and [class mappings](class-mapping.md)

### Contributing
- All changes must pass integration tests on the OrbStack dev environment
- Follow the dual-token security patterns established in the codebase
- Update relevant documentation when adding features or changing APIs
- Manual version bumps only - no automatic releases

### Architecture Philosophy
- **Security-first**: User data isolation, encrypted token storage, no write operations  
- **Performance-aware**: Aggressive caching, rate limiting, minimal API calls
- **User-centric**: Portal user compatibility, intuitive UX, consistent with Nextcloud patterns
- **Maintainable**: Clean abstractions, comprehensive testing, clear documentation

---

For questions or clarifications, please refer to the specific documentation files or consult the project maintainer.