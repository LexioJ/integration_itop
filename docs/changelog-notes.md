# Changelog and Release Notes Preparation

## Overview

This document provides templates and checklists for preparing release documentation, version bumps, and changelog entries for the iTop Integration app.

## CHANGELOG.md Template

### Version Entry Format

```markdown
## [Version] - YYYY-MM-DD

### Added
- New feature description with brief explanation
- Another new feature

### Changed
- Modification to existing functionality
- UI/UX improvements

### Fixed
- Bug fix description
- Another bug fix

### Deprecated
- Features marked for removal in future versions

### Removed
- Features removed in this version

### Security
- Security patches and improvements
```

### Example: Version 1.0.0

```markdown
## [1.0.0] - 2025-10-18

### Added
- **CI Browsing:** Search and browse Configuration Items (PC, Phone, Tablet, Printer, WebApplication) from Nextcloud
- **Unified Search:** CIs and tickets appear in Nextcloud's global search with rich metadata
- **Rich Link Previews:** Paste iTop URLs anywhere in Nextcloud for automatic rich preview widgets
- **Smart Picker:** Intelligent suggestions for tickets/CIs in Text app, Talk, and comments
- **Profile-Aware Permissions:** Portal users see only related assets; power users get full CMDB access
- **Dual-Token Security:** Application token + personal token architecture for secure access
- **Admin Settings:** Configure iTop URL, display name, and application token with connectivity testing
- **Personal Settings:** One-time personal token validation to link Nextcloud user to iTop Person
- **Dashboard Widget:** View ticket counts and recent tickets at a glance
- **Notification System:** Optional notifications for ticket updates

### Changed
- N/A (initial release)

### Fixed
- N/A (initial release)

### Security
- Implemented encrypted token storage using Nextcloud's ICrypto service
- Personal tokens never persisted - used only for identity verification
- All API queries filtered by user's person_id to prevent data leakage
- Rate limiting (5 req/sec/user) to prevent abuse
```

### Example: Version 1.1.0 (Hypothetical)

```markdown
## [1.1.0] - 2025-11-15

### Added
- **Profile Service:** Automatic detection of user profiles (Portal vs Power user) with caching
- **PreviewMapper:** Dedicated service for transforming iTop CI data to preview DTOs
- **Cache Metrics:** Admin dashboard showing cache hit rates and performance statistics
- **CI Class Filtering:** Admins can enable/disable specific CI classes in search and picker
- **Batch CI Preview:** Fetch multiple CI previews in a single API request

### Changed
- Improved cache TTL values based on real-world usage patterns
- Enhanced error messages with actionable guidance
- Optimized OQL queries for better performance (30% faster searches)
- Updated UI with better mobile responsiveness

### Fixed
- Fixed issue where Portal users could see CIs via organization fallback (#42)
- Resolved race condition in cache invalidation
- Corrected timezone handling for ticket timestamps
- Fixed rich preview rendering in Talk dark mode

### Security
- Added request signature validation for webhook endpoints
- Improved token validation error handling
```

## Version Bump Checklist

### Pre-Release Checklist

- [ ] All tests passing (unit + integration)
- [ ] No critical bugs in issue tracker
- [ ] Documentation updated (all .md files)
- [ ] CHANGELOG.md updated with version entry
- [ ] Screenshots updated if UI changed
- [ ] Translation files updated (l10n)

### Version Numbering (Semantic Versioning)

**Format:** `MAJOR.MINOR.PATCH`

**Examples:**
- `1.0.0` - Initial stable release
- `1.1.0` - New features, backward compatible
- `1.0.1` - Bug fixes only, backward compatible
- `2.0.0` - Breaking changes, not backward compatible

**Rules:**
- **MAJOR:** Breaking changes (API changes, removed features)
- **MINOR:** New features, backward compatible
- **PATCH:** Bug fixes, backward compatible

### Files to Update

**1. appinfo/info.xml**
```xml
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
    <id>integration_itop</id>
    <name>iTop Integration</name>
    <summary>Browse iTop Configuration Items and tickets directly from Nextcloud</summary>
    <description><![CDATA[
This app integrates Nextcloud with iTop CMDB, providing:
- Search and browse Configuration Items (CIs)
- Rich link previews for iTop URLs
- Smart picker suggestions in Text, Talk, and comments
- Profile-aware permissions for Portal and Power users
- Secure dual-token architecture
    ]]></description>
    <version>1.0.0</version> <!-- UPDATE THIS -->
    <licence>agpl</licence>
    <author>Your Name</author>
    <namespace>Itop</namespace>
    <category>integration</category>
    <category>tools</category>
    <website>https://github.com/yourorg/integration_itop</website>
    <bugs>https://github.com/yourorg/integration_itop/issues</bugs>
    <repository>https://github.com/yourorg/integration_itop</repository>
    <screenshot>https://raw.githubusercontent.com/yourorg/integration_itop/main/screenshots/preview.png</screenshot>
    <dependencies>
        <nextcloud min-version="30" max-version="31"/>
    </dependencies>
</info>
```

