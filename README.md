# Nextcloud iTop integration

🎫 iTop integration into Nextcloud

This app provides integration between Nextcloud and iTop IT Service Management platform. It allows users to:

- View assigned tickets on the Nextcloud dashboard
- Search for tickets and configuration items from Nextcloud's unified search
- Get notifications for new assigned tickets
- Rich previews of iTop links in Nextcloud apps

## Features

- **Dashboard widget**: Shows your assigned tickets and recent activity
- **Unified search**: Search tickets, incidents, and CIs directly from Nextcloud
- **Notifications**: Get notified when new tickets are assigned to you
- **Rich references**: Share iTop links with rich previews in Talk and other apps

## Configuration

### Admin settings
1. Go to Settings > Administration > Connected accounts
2. Configure your iTop server URL and admin settings

### User settings
1. Go to Settings > Connected accounts
2. Enter your iTop server URL (if not set by admin)
3. Configure your personal API token from iTop

### iTop API Token Setup
1. Log into your iTop instance
2. Go to "My Account" menu
3. Generate a personal API token with appropriate scopes
4. Copy and paste the token into Nextcloud settings

## Requirements

- Nextcloud 30+
- iTop with REST/JSON API enabled
- iTop Authentication by Token extension installed
