# iTop Integration for Nextcloud

🎟️ **Complete iTop ITSM Integration** - Seamlessly connect your Nextcloud with iTop IT Service Management

A comprehensive Nextcloud integration that brings iTop ITSM functionality directly into your Nextcloud environment, enabling users to access tickets, incidents, and configuration items without leaving their collaboration platform.

## ✨ Key Features

### 🔗 Dynamic Reference Provider
Transform iTop links into rich, interactive previews across Nextcloud apps (Talk, Deck, Collectives, etc.).

![Dynamic Reference Provider Screenshot](docs/images/dynamic-reference-provider.png)

**Features:**
- Automatic link detection and rich preview generation
- Detailed ticket information display
- Status indicators and priority badges
- Direct navigation to iTop
- Works in Talk chats, Deck cards, and Collectives documents

### 🔍 Unified Search Integration  
Search your iTop tickets and CIs directly from Nextcloud's unified search bar.

![Unified Search Screenshot](docs/images/unified-search.png)

**Features:**
- Search tickets by title, description, or reference number
- Filter results by ticket type (Incidents, User Requests)
- Real-time status and priority indicators
- Quick access to ticket details
- Searches tickets you created or are assigned to

### 🎯 Smart Picker Integration
Quick access to iTop content when creating or editing documents and communications.

![Smart Picker Screenshot](docs/images/smart-picker.png)

**Features:**
- Browse recent tickets and CIs
- Search and filter functionality
- Insert references with rich previews
- Context-aware suggestions
- Seamless integration with Text app and Talk

### ⚙️ Personal Settings Dashboard
Professional user configuration with real-time status monitoring.

![Personal Settings Screenshot](docs/images/personal-settings.png)

**Features:**
- Secure token-based authentication
- Real-time connection status
- User profile information display
- Open tickets counter (Incidents + User Requests)
- Notification preferences management
- Clean, intuitive interface

### 🛠️ Admin Configuration Panel
Comprehensive administration interface for system-wide configuration.

![Admin Settings Screenshot](docs/images/admin-settings1.png)
![Admin Settings Screenshot](docs/images/admin-settings2.png)

**Features:**
- Application token management (encrypted storage)
- Connection testing and validation
- User-facing name customization
- Connected users monitoring
- Professional, theme-aware UI

## 🔐 Security Architecture

**Dual-Token Approach:**
- **Application Token**: Admin-level token (encrypted) for all data queries
- **Personal Token**: User-provided token for one-time identity verification only (never stored)
- **Person ID Mapping**: Secure user identification without exposing sensitive data
- **Data Isolation**: All queries filtered by Person ID to ensure users only see their own data
- **Portal User Support**: Works with SAML/external authentication through iTop Portal Personal Tokens extension

## 🚀 Installation

