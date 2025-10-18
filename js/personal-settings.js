/**
 * Nextcloud - iTop Integration
 *
 * Personal settings JavaScript
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        const saveButton = document.getElementById('itop-save');
        const personalTokenField = document.getElementById('itop-personal-token');
        const notificationEnabledField = document.getElementById('itop-notification-enabled');
        const resultDiv = document.getElementById('itop-result');

        if (!saveButton || !personalTokenField || !resultDiv) {
            return;
        }

        // Check if user is configured and load data if so
        const connectionStatusValue = document.getElementById('itop-personal-connection-value');
        if (connectionStatusValue && connectionStatusValue.textContent.trim() === 'Configured') {
            loadUserDataIfConfigured();
        }

        function loadUserDataIfConfigured() {
            // Load user info and ticket count if configured
            fetchUserInfo();
            fetchTicketCount();
        }

        function fetchUserInfo() {
            const userInfoValue = document.getElementById('itop-personal-user-value');
            const userInfoCard = document.getElementById('itop-personal-user-info');

            if (!userInfoValue || !userInfoCard) {
                return;
            }

            fetch(OC.generateUrl('/apps/integration_itop/user-info'), {
                method: 'GET',
                headers: {
                    'requesttoken': OC.requestToken
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    userInfoValue.textContent = 'Error: ' + data.error;
                } else {
                    const displayName = data.name || 'Unknown User';
                    const email = data.email || '';
                    const organization = data.organization || '';

                    userInfoValue.innerHTML = `
                        <div class="itop-status-user-info-simple">
                            <div class="itop-status-user-name">${displayName}</div>
                            ${email ? `<div class="itop-status-user-email">${email}</div>` : ''}
                            ${organization ? `<div class="itop-status-user-org">${organization}</div>` : ''}
                        </div>
                    `;
                    userInfoCard.className = 'itop-personal-status-card connected';
                }
            })
            .catch(error => {
                userInfoValue.textContent = 'Error loading';
            });
        }

        function updateStatusCards(userInfo) {
            // Update Connection Status Card
            const connectionStatusCard = document.getElementById('itop-personal-connection-status');
            const connectionValue = document.getElementById('itop-personal-connection-value');

            if (connectionStatusCard && connectionValue) {
                connectionStatusCard.className = 'itop-personal-status-card itop-connection-status success';
                connectionValue.textContent = 'Configured';
            }

            // Update User Info Card
            const userInfoCard = document.getElementById('itop-personal-user-info');
            const userInfoValue = document.getElementById('itop-personal-user-value');

            if (userInfoCard && userInfoValue && userInfo) {
                userInfoCard.className = 'itop-personal-status-card connected';
                const displayName = userInfo.name || 'Unknown User';
                const email = userInfo.email || '';
                const organization = userInfo.organization || '';

                userInfoValue.innerHTML = `
                    <div class="itop-status-user-info-simple">
                        <div class="itop-status-user-name">${displayName}</div>
                        ${email ? `<div class="itop-status-user-email">${email}</div>` : ''}
                        ${organization ? `<div class="itop-status-user-org">${organization}</div>` : ''}
                    </div>
                `;
            }

            // Update Tickets Card
            fetchTicketCount();
        }

        function fetchTicketCount() {
            const ticketsValue = document.getElementById('itop-personal-tickets-value');
            const ticketsCard = document.getElementById('itop-personal-tickets-info');

            if (!ticketsValue || !ticketsCard) {
                return;
            }

            ticketsValue.textContent = 'Loading...';

            fetch(OC.generateUrl('/apps/integration_itop/tickets/count'), {
                method: 'GET',
                headers: {
                    'requesttoken': OC.requestToken
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    ticketsValue.textContent = 'Error: ' + data.error;
                } else if (typeof data.incidents !== 'undefined' && typeof data.requests !== 'undefined') {
                    const incidents = data.incidents || 0;
                    const requests = data.requests || 0;
                    const total = incidents + requests;

                    ticketsValue.innerHTML = `
                        <div class="itop-ticket-counts">
                            Incident(s): <span class="itop-count-large">${incidents}</span> |
                            Request(s): <span class="itop-count-large">${requests}</span>
                        </div>
                    `;

                    if (total > 0) {
                        ticketsCard.className = 'itop-personal-status-card has-tickets connected';
                    } else {
                        ticketsCard.className = 'itop-personal-status-card connected';
                    }
                } else {
                    ticketsValue.innerHTML = 'Incident(s): <span class="itop-count-large">0</span> | Request(s): <span class="itop-count-large">0</span>';
                }
            })
            .catch(error => {
                ticketsValue.textContent = 'Error loading';
            });
        }

        function showResult(message, isError = false, userInfo = null) {
            resultDiv.innerHTML = '';
            resultDiv.className = isError ? 'error' : 'success';

            // Update status cards if we have user info
            if (userInfo) {
                updateStatusCards(userInfo);
            }

            // Create main message row
            const mainRow = document.createElement('div');
            mainRow.className = 'result-main';

            const icon = document.createElement('span');
            icon.className = 'icon ' + (isError ? 'icon-error' : 'icon-checkmark');

            const messageSpan = document.createElement('span');
            messageSpan.className = 'message';
            messageSpan.textContent = message;

            mainRow.appendChild(icon);
            mainRow.appendChild(messageSpan);

            // Add close button
            const closeButton = document.createElement('button');
            closeButton.className = 'close-button';
            closeButton.innerHTML = '×';
            closeButton.title = 'Close';
            closeButton.addEventListener('click', () => {
                resultDiv.classList.add('hidden');
            });
            mainRow.appendChild(closeButton);

            resultDiv.appendChild(mainRow);

            // Show detailed info for successful connection
            if (!isError && userInfo) {
                const detailsHTML = `
                    <div class="success-details">
                        <p><strong>✅ Connected as:</strong> ${userInfo.name}</p>
                        ${userInfo.email ? `<p><strong>📧 Email:</strong> ${userInfo.email}</p>` : ''}
                        ${userInfo.organization ? `<p><strong>🏢 Organization:</strong> ${userInfo.organization}</p>` : ''}
                        <p><strong>🆔 Person ID:</strong> ${userInfo.person_id}</p>
                    </div>
                `;

                const detailsDiv = document.createElement('div');
                detailsDiv.innerHTML = detailsHTML;
                resultDiv.appendChild(detailsDiv);
            }

            resultDiv.classList.remove('hidden');

            // Show Nextcloud notification for success
            if (!isError) {
                if (OC.Notification && OC.Notification.showTemporary) {
                    OC.Notification.showTemporary(message + ' ✅');
                }

                // Clear token field after successful validation
                personalTokenField.value = '';

                // Auto-hide after 5 seconds
                setTimeout(() => {
                    resultDiv.classList.add('hidden');
                }, 5000);
            }
        }

        saveButton.addEventListener('click', function() {
            resultDiv.classList.add('hidden');

            const personalToken = personalTokenField.value.trim();
            const notificationEnabled = notificationEnabledField.checked ? '1' : '0';

            saveButton.disabled = true;
            const originalButtonText = saveButton.innerHTML;
            saveButton.innerHTML = '<span class="icon">⏳</span> Saving...';

            const params = {
                notification_enabled: notificationEnabled
            };

            // Send personal_token if provided (used for identity verification only)
            if (personalToken && personalToken.trim() !== '') {
                params.personal_token = personalToken;
            }

            const req = new XMLHttpRequest();
            req.open('PUT', OC.generateUrl('/apps/integration_itop/config'));
            req.setRequestHeader('requesttoken', OC.requestToken);
            req.setRequestHeader('Content-Type', 'application/json');

            req.onreadystatechange = function() {
                if (req.readyState === 4) {
                    saveButton.disabled = false;
                    saveButton.innerHTML = originalButtonText;

                    if (req.status === 200) {
                        try {
                            const response = JSON.parse(req.responseText);

                            if (response.person_id_configured && response.user_info) {
                                showResult(
                                    response.message || 'Configuration successful!',
                                    false,
                                    response.user_info
                                );
                            } else {
                                showResult(
                                    response.message || 'Settings saved',
                                    false
                                );
                            }
                        } catch (e) {
                            showResult('Failed to parse server response', true);
                        }
                    } else {
                        try {
                            const response = req.responseText ? JSON.parse(req.responseText) : {};
                            const errorMessage = response.error || response.message || 'Failed to save settings';
                            showResult(errorMessage, true);
                        } catch (e) {
                            showResult('Server error (status: ' + req.status + ')', true);
                        }
                    }
                }
            };

            req.send(JSON.stringify(params));
        });
    });
})();
