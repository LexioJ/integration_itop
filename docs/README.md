# Integration iTop - Documentation

This directory contains comprehensive documentation for the iTop Integration app for Nextcloud, with a focus on the Configuration Item (CI) browsing and management feature.

## Documentation Structure

### 📋 Planning & Architecture
- **[PLAN_CI_BROWSING.md](PLAN_CI_BROWSING.md)** - Master implementation plan and project roadmap for CI browsing
- **[PLAN_DASHBOARD.md](PLAN_DASHBOARD.md)** - Dashboard widgets implementation plan (dual-widget architecture)
- **[PLAN_NOTIFICATIONS.md](PLAN_NOTIFICATIONS.md)** - Notification system implementation plan (12 types, Portal + Agent)
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
- **[NOTIFICATIONS.md](NOTIFICATIONS.md)** - Complete notification system guide (setup, configuration, FAQ, troubleshooting)

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

### CI Browsing Feature ✅ **COMPLETE**

**Phase 1-7**: Foundation through Localization ✅
- [x] Comprehensive planning and scope definition
- [x] Technical specifications and contracts
- [x] Core infrastructure (ItopClient, ProfileService, PreviewMapper, CacheService)
- [x] Rich preview widget with unified PhysicalDevice layout
- [x] Unified search integration with profile-aware permissions
- [x] Smart picker provider for Text, Talk, and file comments
- [x] Configuration & settings (admin and personal)
- [x] Full localization (280+ strings in 3 languages: EN, DE, FR)

**Phase 8-9**: Testing & Release ✅
- [x] Testing and QA
- [x] User documentation and changelog
- [x] Release preparation

**Status**: Feature fully implemented and working as expected in production environments.

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

### Notification System (v1.3.0) ✅ **COMPLETE**

**Status**: Fully implemented intelligent notification system with 12 notification types across Portal and Agent tracks

#### Phase 1: Portal Notifications ✅
- [x] Admin settings: 3-state configuration (disabled/forced/user_choice) for all notification types
- [x] Personal settings: Master toggle + granular per-type checkboxes with custom intervals
- [x] Change detection: CMDBChangeOp-based tracking (status, agent_id, case logs)
- [x] Background job: `CheckPortalTicketUpdates` runs every 5 minutes with per-user interval checking
- [x] Notifier extensions: Four portal notification types with localized messages
- [x] Contact role filtering: Supports direct callers AND contacts with role_code IN ('manual', 'computed')
- [x] Rate limiting: Max 20 notifications per user per run
- [x] Self-notification filtering: No notifications for user's own comments
- [x] Agent name resolution: Cached lookups (24h TTL) to minimize API calls
- [x] **Zero duplicate notifications** through unique object keys and timestamp filtering
- [x] **Minimal state storage** (only timestamps, no per-ticket data)

#### Phase 2: Agent Notifications ✅
- [x] Background job: `CheckAgentTicketUpdates` runs every 5 minutes for non-portal users
- [x] Portal-only filtering: Agent job skips users with `is_portal_only='1'`
- [x] SLA warning detection: Weekend-aware crossing-time algorithm (Friday: 72h, Saturday: 48h)
- [x] Team detection: Tracks team_id changes via CMDBChangeOp for unassigned tickets
- [x] Escalating emoji icons: ⏰ (24h) → ⚠️ (12h) → 🟠 (4h) → 🔴 (1h) → 🚨 (breach)
- [x] Assignment tracking: Detects ticket_assigned and ticket_reassigned via agent_id changes
- [x] Comment notifications: Both public and private log types for agents
- [x] Priority escalation: Automatic alerts when tickets reach critical priority
- [x] Query optimization: Up to 100% API call reduction when notification types disabled

#### Phase 4: Polish & Documentation ✅
- [x] Comprehensive user/admin guide: [NOTIFICATIONS.md](NOTIFICATIONS.md) (345 lines)
- [x] Updated README.md with notification system overview
- [x] Updated CHANGELOG.md with detailed v1.3.0 entry
- [x] Complete translations: Added 21 new strings (EN, DE, DE_DE, FR)
- [x] OCC test command: `itop:notifications:test-user --agent/--portal/--reset`

**Portal Notification Types** (4):
1. **ticket_status_changed**: Track ticket lifecycle from new to resolved
2. **agent_responded**: New public comments from IT agents
3. **ticket_resolved**: Resolution notifications
4. **agent_assigned**: Agent assignment changes

**Agent Notification Types** (8):
1. **ticket_assigned**: New tickets assigned to you from unassigned state
2. **ticket_reassigned**: Tickets reassigned to you from another agent
3. **team_unassigned_new**: New unassigned tickets in your team's queue
4. **ticket_tto_warning**: Time To Own SLA warnings (24h/12h/4h/1h thresholds)
5. **ticket_ttr_warning**: Time To Resolve SLA warnings (24h/12h/4h/1h thresholds)
6. **ticket_sla_breach**: SLA deadline exceeded alerts
7. **ticket_priority_critical**: Critical priority escalations
8. **ticket_comment**: All comments on your tickets (public + private)

**Technical Implementation**:
- **ItopAPIService methods**: `getChangeOps()`, `getCaseLogChanges()`, `getUserTicketIds()`, `getAgentTicketIds()`, `getTicketsApproachingDeadline()`, `applyCrossingTimeAlgorithm()`, `getTeamAssignmentChanges()`, `resolveUserNames()`
- **Configuration keys**: `agent_notification_config`, `portal_notification_config`, `default_notification_interval` (JSON)
- **User preferences**: `disabled_agent_notifications`, `disabled_portal_notifications`, `notification_check_interval`, `notification_last_agent_check`, `notification_last_portal_check`
- **CMDBChangeOp detection**: Leverages iTop's built-in change tracking for zero external state
- **Crossing-time algorithm**: Detects threshold crossings without storing per-ticket state
- **Weekend-aware logic**: Friday expands 24h to 72h, Saturday to 48h to catch Monday/Tuesday breaches
- **Deduplication**: Unique object keys (`ticket_id|subject|timestamp_hash`) + Nextcloud's native duplicate prevention

**Architecture Highlights**:
- **Dual background jobs**: Portal and Agent jobs run independently every 5 minutes
- **Per-user intervals**: Reduces API load, users only processed when their interval elapses
- **Smart caching**: Team memberships (30 min), agent names (24h), profile data (30 min)
- **Query optimization**: Early exit if all notifications disabled, selective API calls
- **Rate limiting**: Max 20 notifications per user per run prevents spam

**Testing Results** (OrbStack nextcloud-dev VM):
- ✅ Portal notifications: Status changes, agent responses, resolutions, assignments
- ✅ Agent notifications: Assignments, reassignments, team queue, SLA warnings, breaches
- ✅ Weekend-aware SLA detection works (Friday 72h, Saturday 48h thresholds)
- ✅ Crossing-time algorithm eliminates duplicate SLA warnings
- ✅ Team detection via team_id changes working correctly
- ✅ No duplicate notifications across multiple job runs
- ✅ Query optimization verified (zero API calls when all types disabled)
- ✅ Portal-only user filtering working (agents not processed by agent job)

**Future Enhancement**: Phase 3 (Newsroom Mirroring) - Optional broadcast notifications from iTop newsroom (see [GitHub Issue #3](https://github.com/LexioJ/integration_itop/issues/3))

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