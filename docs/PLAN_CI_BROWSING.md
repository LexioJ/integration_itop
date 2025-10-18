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

### Phase 2: Core Infrastructure
- [ ] ItopClient service with dual-token authentication
- [ ] ProfileService for user profile detection
- [ ] PreviewMapper for CI data transformation
- [ ] Caching and rate limiting infrastructure

### Phase 3: Search Integration
- [ ] Unified Search provider implementation
- [ ] Smart Picker provider
- [ ] Search result ranking and filtering
- [ ] Per-class search query optimization

### Phase 4: Rich Previews
- [ ] Reference provider for link detection
- [ ] Common CI preview widget component
- [ ] Icon assets and theming support
- [ ] Responsive design for all Nextcloud contexts

### Phase 5: Configuration & Settings
- [ ] Admin settings panel with connectivity testing
- [ ] Personal settings with token validation
- [ ] Per-class enable/disable toggles
- [ ] Portal profile detection configuration

### Phase 6: Localization (l10n)
- [ ] Extract all translatable strings to l10n/en.json
- [ ] Create l10n/de.json (German informal - Du)
- [ ] Create l10n/de_DE.json (German formal - Sie)
- [ ] Ensure all user-facing strings use $this->l10n->t()
- [ ] Test with both German variants
- [ ] Document translation contribution process

### Phase 7: Testing & QA
- [ ] Unit tests for all services
- [ ] Integration tests with OrbStack environment
- [ ] Manual testing across all features
- [ ] Performance benchmarking
- [ ] Translation completeness verification

### Phase 8: Documentation & Release
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