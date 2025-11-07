/**
 * Nextcloud - iTop Integration
 *
 * Personal settings JavaScript
 */

(function() {
	'use strict'

	// Use Nextcloud's global translation function
	const t = window.t || function(app, text) { return text }

	document.addEventListener('DOMContentLoaded', function() {

		const saveButton = document.getElementById('itop-save')
		const personalTokenField = document.getElementById('itop-personal-token')
		const notificationEnabledField = document.getElementById('itop-notification-enabled')
		const resultDiv = document.getElementById('itop-result')

		if (!saveButton || !personalTokenField || !resultDiv) {
			return
		}

		// Check if user is configured and load data if so (use CSS class, not translated text)
		const connectionStatusCard = document.getElementById('itop-personal-connection-status')
		if (connectionStatusCard && connectionStatusCard.classList.contains('success')) {
			loadUserDataIfConfigured()
		}

		/**
		 *
		 */
		function loadUserDataIfConfigured() {
			// Load user info and ticket count if configured
			fetchUserInfo()
			fetchTicketCount()
		}

		/**
		 *
		 */
		function fetchUserInfo() {
			const userInfoValue = document.getElementById('itop-personal-user-value')
			const userInfoCard = document.getElementById('itop-personal-user-info')

			if (!userInfoValue || !userInfoCard) {
				return
			}

			fetch(OC.generateUrl('/apps/integration_itop/user-info'), {
				method: 'GET',
				headers: {
					requesttoken: OC.requestToken,
				},
			})
				.then(response => response.json())
				.then(data => {
					if (data.error) {
						userInfoValue.textContent = 'Error: ' + data.error
					} else {
						const displayName = data.name || 'Unknown User'
						const email = data.email || ''
						const organization = data.organization || ''

						userInfoValue.innerHTML = `
                        <div class="itop-status-user-info-simple">
                            <div class="itop-status-user-name">${displayName}</div>
                            ${email ? `<div class="itop-status-user-email">${email}</div>` : ''}
                            ${organization ? `<div class="itop-status-user-org">${organization}</div>` : ''}
                        </div>
                    `
						userInfoCard.className = 'itop-personal-status-card connected'
					}
				})
				.catch(() => {
					userInfoValue.textContent = 'Error loading'
				})
		}

		/**
		 *
		 * @param userInfo
		 */
		function updateStatusCards(userInfo) {
			// Update Connection Status Card
			const connectionStatusCard = document.getElementById('itop-personal-connection-status')
			const connectionValue = document.getElementById('itop-personal-connection-value')

			if (connectionStatusCard && connectionValue) {
				connectionStatusCard.className = 'itop-personal-status-card itop-connection-status success'
				connectionValue.textContent = 'Configured'
			}

			// Update User Info Card
			const userInfoCard = document.getElementById('itop-personal-user-info')
			const userInfoValue = document.getElementById('itop-personal-user-value')

			if (userInfoCard && userInfoValue && userInfo) {
				userInfoCard.className = 'itop-personal-status-card connected'
				const displayName = userInfo.name || 'Unknown User'
				const email = userInfo.email || ''
				const organization = userInfo.organization || ''

				userInfoValue.innerHTML = `
                    <div class="itop-status-user-info-simple">
                        <div class="itop-status-user-name">${displayName}</div>
                        ${email ? `<div class="itop-status-user-email">${email}</div>` : ''}
                        ${organization ? `<div class="itop-status-user-org">${organization}</div>` : ''}
                    </div>
                `
			}

			// Update Tickets Card
			fetchTicketCount()
		}

		/**
		 *
		 */
		function fetchTicketCount() {
			const ticketsValue = document.getElementById('itop-personal-tickets-value')
			const ticketsCard = document.getElementById('itop-personal-tickets-info')

			if (!ticketsValue || !ticketsCard) {
				return
			}

			ticketsValue.textContent = 'Loading...'

			fetch(OC.generateUrl('/apps/integration_itop/tickets/count'), {
				method: 'GET',
				headers: {
					requesttoken: OC.requestToken,
				},
			})
				.then(response => response.json())
				.then(data => {
					if (data.error) {
						ticketsValue.textContent = 'Error: ' + data.error
					} else if (typeof data.incidents !== 'undefined' && typeof data.requests !== 'undefined') {
						const incidents = data.incidents || 0
						const requests = data.requests || 0
						const total = incidents + requests

						ticketsValue.innerHTML = `
                        <div class="itop-ticket-counts">
                            <div>${t('integration_itop', 'Incident(s):')} <span class="itop-count-large">${incidents}</span></div>
                            <div>${t('integration_itop', 'Request(s):')} <span class="itop-count-large">${requests}</span></div>
                        </div>
                    `

						if (total > 0) {
							ticketsCard.className = 'itop-personal-status-card has-tickets connected'
						} else {
							ticketsCard.className = 'itop-personal-status-card connected'
						}
					} else {
						ticketsValue.innerHTML = `
                        <div class="itop-ticket-counts">
                            <div>${t('integration_itop', 'Incident(s):')} <span class="itop-count-large">0</span></div>
                            <div>${t('integration_itop', 'Request(s):')} <span class="itop-count-large">0</span></div>
                        </div>
                    `
					}
				})
				.catch(() => {
					ticketsValue.textContent = 'Error loading'
				})
		}

		/**
		 *
		 * @param message
		 * @param isError
		 * @param userInfo
		 */
		function showResult(message, isError = false, userInfo = null) {
			// Update status cards if we have user info
			if (userInfo) {
				updateStatusCards(userInfo)
			}

			// For errors, show in the resultDiv
			if (isError) {
				resultDiv.innerHTML = ''
				resultDiv.className = 'error'

				// Create main message row
				const mainRow = document.createElement('div')
				mainRow.className = 'result-main'

				const icon = document.createElement('span')
				icon.className = 'icon icon-error'

				const messageSpan = document.createElement('span')
				messageSpan.className = 'message'
				messageSpan.textContent = message

				mainRow.appendChild(icon)
				mainRow.appendChild(messageSpan)

				// Add close button
				const closeButton = document.createElement('button')
				closeButton.className = 'close-button'
				closeButton.innerHTML = '×'
				closeButton.title = 'Close'
				closeButton.addEventListener('click', () => {
					resultDiv.classList.add('hidden')
				})
				mainRow.appendChild(closeButton)

				resultDiv.appendChild(mainRow)
				resultDiv.classList.remove('hidden')
			} else {
				// For success, only show Nextcloud notification
				if (OC.Notification && OC.Notification.showTemporary) {
					OC.Notification.showTemporary(message + ' ✅')
				}

				// Clear token field after successful validation
				personalTokenField.value = ''

				// Hide any previous error messages
				resultDiv.classList.add('hidden')
			}
		}

		saveButton.addEventListener('click', function() {
			resultDiv.classList.add('hidden')

			const personalToken = personalTokenField.value.trim()
			const notificationEnabled = notificationEnabledField.checked ? '1' : '0'

			saveButton.disabled = true
			const originalButtonText = saveButton.innerHTML
			saveButton.innerHTML = '<span class="icon">⏳</span> Saving...'

			const params = {
				notification_enabled: notificationEnabled,
			}

			// Send personal_token if provided (used for identity verification only)
			if (personalToken && personalToken.trim() !== '') {
				params.personal_token = personalToken
			}

			// Collect user CI class preferences
			const ciClassCheckboxes = document.querySelectorAll('input[name="user_ci_class"]')
			if (ciClassCheckboxes.length > 0) {
				const disabledClasses = []
				ciClassCheckboxes.forEach(function(checkbox) {
					if (!checkbox.checked) {
						disabledClasses.push(checkbox.value)
					}
				})
				// Send disabled classes to backend
				params.disabled_ci_classes = disabledClasses
			}

			// Collect notification preferences (3-state system)
			const notificationCheckboxes = document.querySelectorAll('input[data-notification-type]')
			if (notificationCheckboxes.length > 0) {
				const disabledPortalNotifications = []
				const disabledAgentNotifications = []

				notificationCheckboxes.forEach(function(checkbox) {
					if (!checkbox.checked) {
						const notificationType = checkbox.dataset.notificationType
						const notificationName = checkbox.dataset.notification

						if (notificationType === 'portal') {
							disabledPortalNotifications.push(notificationName)
						} else if (notificationType === 'agent') {
							disabledAgentNotifications.push(notificationName)
						}
					}
				})

				// Send disabled notification arrays to backend
				if (disabledPortalNotifications.length > 0) {
					params.disabled_portal_notifications = disabledPortalNotifications
				} else {
					// Empty array = enable all user_choice types
					params.disabled_portal_notifications = []
				}

				if (disabledAgentNotifications.length > 0) {
					params.disabled_agent_notifications = disabledAgentNotifications
				} else {
					// Empty array = enable all user_choice types
					params.disabled_agent_notifications = []
				}
			}

			// Collect notification check interval
			const intervalField = document.getElementById('notification-check-interval')
			if (intervalField) {
				const interval = parseInt(intervalField.value)
				if (interval >= 5 && interval <= 1440) {
					params.notification_check_interval = interval
				}
			}

			const req = new XMLHttpRequest()
			req.open('PUT', OC.generateUrl('/apps/integration_itop/config'))
			req.setRequestHeader('requesttoken', OC.requestToken)
			req.setRequestHeader('Content-Type', 'application/json')

			req.onreadystatechange = function() {
				if (req.readyState === 4) {
					saveButton.disabled = false
					saveButton.innerHTML = originalButtonText

					if (req.status === 200) {
						try {
							const response = JSON.parse(req.responseText)

							if (response.person_id_configured && response.user_info) {
								showResult(
									response.message || 'Configuration successful!',
									false,
									response.user_info,
								)
							} else {
								showResult(
									response.message || 'Settings saved',
									false,
								)
							}
						} catch (e) {
							showResult('Failed to parse server response', true)
						}
					} else {
						try {
							const response = req.responseText ? JSON.parse(req.responseText) : {}
							const errorMessage = response.error || response.message || 'Failed to save settings'
							showResult(errorMessage, true)
						} catch (e) {
							showResult('Server error (status: ' + req.status + ')', true)
						}
					}
				}
			}

			req.send(JSON.stringify(params))
		})
	})
})()