**2. CHANGELOG.md**
```markdown
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-10-18

### Added
- Initial release with CI browsing, search, and preview features
...
```

**3. package.json**

**IMPORTANT:** This file MUST be updated with every version bump

```json
{
  "name": "integration_itop",
  "version": "1.0.0",  <!-- UPDATE THIS -->
  "description": "iTop Integration for Nextcloud",
  ...
}
```

**Note:** Unlike `composer.json` which typically doesn't include a version field in Nextcloud apps, `package.json` requires explicit version updates for npm/vite builds.

**4. composer.json (optional - usually no version field needed)**
```json
{
  "name": "nextcloud/integration_itop",
  "description": "iTop Integration for Nextcloud",
  ...
}
```

## info.xml Update Template

### Complete info.xml Example

```xml
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
    <id>integration_itop</id>
    <name>iTop Integration</name>
    <summary>Browse iTop Configuration Items and tickets directly from Nextcloud</summary>
    <description><![CDATA[
## Features

### 🔍 Unified Search
Search across Configuration Items and tickets directly from Nextcloud's global search bar. Results include CI name, organization, status, and direct links to iTop.

### 🎯 Smart Picker
Get intelligent CI and ticket suggestions when composing text in Talk, Text app, or comments. Insert links that automatically render as rich previews.

### 🖼️ Rich Link Previews
Paste iTop URLs anywhere in Nextcloud to get rich previews showing key information like hardware specs, software versions, ticket details, or contact information.

### 🔐 Profile-Aware Permissions
Portal users see only CIs they're related to, while power users get full CMDB access within their iTop permissions - all secured via dual-token architecture.

### 🛡️ Security
- Encrypted token storage using Nextcloud's ICrypto service
- Personal tokens never persisted
- All queries filtered by user's person_id
- Rate limiting to prevent abuse

## Supported CI Classes

**End User Devices:** PC, Phone, IPPhone, MobilePhone, Tablet, Printer, Peripheral
**Software & Applications:** PCSoftware, OtherSoftware, WebApplication

## Requirements

- Nextcloud 30+
- iTop 3.0+ with REST API enabled
- PHP 8.1+

## Setup

1. **Admin Setup:** Configure iTop URL and application token in admin settings
2. **User Setup:** Each user provides their personal API token once to link their account
3. **Start Using:** Search, browse, and preview iTop objects from Nextcloud!

For detailed documentation, see: https://github.com/yourorg/integration_itop/tree/main/docs
    ]]></description>
    <version>1.0.0</version>
    <licence>agpl</licence>
    <author mail="your.email@example.com" homepage="https://github.com/yourname">Your Name</author>
    <namespace>Itop</namespace>
    <category>integration</category>
    <category>tools</category>
    <website>https://github.com/yourorg/integration_itop</website>
    <bugs>https://github.com/yourorg/integration_itop/issues</bugs>
    <repository type="git">https://github.com/yourorg/integration_itop.git</repository>
    <screenshot>https://raw.githubusercontent.com/yourorg/integration_itop/main/screenshots/search.png</screenshot>
    <screenshot>https://raw.githubusercontent.com/yourorg/integration_itop/main/screenshots/preview.png</screenshot>
    <screenshot>https://raw.githubusercontent.com/yourorg/integration_itop/main/screenshots/settings.png</screenshot>
    <dependencies>
        <nextcloud min-version="30" max-version="31"/>
    </dependencies>
    <settings>
        <admin>OCA\Itop\Settings\Admin</admin>
        <admin-section>OCA\Itop\Settings\AdminSection</admin-section>
        <personal>OCA\Itop\Settings\Personal</personal>
        <personal-section>OCA\Itop\Settings\PersonalSection</personal-section>
    </settings>
</info>
```

### Description Best Practices

**DO:**
- Use Markdown formatting
- Include feature highlights
- Mention requirements clearly
- Provide setup instructions
- Link to documentation

**DON'T:**
- Include HTML tags (use Markdown instead)
- Make claims you can't support
- Forget version compatibility info
- Use excessive emojis

## Breaking Changes Documentation

### Template for Breaking Changes

```markdown
## Breaking Changes in 2.0.0

### Removed Features

#### Personal Token Storage
**Previous Behavior:** Personal tokens were stored encrypted
**New Behavior:** Personal tokens are never stored; used only for one-time validation
**Migration:** Users must re-validate their personal token once
**Impact:** High - all users affected
**Announced:** v1.5.0 (3 months prior)

#### Organization Fallback for Portal Users
**Previous Behavior:** Portal users could see CIs from their organization
**New Behavior:** Portal users see only CIs where they are listed as contact
**Migration:** Admins should review and update contact assignments
**Impact:** Medium - affects Portal users only
**Announced:** v1.4.0 (2 months prior)

### Changed APIs

#### ProfileService::isPortalOnly() Return Type
**Old:** `bool|null` (null on error)
**New:** `bool` (throws exception on error)
**Migration:** Wrap calls in try-catch
**Impact:** Low - internal API only

```php
// Before
$isPortal = $this->profileService->isPortalOnly($userId);
if ($isPortal === null) {
    // Handle error
}

