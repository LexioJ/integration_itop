# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.5.0] - 2026-10-26

### 🔗 Identity Binding & Deprecated API Cleanup

### ⚠️ Breaking
- **Minimum Nextcloud version is now 32**: `OCP\Config\IUserConfig` is only available from Nextcloud 32 onwards, so the user preference migration below raises `min-version` from 30 to 32. Nextcloud 30 and 31 are no longer supported

### Added
- **Admin-side identity binding (`occ itop:bind`)**: administrators can link Nextcloud accounts to their iTop person without those users touching iTop. This is the only route available to portal-only users, because iTop issues personal tokens to back-end users only. Matching is controlled by an admin-configured source attribute (Nextcloud email, user ID or display name) and target (`Person.email`, `Person.employee_number` or `User.login`), and results are graded: `exact` (attribute and name agree) is bindable, `review` (names differ) needs explicit confirmation, `ambiguous` (several candidates) is never guessed, `none` is reported. The command is dry-run by default and requires `--apply` to write; accounts can be excluded from matching entirely. A failing account is reported and skipped rather than aborting the batch, and the command warns when `--limit` leaves accounts unexamined. A person already linked to another Nextcloud account is graded `conflict` and never bound automatically, on any path — `person_id` is the authorisation boundary for ticket and CI access, so two accounts holding one person would mean each seeing the other's tickets
- **Identity binding settings and API**: the matching rule (which Nextcloud attribute is matched against which iTop attribute) is now configurable from Admin settings → iTop instead of only through `occ config:app:set`, and shows a warning when a risky attribute is selected, before the rule is saved. Adds admin endpoints for listing accounts with their binding state, managing exclusions, and unbinding, which the association table builds on
- **User associations screen**: Admin settings → iTop now lists every Nextcloud account with its iTop binding state and avatar, filterable by all / unbound / bound / excluded and searchable by name, user ID or email. Accounts can be excluded from matching (individually or in bulk) and links can be removed. Bindings made before this app recorded provenance show their origin as "unknown" rather than blank. Computing matches is not part of this screen
- **Scope picker on the user associations screen**: one field searches Nextcloud accounts, groups and Teams, and narrows the table to the accounts the chosen scope covers. Teams require the `circles` app; with it disabled the picker simply omits them. Typing without choosing a suggestion still filters by name, user ID or email
- **Match suggestions on the user associations screen**: *Suggest matches* grades the selected accounts — or the current page — as exact, review, ambiguous or conflict, and *Apply exact only* binds the confident ones. Review rows show the Nextcloud and iTop names side by side for a one-click confirmation, ambiguous rows open a picker, and conflict rows name the account already holding that person and offer no action. An already-bound account is never re-bound by apply and gets no Apply or Confirm action — moving someone to a different account stays an explicit unlink-then-apply. Matching runs only when asked, because each account costs one iTop request. Any unbound, non-excluded account can also be linked to a person manually — via the same picker, pre-filled with candidates on an ambiguous row and otherwise opened empty to search — without a match being found first, for the accounts the matcher has nothing to go on
- **Self-service discovery (`POST /binding/discover`)**: a third binding route lets a user link their own account from personal settings, with no administrator and no iTop personal token involved. It is **off by default** — unlike administrator binding, which is backed by the administrator's own application token, and personal-token binding, where iTop itself resolves the token to its own contact, discovery lets the user's own attribute decide which identity they receive, so it starts opt-in and an administrator enables it from Admin settings → iTop
- **What discovery will and will not do**: it binds only on an **exact match** — where the matched attribute and the name agree. Review (names differ), ambiguous (several candidates) and conflict (person already linked to another account) each get their own explanation and point the user to an administrator; none of these ever names the matched person, so a result that cannot be linked automatically gives no clue who it almost matched. It never re-binds an already-linked account — an administrator must unlink first. The discovery and confirmation endpoints take no parameters and are rate-limited to 5 calls per minute per user, and a successful bind is recorded in the audit log with the account, the person, the method (`discovery`) and the matched attribute. Personal settings shows only the binding methods an administrator has enabled, and offers discovery as the alternative for portal-only users who cannot obtain a personal token, pointing them to an administrator only when discovery is also disabled
- **An independent toggle for the existing personal-token route**: on by default, to preserve current behaviour; the personal-token endpoint returns 403 when it is switched off
- **Admin-configured request-access links**: two optional ServiceSubcategory ids — one for "I have a problem" (Incident), one for "I need assistance" (User Request) — let an administrator point users at the right iTop request form. Either, both or neither may be set; leaving one empty hides that link. The link appears only where a user cannot link their own account, pointing them to a way of asking for access instead of an administrator. Setting both ids to the same value is a supported shortcut for instances where one form covers both cases — the user then sees a single "Request access" link rather than two. Both ids are ignored, and no link is shown, until the instance URL is configured, since there is no base URL to build the link on
- **Person detail line on the user associations screen**: a bound row's `person {id}` now shows the iTop person's name and email, and an icon naming whether they are a portal user or a service desk agent — the same kind of answer an administrator already gets from the Account column, instead of having to go and look the id up in iTop themselves. Resolved for the whole page in two batched queries rather than one per row, so the table costs no more iTop requests than it already did. A person iTop can no longer find is called out as a broken link rather than left to look the same as any other row; an unreachable iTop instead leaves the line blank, since that is a transient condition and not something for an administrator to go and repair

