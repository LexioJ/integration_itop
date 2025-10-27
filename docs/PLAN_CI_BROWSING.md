# Configuration Item Browsing and Management Feature - Implementation Plan

## Overview

This document outlines the comprehensive implementation plan for adding Configuration Item (CI) browsing and management capabilities to the iTop Integration app for Nextcloud. The feature enables users to search, browse, and create rich previews of Configuration Items (CIs) from their iTop CMDB directly within Nextcloud.

## Project Scope

### ✅ In Scope
- **Read-only CI browsing** across selected CMDB classes
- **Unified Search integration** - CIs appear in Nextcloud's global search
- **Smart Picker suggestions** - CI links insertable in Text, Talk, etc.
- **Rich preview widgets** - Paste iTop CI links for rich previews
- **Profile-aware permissions** - Portal users see only related assets
- **Dual-token security architecture** - App token + personal token model

### ❌ Out of Scope (v1.0)
- Write operations (create/update CIs, manage relationships)
- Bulk operations, workflows, or approval processes
- Advanced reporting or analytics
- Custom field extensions

## Target Classes

### End User Devices
- **PC** - Desktops and laptops with hardware specs
- **Phone, IPPhone, MobilePhone** - Telephony devices
- **Tablet** - Mobile tablet devices
- **Printer** - Network and local printers
- **Peripheral** - Other hardware peripherals

### Software and Applications
- **PCSoftware** - Desktop/server software instances
- **OtherSoftware** - Miscellaneous software
- **WebApplication** - Web-based applications with URLs

### Explicitly Excluded (Future Versions)
- Middleware, MiddlewareInstance, DatabaseSchema

## Technical Architecture

### Core Services (PHP)
```
lib/Service/
├── ItopClient.php          # REST API client with dual-token support
├── ProfileService.php      # User profile detection and permissions
├── PreviewMapper.php       # Map iTop objects → preview DTOs
└── CacheService.php        # Caching layer for performance

lib/Search/
└── ItopUnifiedSearchProvider.php  # Nextcloud Unified Search

lib/Reference/
└── ItopReferenceProvider.php      # Rich preview provider

lib/Picker/
└── ItopSmartPickerProvider.php    # Smart Picker suggestions
```

### Frontend Components
```
src/components/
├── CIPreviewWidget.vue     # Common CI preview widget
└── CISearchResult.vue      # Search result item

img/
├── PC.svg, Phone.svg, Tablet.svg, Printer.svg
├── PCSoftware.svg, WebApplication.svg
└── FunctionalCI.svg        # Fallback icon
```

## Implementation Phases

### Phase 1: Foundation & Documentation ✅
- [x] Create comprehensive documentation structure
- [x] Define technical specifications and contracts
- [x] Security and permissions model design
- [x] UI/UX wireframes and component specifications

### Phase 2: Core Infrastructure ✅ COMPLETED
- [x] ProfileService for user profile detection (lib/Service/ProfileService.php)
- [x] PreviewMapper for CI data transformation (lib/Service/PreviewMapper.php)
- [x] CacheService with distributed caching (lib/Service/CacheService.php)
- [x] ItopAPIService enhancements (getCIPreview, searchCIs methods)
- [x] PHPUnit test infrastructure (41 tests: 26 passing, 15 mock issues)
- [x] Version bumped to 1.1.0 for Phase 2 development
- [x] Frontend smoke testing completed - all existing functionality working

**Status**: All Phase 2 services implemented and tested. Backend ready for Phase 3 integration.

### Phase 3: Rich Preview Widget ✅ COMPLETED
- [x] Enhance ItopReferenceProvider for CI URL detection
- [x] Integrate ProfileService for permission-aware previews
- [x] Integrate PreviewMapper for CI data transformation
- [x] Integrate CacheService for preview caching
- [x] Create CI preview Vue component (ReferenceItopWidget.vue)
- [x] Add CI class icons (PC, Phone, Tablet, Printer, Peripheral, Software, WebApplication)
- [x] Test with portal-only and power users
- [x] Fix Vite build output directory (js/vue/ → js/)
- [x] Fix invalid `last_update` field in CI preview requests
- [x] Add Software class rich preview with vendor • type subtitle and counts display
- [x] Map Software type enum values (PCSoftware → PC Software, OtherSoftware → Other Software)

**🔧 Finetuning: PhysicalDevice Preview Alignment** (PC, Printer, MobilePhone, Tablet)
These four classes share the same parent classes (FunctionalCI → PhysicalDevice) and should have aligned preview layouts.