### Prerequisites
- Nextcloud 30.0 or higher
- iTop with REST API enabled (version 1.3+)  
- PHP 8.1 or higher
- [iTop Portal Personal Tokens Extension](https://github.com/LexioJ/itop-portal-personal-tokens) (recommended for Portal users)

### Quick Setup
1. **Install the app**:
   ```bash
   # Place in apps directory and enable
   sudo -u www-data php occ app:enable integration_itop
   ```

2. **Configure admin settings** (Settings → Administration → iTop Integration):
   - Enter your iTop server URL
   - Add application token (see configuration guide below)
   - Test connection

3. **Users configure personal settings** (Settings → Personal → iTop Integration):
   - Add personal token for identity verification
   - Enable desired features (search, notifications)

## ⚙️ Configuration Guide

### Admin Configuration

1. **Create Application Token in iTop**:
   - Login as Administrator in iTop
   - Navigate to: **Admin tools → User accounts → + New... → Application Token**
   - Configure:
     - **Person**: Select admin user
     - **Profiles**: **Administrator** + **REST Services User** 
     - **Remote application**: "Nextcloud Integration"
     - **Scope**: **REST/JSON**
   - Save and copy the generated token (shown only once)

2. **Configure in Nextcloud**:
   - Go to **Settings → Administration → iTop Integration**
   - Enter your iTop server URL (e.g., `https://itop.company.com`)
   - Paste the application token
   - Customize the user-facing name (default: "iTop")
   - Click "Test Connection" to verify

### User Setup

1. **Create Personal Token in iTop**:
   - **Portal Users**: Use "Personal Tokens" in My Profile (requires Portal Personal Tokens extension)
   - **Regular Users**: Navigate to **My Account → Personal Tokens**
   - Create token with **REST/JSON** scope
   - Copy token immediately (shown only once)

2. **Configure Personal Settings**:
   - Go to **Settings → Personal → iTop Integration**
   - Paste your personal token
   - Click **Save** - the token validates your identity and is then discarded
   - View your real-time status dashboard

## 🏗️ Technical Architecture

### Core Components
```
lib/
├── Controller/
│   ├── ConfigController.php       # Settings and user validation
│   └── ItopAPIController.php      # API endpoints for tickets/CIs
├── Service/
│   └── ItopAPIService.php         # Core iTop API integration
├── Reference/
│   └── ItopReferenceProvider.php  # Dynamic link previews
├── Search/
│   ├── ItopSearchProvider.php     # Unified search integration
│   └── ItopSearchResultEntry.php  # Search result formatting
├── Dashboard/
│   └── ItopWidget.php             # Dashboard widget
└── Settings/
    ├── Admin.php                   # Admin configuration panel
    └── Personal.php                # User settings interface
```

### API Integration
- **REST API Version**: 1.3+
- **Authentication**: Token-based (Auth-Token header)
- **Query Method**: POST with form-encoded JSON data
- **Response Format**: JSON with object arrays
- **Security**: All queries filtered by Person ID for data isolation

### Supported iTop Objects
- **Tickets**: UserRequest, Incident
- **Configuration Items**: FunctionalCI and subclasses
- **Persons**: User profile information
- **Organizations**: Company/department info

## 🔧 Development

### Architecture Principles
- **Clean separation**: MVC pattern with dedicated service layer
- **Security first**: Dual-token architecture prevents unauthorized access
- **User experience**: Professional UI matching Nextcloud design standards
- **Extensibility**: Modular design allows easy feature additions

### Key Files for Extension
- `ItopAPIService.php` - Add new API interactions here
- `ItopAPIController.php` - Add new REST endpoints
- `ItopReferenceProvider.php` - Customize link preview behavior
- `ItopSearchProvider.php` - Extend search functionality

### Testing Environment
- Containerized development setup
- Database backend compatibility testing
- Comprehensive error handling and logging

## 📋 Roadmap

### ✅ v1.0.0 (Current Release)
- [x] Dynamic Reference Provider with rich link previews
- [x] Unified Search integration 
- [x] Smart Picker for content insertion
- [x] Personal Settings dashboard
- [x] Admin Configuration panel
- [x] Secure dual-token authentication
- [x] Dashboard widget for ticket overview
- [x] Background notifications for new assignments

### 🔄 Future Enhancements
- [ ] Advanced filtering and sorting options
- [ ] Ticket creation from within Nextcloud
- [ ] Configuration Item browsing and management
- [ ] Enhanced notification system with email digest
- [ ] API rate limiting and caching improvements
- [ ] Multi-language support expansion

## 🆘 Support

### Common Issues
- **Connection failed**: Verify iTop server URL and application token
- **User not configured**: Ensure personal token was created and validated
- **Search not working**: Check that search is enabled in personal settings
- **Links not previewing**: Verify reference provider is properly configured

### Getting Help
- Check the logs: `data/nextcloud.log`
- Review iTop API logs for authentication issues
- Ensure all prerequisites are met
- Verify token permissions and scopes

## 📄 License

This project is licensed under the AGPL v3 License - see the [LICENSE](LICENSE) file for details.

---

**Developed for seamless ITSM integration** • Made with ❤️ for the Nextcloud community