// After
try {
    $isPortal = $this->profileService->isPortalOnly($userId);
} catch (ProfileException $e) {
    // Handle error
}
```

### Deprecated Features

#### config.getUserValue('token')
**Status:** Deprecated in v1.4.0, removed in v2.0.0
**Replacement:** `config.getUserValue('person_id')`
**Reason:** Personal tokens no longer stored
```

## Release Process

### Manual Release Workflow

**1. Prepare Release Branch**
```bash
git checkout -b release/1.0.0
```

**2. Update Version Numbers**
```bash
# Update version in all files
vim appinfo/info.xml  # <version>1.0.0</version>
vim package.json      # "version": "1.0.0"
vim composer.json     # "version": "1.0.0"
```

**3. Update CHANGELOG.md**
```bash
vim CHANGELOG.md
# Add new version entry with all changes
```

**4. Run Tests**
```bash
composer install
vendor/bin/phpunit
npm install
npm run test
npm run build
```

**5. Commit Release**
```bash
git add .
git commit -m "Release v1.0.0

- CI browsing and search features
- Rich preview widgets
- Dual-token security architecture
"
git push origin release/1.0.0
```

**6. Create Pull Request**
- Title: "Release v1.0.0"
- Description: Copy CHANGELOG.md entry
- Request review from maintainers

**7. Tag Release (After Merge)**
```bash
git checkout main
git pull origin main
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

**8. Create GitHub Release**
- Go to Releases → Draft new release
- Tag: v1.0.0
- Title: "iTop Integration v1.0.0"
- Description: Copy CHANGELOG.md entry
- Attach built tarball (optional)

### Automatic Version Bumping (Future)

**Using release-please:**
```yaml
# .github/workflows/release.yml
name: Release
on:
  push:
    branches: [main]

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: google-github-actions/release-please-action@v3
        with:
          release-type: php
          package-name: integration_itop
```

## Migration Guides

### Template for Migration Guide

```markdown
# Migrating from v1.x to v2.0

## Overview
Version 2.0 introduces breaking changes to improve security and performance. This guide helps you migrate smoothly.

## Pre-Migration Checklist
- [ ] Backup Nextcloud database
- [ ] Document current configuration
- [ ] Test migration on staging environment
- [ ] Schedule maintenance window
- [ ] Notify users of changes

## Breaking Changes

### 1. Personal Token Flow Changed

**Action Required:** All users must re-configure their personal tokens

**Steps:**
1. Inform users of upcoming change
2. Deploy v2.0
3. Users navigate to Personal Settings → iTop
4. Users enter personal token (will not be stored)
5. Verify person_id extracted correctly

**Rollback Plan:** Revert to v1.x and restore database backup

### 2. Portal User Permissions Restricted

**Action Required:** Review and update contact assignments

**Steps:**
1. Export list of Portal users: `SELECT * FROM oc_preferences WHERE appid='integration_itop' AND configkey='person_id'`
2. For each Portal user, verify they are listed as contact on their CIs in iTop
3. Update contact assignments in iTop as needed
4. Test search for Portal users after deployment

## Post-Migration Testing

- [ ] Admin can connect to iTop
- [ ] Portal user can search (limited results)
- [ ] Power user can search (full results)
- [ ] Rich previews render correctly
- [ ] No errors in Nextcloud logs

## Support
For migration issues, contact: support@example.com
```

## Release Notes for Users

### Template for User-Facing Release Notes

```markdown
# What's New in iTop Integration v1.1.0

## New Features

### 📊 Enhanced Dashboard Widget
Your iTop dashboard widget now shows more detailed ticket statistics with color-coded status indicators. Click any stat card to filter tickets by status.

![Dashboard Screenshot](screenshots/dashboard-v1.1.png)

### ⚡ Faster Search
We've optimized search queries to be 30% faster. You'll notice quicker results when searching for CIs and tickets.

### 🎨 Improved Dark Mode
Rich preview widgets now look great in dark mode with better contrast and readability.

## Improvements

- Better mobile responsiveness for preview widgets
- More descriptive error messages
- Reduced cache times for fresher search results

## Bug Fixes

- Fixed issue where some Portal users couldn't see their assigned tickets
- Resolved timezone display problems in ticket timestamps
- Corrected rendering of long CI names in picker suggestions

## How to Update

Your Nextcloud admin will deploy this update. No action required from you!

## Need Help?

Visit our [documentation](https://github.com/yourorg/integration_itop/tree/main/docs) or contact IT support.
```

## References

- **Testing:** [testing.md](testing.md)
- **Architecture:** [architecture.md](architecture.md)
- **Security:** [security-auth.md](security-auth.md)
- **Semantic Versioning:** https://semver.org/
- **Keep a Changelog:** https://keepachangelog.com/