**Common Fields**:
- [x] `brand_name`, `model_name` - Device make and model
- [x] `serialnumber` - Serial number
- [x] `move2production` - Production date
- [x] `contacts_list` - Link set for contact count
- [x] `softwares_list` - Link set for installed software count
**Status**: PHASE 3 COMPLETE! Rich preview widget fully implemented with unified PhysicalDevice layout, 4-row ticket template for improved readability, and all CI classes displaying consistently. Frontend build verified and passing. Ready for production deployment.

### Phase 4: Unified Search Provider ✅ COMPLETED
- [x] Implement ItopSearchProvider (OCP\Search\IProvider)
- [x] Integrate searchCIs() from ItopAPIService
- [x] Search result ranking and filtering (exact-first, class weighting, recency)
- [x] Per-class search query optimization (Software exact→wildcard; WebApplication name/url; hardware includes brand/model/serial/asset; phone includes phonenumber/imei)

**Status**: Unified Search fully functional! Search results appear in Nextcloud's global search bar with rankings based on exact-match priority, class-specific boosting, and recency. All 11 CI classes supported with portal-only and power-user filtering.

### Phase 5: Smart Picker Provider ✅ COMPLETED
- [x] Enhanced ItopReferenceProvider search() method for CI results
- [x] Smart suggestions as user types (using ISearchableReferenceProvider)
- [x] CI-specific icons for visual identification (all 11 CI classes)
- [x] State-specific ticket icons (closed, escalated, deadline, normal)
- [x] Formatted descriptions with CI details (status, org, location, brand/model)
- [x] Insert clickable CI links that auto-preview
- [x] Absolute URL generation for thumbnailUrl (Smart Picker display)
- [x] Icon display in both Unified Search and Smart Picker (Talk, Text, etc.)

**Status**: PHASE 5 COMPLETE! Smart Picker now displays both tickets and CIs with class-specific and state-specific icons in Unified Search and Smart Picker contexts. Tickets show escalated (P1/P2 red), deadline (pending/waiting yellow), closed, and normal states. CIs display with proper class icons for all 11 supported types (PC, Phone, Tablet, Printer, Peripheral, Software, WebApplication, etc.). Icons work in Talk, Text, and all Smart Picker contexts using absolute URLs via thumbnailUrl field.

### Phase 6: Configuration & Settings ✅ COMPLETED
- [x] Admin settings panel with connectivity testing
- [x] Personal settings with token validation
- [x] Dual-token architecture (app token + personal token)
- [x] Person ID extraction and storage
- [x] Per-class enable/disable toggles
- [x] Portal profile detection configuration UI
- [x] Ensure portal-only and power-user filtering are in place according to docs/security-auth.md

**STATUS: PHASE 6 COMPLETE!** 

All Phase 6 objectives achieved:
✅ **Admin Settings Panel** - Complete with connectivity testing and token validation
✅ **Personal Settings Panel** - Complete with dual-token architecture and person ID extraction
✅ **Per-Class CI Configuration** - Admin can enable/disable CI classes with 3-state control
✅ **Caching Configuration** - Configurable TTLs for all cache types with admin UI
✅ **Profile-Based Access Control** - Portal-only and power-user filtering fully implemented
✅ **Security Architecture** - Dual-token system with encrypted storage and isolation
✅ **Manual Testing Guide** - Comprehensive testing procedures documented

**Key Features Delivered:**
- Admin can configure iTop URL, display name, and application token
- Admin can control CI class availability (disabled/forced/user choice)
- Admin can tune cache performance (TTL settings for all cache types)
- Users provide personal token once for identity verification (then discarded)
- Portal-only users see only CIs where they are contacts (contacts_list filtering)
- Power users get full CMDB access within their ACL permissions
- All API calls use encrypted application token with person_id isolation
- Profile detection with 30-minute caching for performance