### Changed
- **Admin settings reorganised**: the sections an administrator uses day to day come first and connection setup moved to the bottom, since it is configured once. Before the connection is configured the page shows only that form. Identity binding and user associations are now a single "User associations" section listing the accounts with the matching rule beneath it, and chips under the page header jump to any section, with a back-to-top chip on each
- **Migrated off deprecated `IConfig` value APIs**: `IConfig::get/set/deleteAppValue` has been deprecated since Nextcloud 29 in favour of `IAppConfig`, and the matching user preference methods since Nextcloud 33 in favour of `IUserConfig`. All ~200 call sites now use `IAppConfig::getValueString`/`setValueString`/`deleteKey` and `IUserConfig::getValueString`/`setValueString`/`deleteUserConfig`. `IConfig` is still used for `getSystemValue`, which is not deprecated
- **String accessors used deliberately**: the typed `getValueInt`/`getValueBool` variants are intentionally not used. Existing values are stored as `VALUE_MIXED`, which reads safely under any getter, but the first typed *write* pins a config key's type permanently — a single missed call site would then raise `AppConfigTypeConflictException` at runtime. The string accessors preserve the previous behaviour exactly and leave typed accessors as a safe per-key follow-up
- **Controller routing flags moved to PHP attributes**: `@NoAdminRequired`, `@NoCSRFRequired` and `@PublicPage` have been deprecated as PHPDoc annotations since Nextcloud 30 in favour of the `OCP\AppFramework\Http\Attribute\*` attributes. All 16 across the three remaining controllers were converted. Nextcloud logged one deprecation line per matched method per request, so an endpoint such as the CI class icon — fetched once per class on every settings page load — filled the log on instances running at a low log level. No endpoint's access level changed
- **Notifier registration moved to the bootstrap context**: `IManager::registerNotifierService()` has been deprecated since Nextcloud 22; the notifier is now registered via `IRegistrationContext`, which also removes a service container lookup from the `Application` constructor
- **Replaced legacy `OC` globals in the settings bundles**: `OC.generateUrl` → `generateUrl` (`@nextcloud/router`), `OC.requestToken` → `getRequestToken()` (`@nextcloud/auth`), `OC.Notification.showTemporary` → `showSuccess`/`showError` (`@nextcloud/dialogs`), and the `window.t` fallback → `translate` (`@nextcloud/l10n`). The request token is now read when each request's headers are built rather than cached at module load, so a rotated token is picked up

---

### Fixed
- **Portal users with an additional profile were treated as back-end users**: iTop confines a user to the front-end portal as soon as the `Portal user` profile is assigned — the back office stays locked even when a back-end profile such as `Service Desk Agent` is also present. `ProfileService::isPortalOnly()` only reported portal-only when `Portal user` was the *sole* profile, so these users were misclassified. The consequences were that ticket links pointed at `/pages/UI.php` (the back office they cannot open) in widgets, rich previews and notifications; the agent dashboard widget and agent notifications were offered to them; and, because all queries run under the application token, `searchCIs()` and `getCIPreview()` skipped the contact-based scoping that portal users are meant to get and could return Configuration Items beyond the user's entitlement. Detection now keys on the profile being present at all. `Portal power user` is unaffected and still means a back-end user with portal access (typically `Service Desk Agent` + `Portal power user`), since the comparison is exact rather than a substring match
- **Cached portal status is recomputed on upgrade**: a post-migration repair step clears `is_portal_only` and `profiles_last_check` for all users, so the corrected rule takes effect immediately instead of after the profile cache TTL lapses
- **Personal settings respects simple ticketing mode**: in simple mode the ticket count endpoint no longer queries the `Incident` class (which does not exist there) or shows an `Incidents: 0` row that could never be anything else; it shows a single ticket count instead
- **Personal settings hid controls that could not take effect while unlinked**: Search Settings, Notification Settings and Configuration Item Classes are no longer shown for an account that is not linked to an iTop person, since none of them act on anything until it is. The Save button and the "Available features" list are likewise hidden when there is nothing to save and no way for the user to link themselves
- **Linking by personal token now reloads the page on success**, the way self-service discovery already did, so the newly available settings appear immediately instead of after a manual reload
- **An empty grey box no longer sits at the foot of personal settings**: the panel holding the personal-token instructions and the "Available features" list is itself styled, so for an unlinked account with both self-service methods disabled — where both of those blocks are hidden — it was still painted, as an empty box with nothing in it. It is now left out when it would have no content
- **Saving self-service settings no longer erases the two request-form ids**: a client that submits the self-service toggles without the request-id fields — such as a cached pre-1.5.0 admin bundle — left both ids blanked on every save. Omitting a field now leaves its stored value untouched; only an explicitly empty value clears it
- **Account names and iTop person names containing an apostrophe rendered as literal entity text on the user associations screen**: an account such as `O'Brien` showed up as `Select O&#39;Brien` rather than `Select O'Brien`, because `t()` already escapes the values it substitutes and the page's own `esc()` was escaping them a second time on top. Every such interpolation across the table — checkbox labels, badges, tier comparisons, the matched-value hint — now tells `t()` not to escape, leaving `esc()` to do it exactly once, as it was always meant to

