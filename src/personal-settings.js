/**
 * Nextcloud - iTop Integration
 *
 * Personal settings JavaScript
 */

import { getRequestToken } from '@nextcloud/auth'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

(function() {
	'use strict'

	document.addEventListener('DOMContentLoaded', function() {

		const saveButton = document.getElementById('itop-save')
		const personalTokenField = document.getElementById('itop-personal-token')
		const notificationEnabledField = document.getElementById('itop-notification-enabled')
		const resultDiv = document.getElementById('itop-result')

		if (!saveButton || !personalTokenField || !resultDiv) {
			return
		}

		// Handle notification master toggle
		if (notificationEnabledField) {
			notificationEnabledField.addEventListener('change', function() {
				const notificationContent = document.getElementById('notification-settings-content')
				if (notificationContent) {
					if (notificationEnabledField.checked) {
						notificationContent.style.display = ''
					} else {
						notificationContent.style.display = 'none'
					}
				}
			})
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

			fetch(generateUrl('/apps/integration_itop/user-info'), {
				method: 'GET',
				headers: {
					requesttoken: getRequestToken(),
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

			fetch(generateUrl('/apps/integration_itop/tickets/count'), {
				method: 'GET',
				headers: {
					requesttoken: getRequestToken(),
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
                            <div>${t('integration_itop', 'Incidents:')} <span class="itop-count-large">${incidents}</span></div>
                            <div>${t('integration_itop', 'Requests:')} <span class="itop-count-large">${requests}</span></div>
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
                            <div>${t('integration_itop', 'Incidents:')} <span class="itop-count-large">0</span></div>
                            <div>${t('integration_itop', 'Requests:')} <span class="itop-count-large">0</span></div>
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
				showSuccess(message)

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
			const searchEnabled = document.getElementById('itop-search-enabled')

			saveButton.disabled = true
			const originalButtonText = saveButton.innerHTML
			saveButton.innerHTML = '<span class="icon">⏳</span> Saving...'

			const newsroomMirroringField = document.getElementById('itop-newsroom-mirroring-enabled')

			const params = {
				notification_enabled: notificationEnabled,
				search_enabled: searchEnabled && searchEnabled.checked ? '1' : '0',
				newsroom_mirroring_enabled: newsroomMirroringField && newsroomMirroringField.checked ? '1' : '0',
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
			// notification_enabled is used as master toggle - no need to store 'all'
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

			// Collect notification check interval with validation
			const intervalField = document.getElementById('notification-check-interval')
			if (intervalField) {
				const interval = parseInt(intervalField.value)

				// Validate interval range
				if (isNaN(interval) || interval < 5 || interval > 1440) {
					saveButton.disabled = false
					saveButton.innerHTML = originalButtonText

					let errorMsg = 'Invalid notification check interval. '
					if (isNaN(interval)) {
						errorMsg += 'Please enter a valid number.'
					} else if (interval < 5) {
						errorMsg += 'Minimum value is 5 minutes.'
					} else {
						errorMsg += 'Maximum value is 1440 minutes (24 hours).'
					}

					showResult(errorMsg, true)
					return
				}

				params.notification_check_interval = interval
			}

			const req = new XMLHttpRequest()
			req.open('PUT', generateUrl('/apps/integration_itop/config'))
			req.setRequestHeader('requesttoken', getRequestToken())
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

		// Self-service discovery: "Discover me in <instance>" button.
		const discoverButton = document.getElementById('itop-discover-button')
		const discoverResult = document.getElementById('itop-discover-result')

		if (discoverButton && discoverResult) {
			discoverButton.addEventListener('click', function() {
				runDiscovery()
			})
		}

		/**
		 * POSTs to one of the self-service discovery endpoints and resolves
		 * with both the HTTP status and the parsed body, because the tier
		 * messages and the 429 case both need the status to decide what to show.
		 *
		 * @param {string} url the full endpoint URL, from generateUrl()
		 * @return {Promise<{status: number, body: object}>}
		 */
		function postBinding(url) {
			return fetch(url, {
				method: 'POST',
				headers: {
					requesttoken: getRequestToken(),
				},
			}).then(function(response) {
				return response.json()
					.catch(function() {
						return {}
					})
					.then(function(body) {
						return { status: response.status, body }
					})
			})
		}

		/**
		 * Starts the two-step discovery flow by calling /binding/discover.
		 */
		function runDiscovery() {
			discoverButton.disabled = true
			discoverResult.classList.add('hidden')
			discoverResult.innerHTML = ''

			postBinding(generateUrl('/apps/integration_itop/binding/discover'))
				.then(function(result) {
					discoverButton.disabled = false
					handleDiscoveryResponse(result, runDiscovery)
				})
				.catch(function() {
					discoverButton.disabled = false
					showDiscoveryMessage(t('integration_itop', 'Could not reach the server. Please try again.'), true)
				})
		}

		/**
		 * Confirms a previously shown proposal by calling /binding/discover/confirm.
		 *
		 * @param {HTMLButtonElement} confirmButton the button that triggered this, disabled while in flight
		 */
		function confirmDiscovery(confirmButton) {
			confirmButton.disabled = true

			postBinding(generateUrl('/apps/integration_itop/binding/discover/confirm'))
				.then(function(result) {
					if (result.status === 200 && result.body && result.body.status === 'bound') {
						showSuccess(t('integration_itop', 'Your account is now linked.'))
						window.location.reload()
						return
					}

					confirmButton.disabled = false
					handleDiscoveryResponse(result)
				})
				.catch(function() {
					confirmButton.disabled = false
					showDiscoveryMessage(t('integration_itop', 'Could not reach the server. Please try again.'), true)
				})
		}

		/**
		 * Renders whatever /binding/discover or /binding/discover/confirm came
		 * back with.
		 *
		 * A `proposal` is the only case with something to confirm, so it is the
		 * only one that renders a button; every other outcome -- including
		 * `not_self_serviceable`, which deliberately carries no name or id, only
		 * a tier -- is shown as a plain message that ends at "ask an
		 * administrator".
		 *
		 * @param {{status: number, body: object}} result
		 * @param {Function} [retry] called if the user hits the rate limit and wants to try again
		 */
		function handleDiscoveryResponse(result, retry) {
			const status = result.status
			const body = result.body || {}

			if (status === 429) {
				showDiscoveryMessage(t('integration_itop', 'You have tried this a few times already. Please wait a moment and try again.'), true)
				return
			}

			if (status === 200 && body.status === 'proposal') {
				showDiscoveryProposal(body.itopName || '')
				return
			}

			// Every other outcome (not_self_serviceable, already_bound, excluded,
			// disabled, failed, or an unexpected error) carries a ready-to-show
			// message from the server; not_self_serviceable's is already worded
			// by tier server-side, and intentionally names no one.
			const message = body.message || t('integration_itop', 'Your account could not be linked automatically. Please ask an administrator to link it for you.')
			showDiscoveryMessage(message, status !== 200)
		}

		/**
		 * Shows the matched iTop name and a confirm/cancel pair.
		 *
		 * @param {string} itopName the name as returned by iTop; untrusted, so it
		 *   is only ever set via textContent, never innerHTML
		 */
		function showDiscoveryProposal(itopName) {
			discoverResult.innerHTML = ''
			discoverResult.className = 'itop-discover-result proposal'

			const text = document.createElement('p')
			text.textContent = t('integration_itop', 'We found a matching person: {name}. Is this you?', {
				name: itopName,
			})
			discoverResult.appendChild(text)

			const actions = document.createElement('div')
			actions.className = 'itop-discover-actions'

			const confirmButton = document.createElement('button')
			confirmButton.className = 'button primary'
			confirmButton.type = 'button'
			confirmButton.textContent = t('integration_itop', 'Yes, this is me')
			confirmButton.addEventListener('click', function() {
				confirmDiscovery(confirmButton)
			})

			const cancelButton = document.createElement('button')
			cancelButton.className = 'button'
			cancelButton.type = 'button'
			cancelButton.textContent = t('integration_itop', 'Cancel')
			cancelButton.addEventListener('click', function() {
				discoverResult.classList.add('hidden')
				discoverResult.innerHTML = ''
			})

			actions.appendChild(confirmButton)
			actions.appendChild(cancelButton)
			discoverResult.appendChild(actions)

			discoverResult.classList.remove('hidden')
		}

		/**
		 * Shows a plain message below the discover button.
		 *
		 * @param {string} message
		 * @param {boolean} isError
		 */
		function showDiscoveryMessage(message, isError) {
			discoverResult.innerHTML = ''
			discoverResult.className = isError ? 'itop-discover-result error' : 'itop-discover-result'

			const text = document.createElement('p')
			text.textContent = message
			discoverResult.appendChild(text)

			discoverResult.classList.remove('hidden')
		}
	})
})()