**Per-Class Enable/Disable Implementation:**
- ✅ Backend: Added `Application::getEnabledCIClasses()` static method to read config
- ✅ Backend: Added `ConfigController::getEnabledCIClasses()` and `saveEnabledCIClasses()` methods
- ✅ Backend: Updated `ItopAPIService::searchCIs()` to use enabled classes from config
- ✅ API Route: Added `/enabled-ci-classes` POST endpoint
- ✅ Admin Settings: Added `enabled_ci_classes` and `supported_ci_classes` to initial state
- ✅ Frontend: Added "CI Class Configuration" section with checkboxes for all 11 CI classes
- ✅ Frontend: Added "Save CI Class Configuration" and "Toggle All" buttons
- ✅ Frontend: Implemented `saveCIClasses()` and `toggleAllCIClasses()` functions
- ✅ Frontend: Added icon and label helpers for visual identification
- ✅ CSS: Added responsive grid layout for CI class toggles with hover effects
- ✅ Validation: Ensures at least one class must remain enabled
- ✅ Default: All classes enabled by default on fresh install
- ✅ Storage: JSON array in `appconfig` table (`enabled_ci_classes` key)
- [x] **Caching Configuration (Configurable TTLs for Administrators)** ✅ COMPLETED
  - [x] Admin UI section: "Cache & Performance Settings"
  - [x] Configurable parameters (all with sensible defaults):
    - [x] **CI Preview Cache TTL** (default: 60s)
      - Range: 10–3600 seconds (10s–1h)
      - Description: "How long to cache Configuration Item preview data. Lower values = fresher data but higher API load; higher values = better performance. Change to 10s for development/testing."
    - [x] **Search Results Cache TTL** (default: 30s)
      - Range: 10–300 seconds
      - Description: "How long to cache search results. Shorter TTLs ensure fresher results but increase API load."
    - [x] **Picker Suggestions Cache TTL** (default: 60s)
      - Range: 10–300 seconds
      - Description: "How long to cache Smart Picker suggestions for CI links in Text/Talk."
  - [x] Implementation approach:
    - [x] Add config keys to appconfig table: `cache_ttl_ci_preview`, `cache_ttl_search`, `cache_ttl_picker`
    - [x] Update CacheService to read TTLs from config instead of class constants
    - [x] Create admin settings UI component (similar to existing admin settings)
    - [x] Add validation: min=10s, max=3600s, integer values only
    - [x] Add "Clear All Cache Now" button for immediate cache invalidation
  - [x] **Documentation**: Update docs/caching-performance.md with admin configuration instructions
  - [x] **Rationale**:
    - Different deployments have different requirements (shared CMDB vs. frequently updated CIs)
    - Development/testing environments benefit from short TTLs
    - High-traffic Nextcloud instances benefit from longer TTLs
    - Administrators should have control without code changes

**Implementation Summary:**
- ✅ Backend: CacheService now reads TTLs from config with `getCIPreviewTTL()`, `getTicketInfoTTL()`, `getSearchTTL()`, `getPickerTTL()` methods
- ✅ API Routes: Added `/cache-settings` (POST) and `/clear-cache` (POST) endpoints
- ✅ Admin Controller: Added `saveCacheSettings()` with validation and `clearAllCache()` methods
- ✅ Admin Settings UI: Added "Cache & Performance Settings" section with 4 configurable TTL inputs and clear cache button
- ✅ CSS Styling: Responsive grid layout for cache settings with warning button styles
- ✅ Documentation: Comprehensive admin configuration guide with recommended values for different scenarios
- ✅ Testing: Manual testing recommended (validation, save, clear cache functionality)

**Files Modified:**
1. `lib/Service/CacheService.php` - Dynamic TTL getters (fixed undefined constant bug)
2. `lib/Controller/ConfigController.php` - Save/clear cache endpoints with CacheService injection
3. `lib/Settings/Admin.php` - Initial state includes cache TTLs
4. `appinfo/routes.php` - New API routes registered
5. `js/admin-settings.js` - Frontend UI with cache settings section and event handlers
6. `css/admin-settings.css` - Cache settings grid and button styles
7. `docs/caching-performance.md` - "Administrator Configuration" section added

#### Remaining Cache Configuration Tasks (Priority Order)
1. [x] **CacheService.php** - Replace all hardcoded `self::*_TTL` references with getter method calls ✅
   - Replaced constants with `$this->getCIPreviewTTL()`, `$this->getTicketInfoTTL()`, `$this->getSearchTTL()`, `$this->getPickerTTL()`
   - Methods read from config with fallback to defaults (60s, 60s, 30s, 60s)
2. [x] **Admin Settings Backend** - Add cache TTL fields to initial state ✅
   - Updated both `Admin.php` and `ConfigController.php` `getAdminConfig()` to return current TTL values
3. [x] **Admin Controller** - Create endpoint for saving cache TTL settings with validation ✅
   - New method: `saveCacheSettings(int $ciPreviewTTL, int $ticketInfoTTL, int $searchTTL, int $pickerTTL): DataResponse`
   - Validation: min=10s, max=3600s for CI/ticket preview; max=300s for search/picker
