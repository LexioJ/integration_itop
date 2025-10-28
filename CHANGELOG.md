# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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