### Known issue
- `occ user:setting <uid> integration_itop <key> <value>` now fails with *"conflict between new type (mixed) and old type (string)"* for keys the app has already written. `IUserConfig::setValueString()` pins a key's type, while `occ user:setting` writes an untyped value; `setValueMixed()` is not public API, so this affects any app using `IUserConfig` on Nextcloud 32+. App behaviour is unaffected — only manual administration. Workaround: `occ user:setting <uid> integration_itop <key> --delete` first, then set the value

---

## [1.4.3] - 2026-09-26

### 🔧 Nextcloud 35 Compatibility

### Changed
- **Nextcloud 35 Support**: Raised the supported max-version to 35 — the app has been verified to run on Nextcloud 35 without code changes

---

## [1.4.2] - 2026-08-11

### 🔧 Compatibility Fixes

### Fixed
- **Deprecated Exception in Notifier** (#6): `Notifier::prepare()` now throws `OCP\Notification\UnknownNotificationException` instead of the deprecated plain `InvalidArgumentException` when a notification is not known to this notifier — Nextcloud ≥ 30 logged a deprecation warning on every notifications API poll (e.g. from mobile clients or Unified Push) whenever other apps' notifications were processed
- **CI Links Missing Object ID**: Smart picker and search results for Configuration Items produced iTop URLs with an empty `id=` parameter (only UserRequest links worked) — CI queries use `output_fields=*`, which does not include the object id in iTop REST responses; the id is now taken from the response's `key` property in both the CI search and the preview mapper, so inserted links and previews point to the actual object
- **Custom CI Class Icons on Hardened Servers**: Icon auto-discovery relied on the iTop web server exposing HTML directory listings of `/datamodels/2.x/` — standard production hardening (`Options -Indexes`) blocks these, so all custom CI classes silently fell back to the generic Peripheral icon; the installed module list is now fetched via the REST API (`ModuleInstallation`) and datamodel XML URLs are derived from the iTop naming convention, with directory listings only used as an enhancement when available; a failed discovery (empty definitions map) is no longer persisted for 24h, so icons recover on the next attempt instead of staying pinned to the fallback; on servers that block the datamodel XML files entirely, the admin settings now show a hint on the Custom CI Classes section naming the affected classes and the two remedies (allow the Nextcloud server to read the datamodel XMLs, or upload icons manually); class icons in the admin settings are now clickable to upload or overwrite a custom icon (SVG or PNG, max 256 KB) via the file picker
- **Localized iTop Date Formats**: iTop installations with a customized `date_and_time_format` (e.g. `d.m.Y`) delivered localized date strings via the REST API, causing "Invalid Date" in the dashboard widgets, wrong ticket sorting, and broken SLA deadline calculations — all REST responses are now normalized to `Y-m-d H:i:s` regardless of the server's locale configuration (supports `.`, `/`, and `-` separators in day-first, month-first, and year-first order), and the widgets fall back to the raw string instead of rendering "Invalid Date" for unparseable values

---

## [1.4.1] - 2026-07-26

### 🔧 Nextcloud 34 Compatibility, Token Setup Guidance & Release Polish

This release restores compatibility with Nextcloud 34, adds in-app guidance for the iTop personal token prerequisites, and fixes several issues found during the pre-release review of v1.4.0.

### Added
- **iTop Configuration Hint (Admin Settings)**: New warning card explaining that agents need `personal_tokens_allowed_profiles` configured in iTop's Configuration File Editor before they can create personal tokens, including a ready-to-adapt `$MyModuleSettings` example
- **Personal Token Help (Personal Settings)**: Info hint shown when the "Personal Tokens" option is missing from the user's iTop account, pointing them to their iTop administrator

### Fixed
- **Nextcloud 34 Compatibility**: Replaced removed legacy `\OC::$server->getURLGenerator()` / `getDatabaseConnection()` calls with the modern `\OCP\Server::get()` API — the deprecated shortcut methods were removed in Nextcloud 34, causing the admin and personal settings pages to abort mid-render with an Internal Server Error; also raised the supported max-version to 34
- **Broken Admin Settings CSS**: Removed a stray unclosed `.ticket-type-options` rule that swallowed the new config-hint styles and duplicated an existing selector
- **Template Translation Mismatch**: Removed embedded HTML from translatable strings in admin and personal settings templates so they match the locale catalogs (German translations now resolve correctly), and fixed a missing `%s` substitution that displayed literally instead of the instance name
- **Locale File Consistency**: Synced 5 new strings from .js locale files into the .json catalogs for en, de, and de_DE (server-side rendering reads .json — the strings would otherwise fall back to English)
- **Slow Custom CI Class Discovery**: The `ci-class-available` endpoint re-crawled the entire iTop datamodel (one HTTP request per module directory and XML file) on every call — the parsed class-definition map and a negative cache for undiscoverable icons are now persisted in appdata with a 24h TTL, reducing repeat loads from ~37s to under a second
- **Class Browser Not Updating**: Newly added custom CI classes now disappear from the selection panel immediately after saving instead of remaining listed
- **Debug Leftover**: Removed a stray `console.log` from the dashboard widget and rebuilt the frontend bundle

---

## [1.4.0] - 2026-04-10

### Custom CI Classes, Ticket System Detection & Newsroom Mirroring

This release combines three feature branches to add Configuration Item class management improvements, ticket system type detection, and Newsroom Mirroring functionality.

#### Added
- **Custom CI Classes Support**: Complete implementation for managing custom Configuration Item classes beyond the standard 11 types
  - Custom CI class icons stored in appdata with Peripheral.svg fallback
  - Support for custom CI classes in CI Search and Reference Provider
  - Improved admin settings UI for Custom CI Classes section
- **Ticket System Type Detection**: 
  - Automatic detection of iTop ticket system configuration (ITIL/simple/auto)
  - Support for simple ticketing environments with single ticket class
  - Runtime error prevention in personal settings CI icon handling
- **Newsroom Mirroring**:
  - Mirror iTop ticket updates to Nextcloud Talk Notifications
  - Background job for efficient Talk integration processing

#### Changed
- **Admin Settings UI**:
  - Improved custom CI class management and icon handling
  - Enhanced notification configuration UI with emojis and better layout
- **Personal Settings**:
  - Improved personal settings UI consistency and theming
- **Background Jobs**:
  - Added NewsroomPollJob for processing Newsroom Mirroring requests
  - Integrated with existing CheckPortalTicketUpdates and CheckAgentTicketUpdates infrastructure
- **API & Services**:
  - Enhanced ItopAPIService with CI query methods for profile-aware filtering
  - Improved PreviewMapper service for CI data transformation
  - Improved Controller classes for settings management and API endpoints
- **Frontend**:
  - Updated admin-settings.js and personal-settings.js for improved UX
  - Enhanced AgentDashboardWidget.vue for better responsiveness

#### Fixed
- **CI Icon Resolution for Custom Classes**: Custom CI class icons now served via `getCIClassIcon` controller route (appdata) instead of failing on missing SVGs in `img/` directory, which caused the entire search to return empty results
- **Software Search Field Name**: Corrected OQL field `vendor_name` to `vendor` matching the iTop data model
- **Personal Settings CI Icons**: Custom CI classes now display correct icons instead of falling back to the notification bell icon
- **Missing Translations**: Added 54 v1.4.0 translatable strings (ticket system detection, custom CI classes, newsroom mirroring, icon management) to all locale files (en, de, de_DE, fr)
- **Translation Coverage**: Added 15 remaining strings with proper German Informal (Du-form) and German Formal (Sie-form) translations to de and de_DE, and 14 strings to fr — all 4 locales now fully cover the English source strings
- **Locale File Consistency**: Normalized emoji encoding in en.json and fr.json, regenerated .js locale files from .json sources to ensure frontend translations stay in sync

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