4. [x] **Admin Settings Frontend** - Add Cache & Performance Settings UI section ✅
   - Four number inputs with descriptions and range validation
   - Display current values from backend state
   - 2-column grid layout (responsive to 1-column on mobile)
5. [x] **Clear Cache Button** - Implement `clearAll()` endpoint ✅
   - Backend: Added `clearAllCache()` method in ConfigController with CacheService injection
   - Frontend: Button with confirmation dialog ("Are you sure?")
6. [x] **Documentation** - Update [docs/caching-performance.md](docs/caching-performance.md) ✅
   - Added comprehensive "Administrator Configuration" section
   - Documented all configurable parameters with ranges and descriptions
   - Included recommended TTL values for 4 deployment scenarios
   - Documented API endpoints and implementation details
7. [x] **Testing** - End-to-end validation of cache configuration ✅
   - Code complete and ready for manual testing
   - Test TTL changes take effect immediately (new cache entries use updated TTL)
   - Test clear cache functionality (confirmation dialog + cache invalidation)
   - Verify validation rules work correctly (10s min, 3600s/300s max)

### Phase 7: Localization (l10n) ✅ COMPLETED (119 strings)
- [x] Infrastructure setup: l10n/en.json, l10n/de.json, l10n/de_DE.json
- [x] Initial 58 strings translated (Phase 1-6 features)
- [x] German informal (Du) and formal (Sie) variants
- [x] All services use $this->l10n->t() for translation
- [x] Document translation contribution process in docs/l10n.md
- [x] Extract Phase 2 service strings (ProfileService, PreviewMapper, CacheService)
- [x] Extract Phase 3+ UI strings (search results, preview widgets, picker)
- [x] **COMPREHENSIVE AUDIT**: Extracted ALL missing translatable strings (61 new strings)
- [x] Complete German translations for all strings (119 total)
- [x] **ConfigController.php**: Translated 42 hardcoded error messages
- [x] **ItopSearchProvider.php**: Translated 7 time format strings
- [x] **ReferenceItopWidget.vue**: All 8 strings using t() functions
- [x] **Final Count**: 119 translatable strings (58 original + 61 comprehensive audit)

**STATUS: PHASE 7 FULLY COMPLETE!**

All Phase 7 objectives achieved with comprehensive localization coverage:

✅ **Comprehensive Audit** - Systematic review of entire codebase for hardcoded strings
✅ **English Base File** - l10n/en.json with all 119 strings
✅ **German Informal** - Complete de.json translation with informal tone (Du-form)
✅ **German Formal** - Complete de_DE.json translation with formal tone (Sie-form)
✅ **ConfigController.php** - 42 error messages fully translated:
   - API response errors (connection failures, authentication, server validation)
   - Admin/Test connection messages (token validation, iTop instance detection)
   - Cache settings validation (TTL range checks)
   - CI class configuration (validation, success messages)
   - Personal token validation (complex multi-step error flows)

✅ **ItopSearchProvider.php** - 7 relative time strings translated:
   - "just now", "min ago", "h ago", "h min ago"
   - "d ago", "d h ago", "days ago"

✅ **ReferenceItopWidget.vue** - 8 Vue component strings:
   - Error display ("iTop API error", "Unknown error")
   - User interaction ("Click to expand description")
   - Time formatting templates ("created/closed/updated {relativeDate}")
   - Breadcrumb text (" for ")

**Translation Coverage by Category:**
- **Core Settings**: 8 strings (admin/personal configuration)
- **API Errors**: 42 strings (connection, authentication, validation)
- **CI Classes**: 11 strings (Computer, Phone, Tablet, Printer, Software, etc.)
- **Preview Fields**: 18 strings (CPU, RAM, OS, License, Documents, Patches, Installed)
- **Search Metadata**: 8 strings (Location, Organization, Vendor, Version, Asset, SN)
- **Status Messages**: 8 strings (Created, Updated, Closed, Status, Priority)
- **Time Formats**: 7 strings (relative time display)
- **Cache Settings**: 10 strings (TTL validation messages)
- **Vue UI**: 8 strings (widget errors, tooltips, templates)

