/**
 * Nextcloud - iTop Integration
 *
 * Admin settings JavaScript
 */

(function() {
    'use strict';

    console.log('iTop Admin Settings: Loading UNIFIED VERSION with inline Test Connection button...');
    
    // Wait for DOM and OC to be ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('iTop Admin Settings: DOM ready');
        
        // Try InitialState first, fall back to AJAX
        let initialState = {};
        try {
            if (OC.InitialState && OC.InitialState.loadState) {
                initialState = OC.InitialState.loadState('integration_itop', 'admin-config') || {};
                console.log('iTop Admin Settings: Initial state loaded:', initialState);
                createAdminInterface(initialState);
            } else {
                console.log('iTop Admin Settings: InitialState not available, using fallback');
                fetchConfigFromServer();
            }
        } catch (e) {
            console.log('iTop Admin Settings: InitialState failed, using fallback:', e.message);
            fetchConfigFromServer();
        }
    });
    
    function fetchConfigFromServer() {
        console.log('iTop Admin Settings: Fetching config from server...');
        
        fetch(OC.generateUrl('/apps/integration_itop/admin-config'), {
            method: 'GET',
            headers: {
                'requesttoken': OC.requestToken
            }
        })
        .then(response => {
            console.log('iTop Admin Settings: Server response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('iTop Admin Settings: Server config loaded:', data);
            createAdminInterface(data);
        })
        .catch(error => {
            console.error('iTop Admin Settings: Failed to load config:', error);
            createAdminInterface({}); // Load with defaults
        });
    }

    function createAdminInterface(initialState) {
        const container = document.getElementById('itop_prefs');
        if (!container) {
            console.error('iTop Admin Settings: Container #itop_prefs not found');
            return;
        }

        // Clear the container
        container.innerHTML = '';

        // Create the enhanced HTML structure with centered wrapper
        const html = `
            <div class="itop-settings-wrapper">
            <div class="itop-admin-header">
                <div class="icon-container">
                    <div class="app-icon"></div>
                </div>
                <div class="header-content">
                    <h2>${initialState.user_facing_name || 'iTop'} Integration</h2>
                    <p class="subtitle">Configure your iTop system integration settings</p>
                </div>
                <div class="version-badge">v${initialState.version || '1.0.0'}</div>
            </div>

            <div class="settings-section">
                <div class="section-header">
                    <h3>📊 Current Status</h3>
                </div>
                
                <div class="status-grid">
                    <div class="status-card">
                        <div class="status-header">
                            <span class="status-icon">👥</span>
                            <span class="status-title">Connected Users</span>
                        </div>
                        <div class="status-value" id="connected-users-count">${initialState.connected_users || 0} users</div>
                    </div>
                    
                    <div class="status-card connection-status">
                        <div class="status-header">
                            <span class="status-icon">🔌</span>
                            <span class="status-title">Connection</span>
                        </div>
                        <div class="status-value" id="connection-status">${initialState.admin_instance_url && initialState.admin_instance_url.trim() !== '' ? 'Not tested' : 'No URL configured'}</div>
                    </div>
                    
                    <div class="status-card version-status" id="version-status-card">
                        <div class="status-header">
                            <span class="status-icon" id="version-status-icon">📦</span>
                            <span class="status-title">App Version</span>
                        </div>
                        <div class="status-value" id="version-status">
                            <span id="version-current">v${initialState.version || '1.0.0'}</span>
                            <span id="version-check-result"></span>
                        </div>
                    </div>
                    
                    <div class="status-card">
                        <div class="status-header">
                            <span class="status-icon">🏷️</span>
                            <span class="status-title">Display Name</span>
                        </div>
                        <div class="status-value" id="current-name">${initialState.user_facing_name || 'iTop'}</div>
                    </div>
                    
                    <div class="status-card">
                        <div class="status-header">
                            <span class="status-icon">🌐</span>
                            <span class="status-title">Server URL</span>
                        </div>
                        <div class="status-value" id="current-url">${initialState.admin_instance_url || 'Not configured'}</div>
                    </div>
                    
                    <div class="status-card">
                        <div class="status-header">
                            <span class="status-icon">⏰</span>
                            <span class="status-title">Last Updated</span>
                        </div>
                        <div class="status-value">${initialState.last_updated || 'Never'}</div>
                    </div>
                </div>
            </div>
            
            <div class="settings-section">
                <div class="section-header">
                    <h3>📡 Connection Configuration</h3>
                    <p class="section-description">Configure the connection to your iTop system instance</p>
                </div>
                
                <div class="settings-form">
                    <div class="form-group">
                        <label for="itop-user-facing-name" class="form-label">
                            <span class="icon">🏷️</span>
                            Display Name
                        </label>
                        <input
                            type="text"
                            id="itop-user-facing-name"
                            value="${initialState.user_facing_name || 'iTop'}"
                            placeholder="e.g., ServicePoint, Helpdesk, iTop"
                            class="form-input"
                            maxlength="100"
                        />
                        <p class="form-hint">The name users will see throughout Nextcloud (e.g., "ServicePoint Integration")</p>
                    </div>

                    <div class="form-group">
                        <label for="itop-instance-url" class="form-label">
                            <span class="icon">🌐</span>
                            Server URL
                        </label>
                        <div class="form-input-group">
                            <input
                                type="url"
                                id="itop-instance-url"
                                value="${initialState.admin_instance_url || ''}"
                                placeholder="https://your-itop-server.com"
                                class="form-input"
                            />
                            <button id="test-connection" class="btn-secondary btn-inline" ${!initialState.admin_instance_url ? 'disabled' : ''}>
                                <span class="btn-icon">🔍</span>
                                Test Connection
                            </button>
                        </div>
                        <p class="form-hint">The complete URL to your iTop system instance</p>
                    </div>

                    <div class="form-group">
                        <label for="itop-application-token" class="form-label">
                            <span class="icon">🔑</span>
                            Application Token (Administrator)
                        </label>
                        <div class="form-input-group">
                            <input
                                type="text"
                                id="itop-application-token"
                                value=""
                                placeholder="${initialState.has_application_token ? '••••••••••••••••  (Token saved - enter new token to update)' : 'Paste your iTop Administrator token here'}"
                                class="form-input password-style"
                                autocomplete="off"
                            />
                            <button id="test-application-token" class="btn-secondary btn-inline" disabled>
                                <span class="btn-icon">🔍</span>
                                Test Token
                            </button>
                        </div>
                        <p class="form-hint">
                            <strong>Phase 2:</strong> Required Administrator-level token for querying user data.
                            <a href="https://github.com/LexioJ/integration_itop#admin-configuration-phase-2" target="_blank">How to create →</a>
                        </p>
                        <div class="form-info-box">
                            <strong>⚠️ Important:</strong> This token must have <strong>Administrator</strong> + <strong>REST Services User</strong> profiles.
                            It will be used securely to query user data (filtered by Person ID for security).
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button id="save-itop-config" class="btn-primary">
                            <span class="btn-icon">💾</span>
                            Save Configuration
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="settings-section">
                <div class="section-header">
                    <h3>⚙️ User Permission Requirements</h3>
                    <p class="section-description">Important: Configure these iTop user permissions to prevent connection issues</p>
                </div>
                <div class="info-cards">
                    <div class="info-card permission-steps">
                        <div class="info-icon">📋</div>
                        <div class="info-content">
                            <h4>How to Configure User Permissions</h4>
                            <ol>
                                <li><strong>Admin Setup:</strong> Log into iTop as administrator</li>
                                <li><strong>User Profiles:</strong> Go to <strong>Admin Tools → User Management → User Accounts</strong></li>
                                <li><strong>Edit User:</strong> Select each user who needs Nextcloud integration</li>
                                <li><strong>Add Profile:</strong> In the <strong>Profiles</strong> tab, add <strong>"REST Services User"</strong> profile</li>
                                <li><strong>Save User:</strong> Save the user account</li>
                                <li><strong>User Token:</strong> User creates Personal Token via <em>My Account → Personal Tokens</em></li>
                                <li><strong>⚠️ CRITICAL:</strong> Token must have <strong>"REST API"</strong> selected in <em>Scopes</em></li>
                            </ol>
                        </div>
                    </div>
                    
                    <div class="info-card token-requirements">
                        <div class="info-icon">🔑</div>
                        <div class="info-content">
                            <h4>Personal Token Requirements</h4>
                            <p><strong>Each user must create a Personal Token with specific settings:</strong></p>
                            <ul>
                                <li><strong>Location:</strong> <em>My Account → Personal Tokens</em> in iTop</li>
                                <li><strong>Application Name:</strong> Any descriptive name (e.g., "Nextcloud Integration")</li>
                                <li><strong>⚠️ REQUIRED:</strong> <strong>"REST API"</strong> must be checked in <em>Scopes</em></li>
                                <li><strong>Expiration:</strong> Optional, set according to your policy</li>
                                <li><strong>Important:</strong> Copy token immediately after creation (can't be retrieved later)</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="settings-section">
                <div class="section-header">
                    <h3>💡 Next Steps</h3>
                </div>
                <div class="info-cards">
                    <div class="info-card">
                        <div class="info-icon">👥</div>
                        <div class="info-content">
                            <h4>User Configuration</h4>
                            <p>After setting up permissions, users can configure their personal API tokens in Settings → Personal → ${initialState.user_facing_name || 'iTop'} Integration</p>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-icon">🎯</div>
                        <div class="info-content">
                            <h4>Available Features</h4>
                            <p>Dashboard widgets, unified search, link previews, and notifications for tickets and CIs</p>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        `;

        container.innerHTML = html;
        container.className = 'section';

        // Attach event handlers
        attachEventHandlers();
        
        // Check for app version updates
        checkAppVersion(initialState.version || '1.0.0');
        
        // Auto-test connection if URL is configured and not empty (silently)
        if (initialState.admin_instance_url && initialState.admin_instance_url.trim() !== '') {
            console.log('iTop Admin Settings: Auto-testing connection silently for URL:', initialState.admin_instance_url);
            setTimeout(() => testConnection(true), 1000); // Wait 1 second after page load, silent mode
        } else {
            console.log('iTop Admin Settings: No URL configured, skipping auto-test');
        }
    }

    function attachEventHandlers() {
        const saveButton = document.getElementById('save-itop-config');
        const testButton = document.getElementById('test-connection');
        const testTokenButton = document.getElementById('test-application-token');
        const urlInput = document.getElementById('itop-instance-url');
        const nameInput = document.getElementById('itop-user-facing-name');
        const tokenInput = document.getElementById('itop-application-token');

        if (!saveButton || !urlInput || !nameInput) {
            console.error('iTop Admin Settings: Required elements not found');
            return;
        }

        saveButton.addEventListener('click', function(e) {
            e.preventDefault();
            saveConfiguration();
        });

        if (testButton) {
            testButton.addEventListener('click', function(e) {
                e.preventDefault();
                testConnection();
            });
        }

        if (testTokenButton) {
            testTokenButton.addEventListener('click', function(e) {
                e.preventDefault();
                testApplicationToken();
            });
        }

        // Enable/disable test button based on URL input
        urlInput.addEventListener('input', function() {
            if (testButton) {
                testButton.disabled = !urlInput.value.trim();
            }
        });

        // Enable/disable test token button based on token input
        if (tokenInput && testTokenButton) {
            tokenInput.addEventListener('input', function() {
                const value = tokenInput.value.trim();
                // Enable button if there's a value (including bullets placeholder which means token exists)
                const hasToken = value && value.length > 0;
                testTokenButton.disabled = !hasToken;
            });
        }

        // Also save on Enter key in inputs
        [urlInput, nameInput, tokenInput].forEach(input => {
            if (input) {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        saveConfiguration();
                    }
                });
            }
        });

        console.log('iTop Admin Settings: Event handlers attached');
    }

    function saveConfiguration() {
        console.log('iTop Admin Settings: Save button clicked');

        const urlInput = document.getElementById('itop-instance-url');
        const nameInput = document.getElementById('itop-user-facing-name');
        const tokenInput = document.getElementById('itop-application-token');
        const saveButton = document.getElementById('save-itop-config');
        const resultDiv = document.getElementById('save-result');
        const currentUrlSpan = document.getElementById('current-url');
        const currentNameSpan = document.getElementById('current-name');

        const url = urlInput.value.trim();
        const name = nameInput.value.trim();
        const token = tokenInput.value.trim();

        // Validation
        if (!url && !name && !token) {
            showResult('Please enter at least a URL, display name, or application token', true);
            return;
        }

        // Validate URL if provided
        if (url) {
            try {
                new URL(url);
            } catch (e) {
                showResult('Please enter a valid URL', true);
                return;
            }
        }

        // Validate name length
        if (name.length > 100) {
            showResult('Display name is too long (max 100 characters)', true);
            return;
        }

        // Show saving state
        saveButton.disabled = true;
        const originalText = saveButton.innerHTML;
        saveButton.innerHTML = '<span class="btn-icon">⏳</span> Saving...';

        // Prepare request
        const requestUrl = OC.generateUrl('/apps/integration_itop/admin-config');
        const requestData = {
            values: {}
        };

        // Only include values that are provided
        if (url) requestData.values.admin_instance_url = url;
        if (name) requestData.values.user_facing_name = name || 'iTop';
        // Only send token if it's not the placeholder (bullets)
        if (token && token !== '••••••••••••••••') {
            requestData.values.application_token = token;
        }

        console.log('iTop Admin Settings: Making request to:', requestUrl);
        console.log('iTop Admin Settings: Request data (keys):', Object.keys(requestData.values));

        // Make the request using fetch
        fetch(requestUrl, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken || ''
            },
            body: JSON.stringify(requestData)
        })
        .then(function(response) {
            console.log('iTop Admin Settings: Response status:', response.status);
            if (!response.ok) {
                throw new Error('Server responded with status: ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            console.log('iTop Admin Settings: Response data:', data);

            if (data.message) {
                // Update the current displays
                if (data.admin_instance_url !== undefined && currentUrlSpan) {
                    currentUrlSpan.textContent = data.admin_instance_url || 'Not configured';
                }

                if (data.user_facing_name !== undefined && currentNameSpan) {
                    currentNameSpan.textContent = data.user_facing_name || 'iTop';

                    // Update the page title if name changed
                    const headerTitle = document.querySelector('.header-content h2');
                    if (headerTitle) {
                        headerTitle.textContent = (data.user_facing_name || 'iTop') + ' Integration';
                    }
                }

                // Update token field placeholder if token was saved
                if (data.has_application_token !== undefined && tokenInput) {
                    const testTokenButton = document.getElementById('test-application-token');
                    if (data.has_application_token) {
                        // Clear the field and update placeholder
                        tokenInput.value = '';
                        tokenInput.placeholder = '••••••••••••••••  (Token saved - enter new token to update)';
                        // Disable test token button since field is now empty
                        if (testTokenButton) {
                            testTokenButton.disabled = true;
                        }
                    } else {
                        tokenInput.value = '';
                        tokenInput.placeholder = 'Paste your iTop Administrator token here';
                        // Disable test token button if no token
                        if (testTokenButton) {
                            testTokenButton.disabled = true;
                        }
                    }
                }

                // Show single Nextcloud success notification with checkmark
                if (OC.Notification && OC.Notification.showTemporary) {
                    OC.Notification.showTemporary('Configuration saved successfully \u2705');
                }
            } else {
                showResult('Unexpected response from server', true);
            }
        })
        .catch(function(error) {
            console.error('iTop Admin Settings: Error:', error);
            showResult('Error saving configuration: ' + error.message, true);
            
            // Show Nextcloud error notification
            if (OC.Notification && OC.Notification.showTemporary) {
                OC.Notification.showTemporary('Failed to save iTop configuration');
            }
        })
        .finally(function() {
            saveButton.disabled = false;
            saveButton.innerHTML = originalText;
        });
    }

    function testConnection(silent = false) {
        console.log('iTop Admin Settings: Testing connection...' + (silent ? ' (silent mode)' : ''));

        const testButton = document.getElementById('test-connection');
        const statusElement = document.getElementById('connection-status');
        const statusCard = document.querySelector('.connection-status');
        const urlInput = document.getElementById('itop-instance-url');

        if (!testButton || !statusElement || !urlInput) {
            console.error('iTop Admin Settings: Required elements not found');
            return;
        }

        const currentUrl = urlInput.value.trim();

        if (!currentUrl) {
            if (!silent) showResult('Please enter a server URL to test', true);
            return;
        }

        console.log('iTop Admin Settings: Testing URL:', currentUrl);

        // Update UI to show testing state
        testButton.disabled = true;
        testButton.innerHTML = '<span class="btn-icon">⏳</span> Testing...';
        statusElement.textContent = 'Testing connection...';
        if (statusCard) {
            statusCard.className = 'status-card connection-status testing';
        }

        fetch(OC.generateUrl('/apps/integration_itop/admin-config/test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({ url: currentUrl })
        })
        .then(response => response.json())
        .then(data => {
            console.log('iTop Admin Settings: Connection test result:', data);

            if (data.status === 'success') {
                statusElement.textContent = '✅ Connected';
                if (statusCard) {
                    statusCard.className = 'status-card connection-status success';
                }
                if (!silent) showResult('Connection test successful! \ud83c\udf89', false);
            } else if (data.status === 'warning') {
                statusElement.textContent = '⚠️ Warning';
                if (statusCard) {
                    statusCard.className = 'status-card connection-status warning';
                }
                if (!silent) showResult('Connection warning: ' + (data.message || 'Server responded with warning'), true);
            } else {
                statusElement.textContent = '❌ Failed';
                if (statusCard) {
                    statusCard.className = 'status-card connection-status error';
                }
                if (!silent) showResult('Connection test failed: ' + (data.message || 'Unknown error'), true);
            }
        })
        .catch(error => {
            console.error('iTop Admin Settings: Connection test error:', error);
            statusElement.textContent = '❌ Error';
            if (statusCard) {
                statusCard.className = 'status-card connection-status error';
            }
            if (!silent) showResult('Connection test failed: ' + error.message, true);
        })
        .finally(() => {
            testButton.disabled = false;
            testButton.innerHTML = '<span class="btn-icon">🔍</span> Test Connection';
        });
    }

    function testApplicationToken() {
        console.log('iTop Admin Settings: Testing application token...');

        const testButton = document.getElementById('test-application-token');
        const tokenInput = document.getElementById('itop-application-token');

        if (!testButton) {
            console.error('iTop Admin Settings: Test token button not found');
            return;
        }

        if (!tokenInput) {
            console.error('iTop Admin Settings: Token input not found');
            return;
        }

        // Get the current token value from the input field
        const tokenValue = tokenInput.value.trim();

        // Don't test if field is empty
        if (!tokenValue || tokenValue.length === 0) {
            showResult('❌ Please enter an application token first', true);
            return;
        }

        // Update UI to show testing state
        testButton.disabled = true;
        const originalText = testButton.innerHTML;
        testButton.innerHTML = '<span class="btn-icon">⏳</span> Testing...';

        // Send the token value to test
        fetch(OC.generateUrl('/apps/integration_itop/admin-config/test-token'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({
                token: tokenValue
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('iTop Admin Settings: Token test result:', data);

            if (data.status === 'success') {
                // Only show the Nextcloud notification (no showResult)
                if (OC.Notification && OC.Notification.showTemporary) {
                    OC.Notification.showTemporary('Application token validated successfully ✅');
                }
            } else {
                // Show error in result area (no notification for errors)
                showResult('❌ Token test failed: ' + (data.message || 'Unknown error'), true);
            }
        })
        .catch(error => {
            console.error('iTop Admin Settings: Token test error:', error);
            showResult('Token test failed: ' + error.message, true);
        })
        .finally(() => {
            testButton.disabled = false;
            testButton.innerHTML = originalText;
        });
    }

    function checkAppVersion(currentVersion) {
        console.log('iTop Admin Settings: Current version:', currentVersion);

        const versionCheckResult = document.getElementById('version-check-result');
        const versionStatusCard = document.getElementById('version-status-card');

        if (!versionCheckResult) {
            return;
        }

        // Add a clean link to the GitHub releases page
        versionCheckResult.innerHTML = `<a href="https://github.com/LexioJ/integration_itop/releases" target="_blank" style="color: var(--color-text-maxcontrast); text-decoration: none; font-size: 12px; margin-left: 8px;">↗️</a>`;
        if (versionStatusCard) {
            versionStatusCard.className = 'status-card version-status';
        }
    }
    
    function compareVersions(version1, version2) {
        // Simple version comparison (assumes semantic versioning)
        const v1parts = version1.replace(/^v/, '').split('.').map(n => parseInt(n) || 0);
        const v2parts = version2.replace(/^v/, '').split('.').map(n => parseInt(n) || 0);
        
        for (let i = 0; i < Math.max(v1parts.length, v2parts.length); i++) {
            const v1part = v1parts[i] || 0;
            const v2part = v2parts[i] || 0;
            
            if (v1part < v2part) return -1;
            if (v1part > v2part) return 1;
        }
        
        return 0; // Equal
    }
    
    function showResult(message, isError) {
        // Use only Nextcloud's native notification system
        if (OC.Notification && OC.Notification.showTemporary) {
            if (isError) {
                OC.Notification.showTemporary(message);
            } else {
                OC.Notification.showTemporary(message);
            }
        }
        
        // Log to console for debugging
        if (isError) {
            console.error('iTop Admin Settings:', message);
        } else {
            console.log('iTop Admin Settings:', message);
        }
    }

    console.log('iTop Admin Settings: Script loaded');
})();
