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

	// Which request form each discovery outcome should offer, if any. One link,
	// never both: being unable to link *because of policy* is a service request
	// ('assistance'), while trying to link and having it *break* is an incident
	// ('problem'). The two typically carry different SLAs.
	//
	// Every status lib/Service/Binding/DiscoveryService.php can produce is
	// listed, so a null is a deliberate "nothing to request" rather than an
	// oversight:
	//   excluded            policy -- this account is excluded from matching
	//   disabled            policy -- self-service was turned off after load
	//   not_self_serviceable  discovery ran and could not link them
	//   failed              iTop could not be reached
	//   already_bound       linked already; nothing to request
	//   proposal, bound     working as intended
	const REQUEST_LINK_BY_STATUS = {
		excluded: 'assistance',
		disabled: 'assistance',
		not_self_serviceable: 'problem',
		failed: 'problem',
		already_bound: null,
		proposal: null,
		bound: null,
	}

	document.addEventListener('DOMContentLoaded', function() {

		const saveButton = document.getElementById('itop-save')
		// Absent, not merely disabled, when an administrator has turned the
		// token binding path off -- see templates/personalSettings.php. Every
		// use below must tolerate that.
		const personalTokenField = document.getElementById('itop-personal-token')
		// Also absent, along with every other control in the search,
		// notification and CI-class sections, while the account is not linked
		// to an iTop person: those sections are not rendered at all. Only
		// #itop-save and #itop-result are unconditional.
		const notificationEnabledField = document.getElementById('itop-notification-enabled')
		const resultDiv = document.getElementById('itop-result')

		// No early return on a missing Save button. It is absent when an
		// unlinked user has no persistable field at all (see
		// templates/personalSettings.php), and returning here would abandon the
		// rest of this module -- including the discovery listener below, leaving
		// a Discover button that renders perfectly and does nothing. That exact
		// bug has already shipped on this page once. Gate the save path only.

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
						renderUserInfo(userInfoValue, data)
						userInfoCard.className = 'itop-personal-status-card connected'
					}
				})
				.catch(() => {
					userInfoValue.textContent = 'Error loading'
				})
		}

		/**
		 * Paints the name/email/organization lines of the user-info card.
		 *
		 * Every one of the three values comes straight out of an iTop Person
		 * record (ConfigController::getUserInfo()), so this app has no say over
		 * what is in them -- a Person whose first_name is markup is a thing any
		 * iTop agent can create. They are therefore only ever set via
		 * textContent, never innerHTML, the same rule
		 * showDiscoveryProposal() below states for the discovered name.
		 *
		 * @param {HTMLElement} target the card's value element, emptied first
		 * @param {object} userInfo the parsed /user-info body
		 */
		function renderUserInfo(target, userInfo) {
			target.textContent = ''

			const wrapper = document.createElement('div')
			wrapper.className = 'itop-status-user-info-simple'

			const name = document.createElement('div')
			name.className = 'itop-status-user-name'
			name.textContent = userInfo.name || 'Unknown User'
			wrapper.appendChild(name)

			if (userInfo.email) {
				const email = document.createElement('div')
				email.className = 'itop-status-user-email'
				email.textContent = userInfo.email
				wrapper.appendChild(email)
			}

			if (userInfo.organization) {
				const org = document.createElement('div')
				org.className = 'itop-status-user-org'
				org.textContent = userInfo.organization
				wrapper.appendChild(org)
			}

			target.appendChild(wrapper)
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
						const total = data.simple ? requests : incidents + requests

						ticketsValue.innerHTML = data.simple
							? `
                        <div class="itop-ticket-counts">
                            <div>${t('integration_itop', 'Tickets:')} <span class="itop-count-large">${total}</span></div>
                        </div>
                    `
							: `
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
		 * Only ever reached from the save handler, which is attached behind a
		 * `saveButton && resultDiv` check, so `resultDiv` is non-null here.
		 *
		 * @param message
		 * @param isError
		 * @param {?string} requestLinkKind which request form to offer below an
		 *   error message -- 'problem' where the binding attempt itself broke,
		 *   or null for none. Ignored on success.
		 */
		function showResult(message, isError = false, requestLinkKind = null) {
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
				appendRequestLink(resultDiv, requestLinkKind)
				resultDiv.classList.remove('hidden')
			} else {
				// For success, only show Nextcloud notification
				showSuccess(message)

				// Clear token field after successful validation, if present
				if (personalTokenField) {
					personalTokenField.value = ''
				}

				// Hide any previous error messages
				resultDiv.classList.add('hidden')
			}
		}

		if (saveButton && resultDiv) {
			saveButton.addEventListener('click', onSave)
		}

		/**
		 * Collects whatever controls are on the page and PUTs them to /config.
		 */
		function onSave() {
			resultDiv.classList.add('hidden')

			const personalToken = personalTokenField ? personalTokenField.value.trim() : ''
			const searchEnabledField = document.getElementById('itop-search-enabled')
			const newsroomMirroringField = document.getElementById('itop-newsroom-mirroring-enabled')

			saveButton.disabled = true
			const originalButtonText = saveButton.innerHTML
			saveButton.innerHTML = '<span class="icon">⏳</span> Saving...'

			// A key is sent only when its control is on the page. Posting '0'
			// for an absent toggle would overwrite a preference the user set
			// while linked, the moment they pressed Save after being unlinked --
			// silently, since the control they would check is not there.
			// ConfigController::setConfig() iterates the submitted keys, so an
			// omitted key leaves the stored value untouched.
			const params = {}

			if (notificationEnabledField) {
				params.notification_enabled = notificationEnabledField.checked ? '1' : '0'
			}
			if (searchEnabledField) {
				params.search_enabled = searchEnabledField.checked ? '1' : '0'
			}
			if (newsroomMirroringField) {
				params.newsroom_mirroring_enabled = newsroomMirroringField.checked ? '1' : '0'
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
								// A token was just validated and the account is
								// now linked. Reload rather than only repainting
								// the status cards: whether Search, Notification
								// and CI-class settings are rendered at all was
								// decided server-side, so without this the user
								// is told they are connected while every toggle
								// is still missing from the page. Same ending as
								// confirmDiscovery() -- the two linking paths
								// must leave the user in the same state.
								// Fallback reuses confirmDiscovery()'s string rather
								// than adding a near-duplicate: the two paths end
								// in the same place and already say the same thing.
								showSuccess(response.message || t('integration_itop', 'Your account is now linked.'))
								window.location.reload()
								return
							}

							showResult(
								response.message || 'Settings saved',
								false,
							)
						} catch (e) {
							showResult('Failed to parse server response', true)
						}
					} else {
						// A token was submitted and the binding did not happen:
						// the token was rejected, iTop could not be reached, or
						// the person is already taken. That is an attempt to
						// link that broke, so it offers the incident form. The
						// one exception is 403, which setConfig() returns only
						// for "token binding is disabled by your administrator"
						// -- policy, so assistance. A save with no token in it
						// is not a binding attempt and gets no link at all.
						const tokenWasSubmitted = typeof params.personal_token !== 'undefined'
						let linkKind = null
						if (tokenWasSubmitted) {
							linkKind = req.status === 403 ? 'assistance' : 'problem'
						}
						try {
							const response = req.responseText ? JSON.parse(req.responseText) : {}
							const errorMessage = response.error || response.message || 'Failed to save settings'
							showResult(errorMessage, true, linkKind)
						} catch (e) {
							showResult('Server error (status: ' + req.status + ')', true, linkKind)
						}
					}
				}
			}

			req.send(JSON.stringify(params))
		}

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
					handleDiscoveryResponse(result)
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
		 * @param {{status: number, body: object}} result the HTTP status and parsed JSON body returned by postBinding()
		 */
		function handleDiscoveryResponse(result) {
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
			// message from the server; not_self_serviceable's is worded by tier
			// server-side (DiscoveryController::notSelfServiceableMessage()), and
			// intentionally names no one regardless of tier.
			const message = body.message || t('integration_itop', 'Your account could not be linked automatically. Please ask an administrator to link it for you.')
			showDiscoveryMessage(message, status !== 200, requestLinkKindFor(body.status, status))
		}

		/**
		 * Which request form a discovery outcome should offer, or null for none.
		 *
		 * An unrecognised status that arrives with an error is breakage, the
		 * same as `failed` -- that is DiscoveryController's `default` branch,
		 * HTTP 500 -- so it offers the incident form. An unrecognised status at
		 * HTTP 200 offers nothing: nothing there says the user is stuck.
		 *
		 * @param {string|undefined} bodyStatus the `status` field of the parsed body
		 * @param {number} httpStatus the HTTP status code
		 * @return {?string} 'assistance', 'problem', or null
		 */
		function requestLinkKindFor(bodyStatus, httpStatus) {
			if (Object.prototype.hasOwnProperty.call(REQUEST_LINK_BY_STATUS, bodyStatus)) {
				return REQUEST_LINK_BY_STATUS[bodyStatus]
			}
			return httpStatus === 200 ? null : 'problem'
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
		 * @param {string} message the text to display
		 * @param {boolean} isError whether to style it as an error
		 * @param {?string} requestLinkKind which request form to offer below the
		 *   message -- 'assistance', 'problem', or null for none
		 */
		function showDiscoveryMessage(message, isError, requestLinkKind = null) {
			discoverResult.innerHTML = ''
			discoverResult.className = isError ? 'itop-discover-result error' : 'itop-discover-result'

			const text = document.createElement('p')
			text.textContent = message
			discoverResult.appendChild(text)

			appendRequestLink(discoverResult, requestLinkKind)

			discoverResult.classList.remove('hidden')
		}

		/**
		 * Appends one administrator-configured request-access link.
		 *
		 * The markup is cloned from the <template> the server rendered, so the
		 * URL pattern and the labels exist in exactly one place
		 * (lib/Settings/Personal.php) and nothing untrusted is interpolated
		 * here. A kind whose id is not configured has no template, and appends
		 * nothing -- never the other kind, which would file the wrong sort of
		 * ticket.
		 *
		 * @param {HTMLElement} target the element to append to
		 * @param {?string} kind 'assistance', 'problem', or null for none
		 */
		function appendRequestLink(target, kind) {
			if (!kind) {
				return
			}
			const template = document.getElementById('itop-request-access-template-' + kind)
			if (!template || !template.content) {
				return
			}
			target.appendChild(template.content.cloneNode(true))
		}
	})
})()
