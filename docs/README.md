# Integration iTop - Documentation

This directory contains comprehensive documentation for the iTop Integration app for Nextcloud, with a focus on the Configuration Item (CI) browsing and management feature.

## Documentation Structure

### 📋 Planning & Architecture
- **[PLAN_CI_BROWSING.md](PLAN_CI_BROWSING.md)** - Master implementation plan and project roadmap
- **[architecture.md](architecture.md)** - High-level app architecture and data flow diagrams  
- **[security-auth.md](security-auth.md)** - Dual-token flow, storage, permissions model, profile checks

### 🔌 API & Data Integration  
- **[itop-api.md](itop-api.md)** - REST endpoints, OQL queries, pagination/limits, error semantics
- **[class-mapping.md](class-mapping.md)** - CI classes, fields, preview mapping, icons, search weights

### 🔍 Feature Specifications
- **[unified-search.md](unified-search.md)** - Search provider spec, response format, result limits, i18n
- **[smart-picker.md](smart-picker.md)** - Suggestion provider spec, UX, throttling, access rules  
- **[rich-preview.md](rich-preview.md)** - Reference provider spec, preview templates, assets, fallbacks

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

**Phase 1**: Foundation & Documentation ✅ **COMPLETE**
- [x] Comprehensive planning and scope definition
- [x] Technical specifications and contracts
- [x] Security and permissions model design  
- [x] UI/UX wireframes and component specifications

**Next Phase**: Core Infrastructure Development
- [ ] ItopClient service with dual-token authentication
- [ ] ProfileService for user profile detection
- [ ] PreviewMapper for CI data transformation
- [ ] Caching and rate limiting infrastructure

## Key Features Overview

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