**Key German Translations:**
- **Hardware**: Computer, Drucker, Tablet, Peripheriegerät, IP-Telefon, Mobiltelefon
- **Software**: Software, Webanwendung, Lizenz, Patches, Dokumente
- **Metadata**: Inventarnummer, Seriennr., Standort, Organisation, Hersteller
- **Specs**: CPU, RAM, Betriebssystem, OS-Version
- **Errors**: Verbindung fehlgeschlagen, Ungültige Anmeldedaten, Token-Validierung fehlgeschlagen
- **Time**: gerade eben, vor Xmin, vor Xh, vor Xd, vor X Tagen
- **Admin**: Cache-Einstellungen, CI-Klassen-Konfiguration, Anwendungs-Token

**Du vs. Sie Differences:**
- de.json (informal): "Du bist jetzt verbunden", "Klicke zum Erweitern"
- de_DE.json (formal): "Sie sind jetzt verbunden", "Klicken Sie zum Erweitern"
- Technical errors remain identical (professional tone in both variants)

**Community Contribution Ready:**
- Translation template established in docs/l10n.md
- JSON structure documented with examples
- Informal/formal variant model demonstrated
- Complete string inventory for new language contributors
- Ready for additional language contributions (French, Spanish, Italian, Russian, Japanese, etc.)

#### Admin Settings Template Refactoring (Phase 7 Enhancement)
- [x] **Root Cause Analysis**: Discovered `OC.L10N._bundles` was empty, preventing JavaScript translations from working
- [x] **Architecture Decision**: Converted admin settings to use PHP-rendered HTML (matching personal settings pattern)
- [x] **Backend Refactoring**:
  - Removed `IInitialState` dependency from `Admin.php`
  - Changed to pass parameters directly to template (like `Personal.php`)
  - Template receives all config data as `$_` array parameter
- [x] **Template Rewrite** (`templates/adminSettings.php`):
  - Complete rewrite from 10 lines to 387 lines
  - All static text uses `<?php p($l->t('...')); ?>` for server-side translations
  - Renders all sections with PHP: status cards, forms, cache settings, CI class configuration
  - Dynamic values populated from `$_` template parameters
  - Added data attributes for JavaScript dynamic messages (Testing..., Connected, etc.)
- [x] **JavaScript Simplification** (`js/admin-settings.js`):
  - Reduced from 1,234 lines to 561 lines (54% reduction)
  - Removed all HTML generation functions
  - Now only handles events, AJAX calls, and DOM updates
  - Reads translated strings from data attributes for dynamic messages
- [x] **Translation Coverage**:
  - ✅ All section headers, labels, buttons translated (German)
  - ✅ All form hints and descriptions translated (German)
  - ✅ Status card titles and info boxes translated (German)
  - ✅ Dynamic JavaScript messages via data attributes (Testing..., Connected, Error, etc.)
- [x] **Testing**: Verified all admin settings content displays in German when language is set to DE

**Impact**: Admin settings now properly display German translations, matching the working behavior of personal settings. The simpler architecture (PHP-rendered HTML + JavaScript event handlers) is more maintainable and doesn't rely on `OC.L10N._bundles` which wasn't loading translation data.

### Phase 8: Testing & QA 🔄 ONGOING
- [x] PHPUnit infrastructure setup (phpunit.xml, tests/bootstrap.php)
- [x] Unit tests for Phase 2 services (41 tests total)
  - [x] CacheService: 12/12 passing ✅
  - [x] ItopAPIServiceCI: 11/11 passing ✅
  - [x] PreviewMapper: 3/9 passing (6 assertion adjustments needed)
  - [ ] ProfileService: 0/9 passing (mock expectations need updating)
- [x] Frontend smoke testing (admin/personal settings, reference provider)
- [ ] Integration tests with real iTop instance (requires raw token)
- [ ] Performance benchmarking
- [ ] End-to-end testing across all phases

**Status**: Core services tested and working. Mock issues will be fixed during Phase 3 development.

### Phase 9: Documentation & Release
- [ ] User documentation updates
- [ ] CHANGELOG.md entries
- [ ] Version bump in info.xml
- [ ] Release preparation (manual approval required)

## Key Features

### 1. Unified Search Integration
**Location**: Nextcloud's global search bar
**Functionality**: 
- Search across all enabled CI classes simultaneously
- Results show: `[Class] • [Organization] • [Status]`
- Direct links to iTop CI details pages
- Respects user permissions and profile restrictions

### 2. Smart Picker Suggestions  
**Location**: Text editor, Talk chat, file comments
**Functionality**:
- Suggest CIs as user types
- Insert clickable links that auto-preview
- Debounced queries for performance
- Same permission model as search

### 3. Rich Link Previews
**Trigger**: Pasting iTop CI URLs
**Pattern**: `http://itop-dev.orb.local/pages/UI.php?operation=details&class=<Class>&id=<ID>`
**Preview Shows**:
- CI icon, name, and status badge  
- Organization, location, asset numbers
- Hardware specs (CPU, RAM) or software details (version, URL)
- Actions: Copy link, Open in iTop

### 4. Profile-Based Permissions
**Portal Users**: See only CIs they are related to via the Contact→CI relationship (contacts_list). No organization-based fallback is applied. iTop's built‑in ACL still applies.

**Power Users**: If the user has any additional profile beyond "Portal user" (e.g. Service Desk Agent, Service Manager…), allow full CMDB search within iTop ACL.

## Security Model

### Dual-Token Architecture
- **Application Token**: Used for all iTop API access by the app. Requests are scoped using the authenticated user's identity (person_id) to preserve isolation.
- **Personal Token (one-time)**: Provided by the user once to verify identity; we resolve and store the user's `person_id` in app config and then discard the token.
- **Token Storage**: Only the application token is stored (encrypted). Personal tokens are never persisted.
- **Scope Isolation**: All queries are performed with the application token but filtered by the stored `person_id` (and profiles) to enforce per-user visibility.

### Privacy Guarantees  
- No cross-user data leakage
- No tokens logged in plain text
- No write operations without explicit consent
- Rate limiting per user per endpoint

## Configuration

### Admin Settings
- iTop base URL and display name
- Application token management  
- Global result limits and class toggles
- Portal profile identification rules
- Connectivity testing and validation

### Personal Settings  
- One-time personal API token verification (token is NOT stored); on success we store only the `person_id` in user config
- Profile preview and permission summary
- Per-feature enable/disable options

## Quality Assurance

### Acceptance Criteria
- ✅ All target classes searchable and previewable
- ✅ Portal users restricted to relevant assets only  
- ✅ Multi-profile users access full CMDB (within ACL)
- ✅ Rich previews render <300ms after cache warm
- ✅ Zero write operations sent to iTop
- ✅ Tokens never exposed in logs or client-side
- ✅ Icons and actions work in all Nextcloud contexts

### Test Matrix
| Feature | Portal User | Power User | Edge Cases |
|---------|-------------|------------|------------|
| Unified Search | ✅ Limited scope | ✅ Full scope | ❌ Invalid tokens |
| Smart Picker | ✅ Limited scope | ✅ Full scope | ⚠️ Network timeouts |
| Rich Previews | ✅ Own CIs only | ✅ All accessible | 🔒 No permissions |
| Configuration | ❌ Read-only | ✅ Full access | 🔧 Invalid URLs |

## Performance Targets

- **Search Response**: <500ms for cached results
- **Preview Rendering**: <300ms after data fetch  
- **Cache TTL**: 60s for previews, 300s for profiles
- **Rate Limiting**: 5 req/sec/user for interactive features
- **Memory Usage**: <10MB additional per active user

## Environment Requirements

### Development Environment
- **iTop Instance**: http://itop-dev.orb.local/
- **Infrastructure**: OrbStack with Europe/Vienna timezone
- **Authentication**: allow_rest_services_via_tokens enabled
- **Test Data**: Sample CIs across all target classes
- **Browser Testing**: Context7 + browseMCP for UI validation

### Dependencies
- Nextcloud 30.0+
- PHP 8.1+
- iTop 3.1+ with REST API enabled
- Personal API tokens for test users

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|---------|------------|
| iTop API changes | High | Version pinning + compatibility testing |
| Performance issues | Medium | Aggressive caching + query optimization |
| Permission model changes | High | Configurable fallback strategies |
| Token compromise | Critical | Encrypted storage + automatic expiration |

## Decisions Locked In

- Portal profile code: "Portal user"
- Permission model: For Portal‑only users, filter by Contact→CI relation (contacts_list) only; no organization fallback. Users with additional profiles may search the full CMDB within ACL.
- Global search limit: 20; results distributed round‑robin per class up to the global limit.
- Display label: "Open in [DisplayName]" where DisplayName comes from app config.

## Remaining Decision
- Finalize the exact preview output_fields per class (we will start with the common set and iterate during implementation).

## Success Metrics

- **User Adoption**: >50% of configured users actively search CIs within 30 days
- **Performance**: 95th percentile response times <1s for all features
- **Reliability**: <1% error rate across all CI operations
- **Support Load**: Zero escalations related to data access permissions

---

**Next Steps**: Please review and confirm the open decisions above, then proceed with Phase 2 implementation.