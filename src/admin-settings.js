/**
 * Nextcloud - iTop Integration
 *
 * Admin settings JavaScript
 * Handles events and dynamic updates for server-rendered HTML
 */

import { getRequestToken } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

(function() {
	'use strict'

	// Wait for DOM to be ready
	document.addEventListener('DOMContentLoaded', function() {
		// Attach all event handlers
		attachEventHandlers()

		// Check for app version updates
		const versionElement = document.getElementById('version-current')
		if (versionElement) {
			const currentVersion = versionElement.textContent.replace('v', '')
			checkAppVersion(currentVersion)
		}

		// Auto-test connection if URL is configured
		const urlInput = document.getElementById('itop-instance-url')
		if (urlInput && urlInput.value.trim() !== '') {
			setTimeout(() => testConnection(true), 1000)
		}

		// Load connected users count via AJAX
		loadConnectedUsersCount()

		// Smooth scroll for the section chips. prefers-reduced-motion is
		// honoured because a full-page jump-scroll is exactly the kind of
		// motion that triggers vestibular symptoms.
		const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
		if (!reduceMotion) {
			document.querySelectorAll('.itop-chip').forEach(function(chip) {
				chip.addEventListener('click', function(e) {
					const target = document.querySelector(chip.getAttribute('href'))
					if (!target) return
					e.preventDefault()
					target.scrollIntoView({ behavior: 'smooth', block: 'start' })
				})
			})
		}
	})

	/**
	 *
	 */
	function attachEventHandlers() {
		// Main configuration form
		const saveButton = document.getElementById('save-itop-config')
		const testButton = document.getElementById('test-connection')
		const testTokenButton = document.getElementById('test-application-token')
		const urlInput = document.getElementById('itop-instance-url')
		const nameInput = document.getElementById('itop-user-facing-name')
		const tokenInput = document.getElementById('itop-application-token')

		if (saveButton) {
			saveButton.addEventListener('click', function(e) {
				e.preventDefault()
				saveConfiguration()
			})
		}

		if (testButton) {
			testButton.addEventListener('click', function(e) {
				e.preventDefault()
				testConnection()
			})
		}

		if (testTokenButton) {
			testTokenButton.addEventListener('click', function(e) {
				e.preventDefault()
				testApplicationToken()
			})
		}

		// Enable/disable test button based on URL input
		if (urlInput && testButton) {
			urlInput.addEventListener('input', function() {
				testButton.disabled = !urlInput.value.trim()
			})
		}

		// Enable/disable test token button based on token input
		if (tokenInput && testTokenButton) {
			tokenInput.addEventListener('input', function() {
				testTokenButton.disabled = !tokenInput.value.trim()
			})
		}

		// Save on Enter key
		[urlInput, nameInput, tokenInput].forEach(input => {
			if (input) {
				input.addEventListener('keypress', function(e) {
					if (e.key === 'Enter') {
						e.preventDefault()
						saveConfiguration()
					}
				})
			}
		})

		// Notification settings (legacy)
		const saveNotificationButton = document.getElementById('save-notification-settings')

		if (saveNotificationButton) {
			saveNotificationButton.addEventListener('click', function(e) {
				e.preventDefault()
				saveNotificationSettings()
			})
		}

		// Notification configuration (3-state)
		const saveNotificationConfigButton = document.getElementById('save-notification-config')

		if (saveNotificationConfigButton) {
			saveNotificationConfigButton.addEventListener('click', function(e) {
				e.preventDefault()
				saveNotificationConfig()
			})
		}

		// Cache settings
		const saveCacheButton = document.getElementById('save-cache-settings')
		const clearCacheButton = document.getElementById('clear-all-cache')

		if (saveCacheButton) {
			saveCacheButton.addEventListener('click', function(e) {
				e.preventDefault()
				saveCacheSettings()
			})
		}

		if (clearCacheButton) {
			clearCacheButton.addEventListener('click', function(e) {
				e.preventDefault()
				clearAllCache()
			})
		}

		// CI class configuration
		const saveCIClassesButton = document.getElementById('save-ci-classes')
		const toggleAllButton = document.getElementById('toggle-all-ci-classes')

		if (saveCIClassesButton) {
			saveCIClassesButton.addEventListener('click', function(e) {
				e.preventDefault()
				saveCIClasses()
			})
		}

		if (toggleAllButton) {
			toggleAllButton.addEventListener('click', function(e) {
				e.preventDefault()
				toggleAllCIClasses()
			})
		}

		// Ticket system type
		const saveTicketSystemTypeButton = document.getElementById('save-ticket-system-type')
		if (saveTicketSystemTypeButton) {
			saveTicketSystemTypeButton.addEventListener('click', function(e) {
				e.preventDefault()
				saveTicketSystemType()
			})
		}

		// Show/hide simple-mode enum options when radio selection changes
		document.querySelectorAll('input[name="ticket-system-type"]').forEach(function(radio) {
			radio.addEventListener('change', function() {
				const simpleOptions = document.getElementById('simple-mode-options')
				if (simpleOptions) {
					simpleOptions.style.display = (this.value === 'simple') ? '' : 'none'
				}
				document.querySelectorAll('.ticket-type-option').forEach(function(label) {
					label.classList.remove('active')
				})
				this.closest('.ticket-type-option')?.classList.add('active')
			})
		})

		// Notification toggle all button
		const toggleAllNotificationsButton = document.getElementById('toggle-all-notifications')

		if (toggleAllNotificationsButton) {
			toggleAllNotificationsButton.addEventListener('click', function(e) {
				e.preventDefault()
				toggleAllNotifications()
			})
		}

		// CI class state toggle buttons (delegated to also cover dynamically added custom class rows)
		document.addEventListener('click', function(e) {
			// A click whose target is not an Element -- document itself, or a
			// text node from a synthetic event -- has no closest(). Each
			// handler below is a single delegated listener, so letting it
			// throw would kill that interaction until the page is reloaded.
			if (!(e.target instanceof Element)) return
			const button = e.target.closest('.state-button')
			if (!button) return

			const toggleGroup = button.closest('.state-toggle-group')
			if (!toggleGroup) return

			e.preventDefault()

			// Remove active class from all buttons in this group
			toggleGroup.querySelectorAll('.state-button').forEach(function(btn) {
				btn.classList.remove('active')
			})

			// Add active class to clicked button
			button.classList.add('active')
		})

		// Remove custom CI class button (delegated)
		document.addEventListener('click', function(e) {
			if (!(e.target instanceof Element)) return
			const btn = e.target.closest('.remove-custom-class')
			if (!btn) return
			e.preventDefault()
			const className = btn.dataset.class
			removeCustomCIClass(className, btn)
		})

		// Upload/overwrite a custom CI class icon by clicking the icon image (delegated)
		document.addEventListener('click', function(e) {
			if (!(e.target instanceof Element)) return
			const iconEl = e.target.closest('#custom-ci-class-config-grid .ci-class-icon')
			if (!iconEl) return
			e.preventDefault()
			const row = iconEl.closest('.ci-class-config-row')
			uploadCustomClassIcon(iconEl.dataset.class || (row ? row.id.replace('custom-ci-row-', '') : ''))
		})

		// Identity binding match rule (delegated, like the handlers above, so a
		// direct getElementById().addEventListener() lookup at attach time can't
		// miss the button)
		document.addEventListener('click', function(e) {
			if (!(e.target instanceof Element)) return
			if (!e.target.closest('#itop-binding-rule-save')) return
			e.preventDefault()
			saveBindingRule()
		})

		// Self-service toggles: personal token and discovery (delegated, same
		// reason as the matching-rule save button above)
		document.addEventListener('click', function(e) {
			if (!(e.target instanceof Element)) return
			if (!e.target.closest('#itop-binding-self-service-save')) return
			e.preventDefault()
			saveSelfServiceMethods()
		})

		// Warn as soon as the admin picks a mutable source, before they save --
		// mutability is per source and server-controlled (it depends on
		// allow_user_to_change_email / allow_user_to_change_display_name), so
		// it's read from the data-mutable attribute the server rendered on
		// each <option>, not guessed client-side.
		document.addEventListener('change', function(e) {
			if (!(e.target instanceof Element)) return
			const select = e.target.closest('#itop-binding-source')
			if (!select) return
			const warning = document.getElementById('itop-binding-mutable-warning')
			if (!warning) return
			const option = select.selectedOptions[0]
			const mutable = option ? option.dataset.mutable === '1' : false
			warning.classList.toggle('hidden', !mutable)
		})

		// Custom CI classes: browse button
		const browseButton = document.getElementById('browse-itop-classes')
		if (browseButton) {
			browseButton.addEventListener('click', function(e) {
				e.preventDefault()
				browseItopClasses()
			})
		}

		// Custom CI classes: save button
		const saveCustomCIButton = document.getElementById('save-custom-ci-classes')
		if (saveCustomCIButton) {
			saveCustomCIButton.addEventListener('click', function(e) {
				e.preventDefault()
				saveCustomCIClasses()
			})
		}

		// Make sure existing rows always expose a visible remove button
		ensureCustomClassRemoveButtons()
	}

	/**
	 * Persist the identity-binding match rule.
	 */
	function saveBindingRule() {
		const source = document.getElementById('itop-binding-source').value
		const target = document.getElementById('itop-binding-target').value

		fetch(generateUrl('/apps/integration_itop/binding/rule'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ source, target }),
		})
			.then(response => response.json().then(data => ({ ok: response.ok, data })))
			.then(({ ok, data }) => {
				if (!ok) {
					showNotification(data.message || t('integration_itop', 'Could not save the matching rule'), true)
					resyncBindingRuleForm()
					return
				}
				// The warning depends on the chosen source, so refresh it from the
				// server's answer rather than guessing client-side.
				const warning = document.getElementById('itop-binding-mutable-warning')
				if (warning) {
					warning.classList.toggle('hidden', !data.source_user_mutable)
				}
				showNotification(t('integration_itop', 'Matching rule saved'), false)
				// A tier computed under the old rule is a claim that is no
				// longer true. admin-binding.js listens for this to drop any
				// suggestions it is holding.
				document.dispatchEvent(new CustomEvent('itop:binding-rule-changed'))
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Could not save the matching rule'), true)
				resyncBindingRuleForm()
			})
	}

	/**
	 * Persist the two self-service binding toggles: personal token and
	 * discovery. Admin-side binding has no toggle -- it is always available.
	 */
	function saveSelfServiceMethods() {
		const tokenEnabled = document.getElementById('itop-binding-token-enabled').checked
		const discoveryEnabled = document.getElementById('itop-binding-discovery-enabled').checked

		fetch(generateUrl('/apps/integration_itop/binding/self-service'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ tokenEnabled, discoveryEnabled }),
		})
			.then(response => response.json().then(data => ({ ok: response.ok, data })))
			.then(({ ok, data }) => {
				if (!ok) {
					showNotification(data.message || t('integration_itop', 'Could not save the self-service settings'), true)
					return
				}
				showNotification(t('integration_itop', 'Self-service settings saved'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Could not save the self-service settings'), true)
			})
	}

	/**
	 * Re-read the stored matching rule from the server and reset the form to
	 * it. Used after a failed save so the UI doesn't keep asserting the
	 * unsaved selection (both the selects and the mutability warning).
	 */
	function resyncBindingRuleForm() {
		fetch(generateUrl('/apps/integration_itop/binding/rule'), {
			method: 'GET',
			headers: {
				requesttoken: getRequestToken(),
			},
		})
			.then(response => {
				if (!response.ok) throw new Error('Server error: ' + response.status)
				return response.json()
			})
			.then(data => {
				const sourceSelect = document.getElementById('itop-binding-source')
				const targetSelect = document.getElementById('itop-binding-target')
				const warning = document.getElementById('itop-binding-mutable-warning')
				if (sourceSelect && data.source !== undefined) sourceSelect.value = data.source
				if (targetSelect && data.target !== undefined) targetSelect.value = data.target
				if (warning) warning.classList.toggle('hidden', !data.source_user_mutable)
			})
			.catch(() => {
				// The recovery GET hit the same endpoint on the same server, so
				// it likely failed for the same reason the save did. Fail
				// toward alarming rather than reassuring: show the warning
				// (safe even if it doesn't apply) rather than leave it hidden
				// while the stored source may still be mutable.
				const warning = document.getElementById('itop-binding-mutable-warning')
				if (warning) warning.classList.remove('hidden')
				showNotification(t('integration_itop', 'Could not read the stored matching rule — reload the page to see the saved state'), true)
			})
	}

	/**
	 *
	 */
	function loadConnectedUsersCount() {
		fetch(generateUrl('/apps/integration_itop/admin-config'), {
			method: 'GET',
			headers: {
				requesttoken: getRequestToken(),
			},
		})
			.then(response => response.json())
			.then(data => {
				const countElement = document.getElementById('connected-users-count')
				if (countElement && data.connected_users !== undefined) {
					countElement.textContent = data.connected_users + ' ' + t('integration_itop', 'users')
				}
			})
			.catch(() => {
				// Silently fail
			})
	}

	/**
	 *
	 */
	function saveConfiguration() {

		const urlInput = document.getElementById('itop-instance-url')
		const nameInput = document.getElementById('itop-user-facing-name')
		const tokenInput = document.getElementById('itop-application-token')
		const saveButton = document.getElementById('save-itop-config')

		const url = urlInput.value.trim()
		const name = nameInput.value.trim()
		const token = tokenInput.value.trim()

		// Validation
		if (!url && !name && !token) {
			showNotification(t('integration_itop', 'Please enter at least a URL, display name, or application token'), true)
			return
		}

		if (url) {
			try {
				// Validate URL
				const urlObj = new URL(url)
				// Ensure we have a valid protocol
				if (!urlObj.protocol) {
					throw new Error('Invalid URL')
				}
			} catch (e) {
				showNotification(t('integration_itop', 'Please enter a valid URL'), true)
				return
			}
		}

		if (name.length > 100) {
			showNotification(t('integration_itop', 'Display name is too long (max 100 characters)'), true)
			return
		}

		// Show saving state
		saveButton.disabled = true
		const originalText = saveButton.innerHTML
		saveButton.innerHTML = '<span class="btn-icon">⏳</span> ' + t('integration_itop', 'Saving...')

		const requestData = { values: {} }
		if (url) requestData.values.admin_instance_url = url
		if (name) requestData.values.user_facing_name = name
		if (token && token !== '••••••••••••••••') {
			requestData.values.application_token = token
		}

		fetch(generateUrl('/apps/integration_itop/admin-config'), {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify(requestData),
		})
			.then(response => {
				if (!response.ok) throw new Error('Server error: ' + response.status)
				return response.json()
			})
			.then(data => {
				// Update displayed values
				const currentUrlSpan = document.getElementById('current-url')
				const currentNameSpan = document.getElementById('current-name')

				if (data.admin_instance_url !== undefined && currentUrlSpan) {
					currentUrlSpan.textContent = data.admin_instance_url || t('integration_itop', 'Not configured')
				}

				if (data.user_facing_name !== undefined && currentNameSpan) {
					currentNameSpan.textContent = data.user_facing_name
				}

				// Update token placeholder
				if (data.has_application_token !== undefined && tokenInput) {
					tokenInput.value = ''
					tokenInput.placeholder = data.has_application_token
						? '••••••••••••••••  ' + t('integration_itop', '(Configuration is saved - enter new token to update)')
						: t('integration_itop', 'Paste your personal token here')
					const testTokenButton = document.getElementById('test-application-token')
					if (testTokenButton) testTokenButton.disabled = true
				}

				showNotification(t('integration_itop', 'Configuration saved successfully'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Error saving configuration'), true)
			})
			.finally(() => {
				saveButton.disabled = false
				saveButton.innerHTML = originalText
			})
	}

	/**
	 * Test connection to iTop server
	 *
	 * @param {boolean} silent - Whether to suppress notifications
	 */
	function testConnection(silent = false) {

		const testButton = document.getElementById('test-connection')
		const statusElement = document.getElementById('connection-status')
		const statusCard = document.querySelector('.connection-status')
		const urlInput = document.getElementById('itop-instance-url')

		if (!statusElement || !urlInput) return

		const currentUrl = urlInput.value.trim()
		if (!currentUrl) {
			if (!silent) showNotification(t('integration_itop', 'Please enter a server URL'), true)
			return
		}

		// Get translated strings from data attributes
		const textTesting = statusElement.dataset.textTesting || 'Testing...'
		const textConnected = statusElement.dataset.textConnected || 'Connected'
		const textError = statusElement.dataset.textError || 'Error'
		const textFailed = statusElement.dataset.textFailed || 'Connection failed'
		const btnTextTest = testButton ? (testButton.dataset.textTest || 'Test Connection') : 'Test Connection'
		const btnTextTesting = testButton ? (testButton.dataset.textTesting || 'Testing...') : 'Testing...'

		// Update UI
		if (testButton) {
			testButton.disabled = true
			testButton.innerHTML = '<span class="btn-icon">⏳</span> ' + btnTextTesting
		}
		statusElement.textContent = textTesting
		if (statusCard) statusCard.className = 'status-card connection-status testing'

		fetch(generateUrl('/apps/integration_itop/admin-config/test'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ url: currentUrl }),
		})
			.then(response => response.json())
			.then(data => {
				if (data.status === 'success') {
					statusElement.textContent = '✅ ' + textConnected
					if (statusCard) statusCard.className = 'status-card connection-status success'
					if (!silent) showNotification(textConnected, false)
				} else {
					statusElement.textContent = '❌ ' + (data.message || textFailed)
					if (statusCard) statusCard.className = 'status-card connection-status error'
					if (!silent) showNotification(data.message || textFailed, true)
				}
			})
			.catch(() => {
				statusElement.textContent = '❌ ' + textError
				if (statusCard) statusCard.className = 'status-card connection-status error'
				if (!silent) showNotification(textFailed, true)
			})
			.finally(() => {
				if (testButton) {
					testButton.disabled = false
					testButton.innerHTML = '<span class="btn-icon">🔍</span> ' + btnTextTest
				}
			})
	}

	/**
	 * Test application token validity
	 */
	function testApplicationToken() {

		const tokenInput = document.getElementById('itop-application-token')
		const testButton = document.getElementById('test-application-token')

		if (!tokenInput || !testButton) return

		const token = tokenInput.value.trim()
		if (!token) {
			showNotification(t('integration_itop', 'Please enter a token'), true)
			return
		}

		testButton.disabled = true
		const originalText = testButton.innerHTML
		testButton.innerHTML = '<span class="btn-icon">⏳</span> ' + t('integration_itop', 'Testing...')

		fetch(generateUrl('/apps/integration_itop/admin-config/test-token'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ token }),
		})
			.then(response => response.json())
			.then(data => {
				if (data.status === 'success') {
					showNotification(t('integration_itop', 'Token is valid'), false)
				} else {
					showNotification(data.message || t('integration_itop', 'Token test failed'), true)
				}
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Token test failed'), true)
			})
			.finally(() => {
				testButton.disabled = false
				testButton.innerHTML = originalText
			})
	}

	/**
	 * Save notification settings (legacy)
	 */
	function saveNotificationSettings() {

		const saveButton = document.getElementById('save-notification-settings')
		const portalInterval = parseInt(document.getElementById('portal-notification-interval').value)

		saveButton.disabled = true
		const originalText = saveButton.innerHTML
		saveButton.innerHTML = '<span class="btn-icon">⏳</span> ' + t('integration_itop', 'Saving...')

		fetch(generateUrl('/apps/integration_itop/notification-settings'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ portalInterval }),
		})
			.then(response => {
				if (!response.ok) throw new Error('Server error')
				return response.json()
			})
			.then(data => {
				showNotification(t('integration_itop', 'Notification settings saved'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Error saving notification settings'), true)
			})
			.finally(() => {
				saveButton.disabled = false
				saveButton.innerHTML = originalText
			})
	}

	/**
	 * Save notification configuration (3-state)
	 */
	function saveNotificationConfig() {

		const saveButton = document.getElementById('save-notification-config')
		const defaultInterval = parseInt(document.getElementById('default-notification-interval').value)

		// Collect portal notification states
		const portalConfig = {}
		document.querySelectorAll('.state-toggle-group[data-notification-type="portal"]').forEach(group => {
			const notificationType = group.dataset.notification
			const activeButton = group.querySelector('.state-button.active')
			if (activeButton) {
				portalConfig[notificationType] = activeButton.dataset.state
			}
		})

		// Collect agent notification states
		const agentConfig = {}
		document.querySelectorAll('.state-toggle-group[data-notification-type="agent"]').forEach(group => {
			const notificationType = group.dataset.notification
			const activeButton = group.querySelector('.state-button.active')
			if (activeButton) {
				agentConfig[notificationType] = activeButton.dataset.state
			}
		})

		saveButton.disabled = true
		const originalText = saveButton.innerHTML
		saveButton.innerHTML = '<span class="btn-icon">⏳</span> ' + t('integration_itop', 'Saving...')

		fetch(generateUrl('/apps/integration_itop/notification-config'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({
				defaultInterval,
				portalConfig: JSON.stringify(portalConfig),
				agentConfig: JSON.stringify(agentConfig),
			}),
		})
			.then(response => {
				if (!response.ok) throw new Error('Server error')
				return response.json()
			})
			.then(data => {
				showNotification(t('integration_itop', 'Notification configuration saved'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Error saving notification configuration'), true)
			})
			.finally(() => {
				saveButton.disabled = false
				saveButton.innerHTML = originalText
			})
	}

	/**
	 * Save cache settings
	 */
	function saveCacheSettings() {

		const saveButton = document.getElementById('save-cache-settings')
		const values = {
			cache_ttl_ci_preview: document.getElementById('cache-ttl-ci-preview').value,
			cache_ttl_ticket_info: document.getElementById('cache-ttl-ticket-info').value,
			cache_ttl_search: document.getElementById('cache-ttl-search').value,
			cache_ttl_picker: document.getElementById('cache-ttl-picker').value,
			cache_ttl_profile: document.getElementById('cache-ttl-profile').value,
		}

		saveButton.disabled = true
		const originalText = saveButton.innerHTML
		saveButton.innerHTML = '<span class="btn-icon">⏳</span> ' + t('integration_itop', 'Saving...')

		fetch(generateUrl('/apps/integration_itop/admin-config'), {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ values }),
		})
			.then(response => {
				if (!response.ok) throw new Error('Server error')
				return response.json()
			})
			.then(data => {
				showNotification(t('integration_itop', 'Cache settings saved'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Error saving cache settings'), true)
			})
			.finally(() => {
				saveButton.disabled = false
				saveButton.innerHTML = originalText
			})
	}

	/**
	 * Clear all cached data
	 */
	function clearAllCache() {

		if (!confirm(t('integration_itop', 'Are you sure you want to clear all cached data?'))) {
			return
		}

		const clearButton = document.getElementById('clear-all-cache')
		clearButton.disabled = true
		const originalText = clearButton.innerHTML
		clearButton.innerHTML = '<span class="btn-icon">⏳</span> ' + t('integration_itop', 'Clearing...')

		fetch(generateUrl('/apps/integration_itop/clear-cache'), {
			method: 'DELETE',
			headers: {
				requesttoken: getRequestToken(),
			},
		})
			.then(response => response.json())
			.then(data => {
				showNotification(t('integration_itop', 'Cache cleared successfully'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Error clearing cache'), true)
			})
			.finally(() => {
				clearButton.disabled = false
				clearButton.innerHTML = originalText
			})
	}

	/**
	 * Save CI class configuration
	 */
	function saveCIClasses() {

		const saveButton = document.getElementById('save-ci-classes')
		const config = {}

		// Collect current state from CI class toggle groups only
		document.querySelectorAll('.state-toggle-group[data-class]').forEach(group => {
			const className = group.dataset.class
			const activeButton = group.querySelector('.state-button.active')
			if (activeButton) {
				config[className] = activeButton.dataset.state
			}
		})

		saveButton.disabled = true
		const originalText = saveButton.innerHTML
		saveButton.innerHTML = '<span class="btn-icon">⏳</span> ' + t('integration_itop', 'Saving...')

		fetch(generateUrl('/apps/integration_itop/ci-class-config'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({ classConfig: config }),
		})
			.then(response => {
				if (!response.ok) throw new Error('Server error')
				return response.json()
			})
			.then(data => {
				showNotification(t('integration_itop', 'CI class configuration saved'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Error saving CI class configuration'), true)
			})
			.finally(() => {
				saveButton.disabled = false
				saveButton.innerHTML = originalText
			})
	}

	/**
	 * Toggle all CI classes to next state
	 */
	function toggleAllCIClasses() {

		// Check current state - if any are disabled, enable all to 'forced'
		// If all are forced, toggle to 'user_choice'
		// If all are user_choice, toggle to 'disabled'
		// Only select CI class toggle groups (those with data-class attribute)
		const groups = document.querySelectorAll('.state-toggle-group[data-class]')
		const states = []
		groups.forEach(group => {
			const activeButton = group.querySelector('.state-button.active')
			if (activeButton) {
				states.push(activeButton.dataset.state)
			}
		})

		// Determine target state
		let targetState = 'forced'
		if (states.every(s => s === 'forced')) {
			targetState = 'user_choice'
		} else if (states.every(s => s === 'user_choice')) {
			targetState = 'disabled'
		}

		// Update all CI class groups only
		groups.forEach(group => {
			group.querySelectorAll('.state-button').forEach(btn => {
				btn.classList.remove('active')
				if (btn.dataset.state === targetState) {
					btn.classList.add('active')
				}
			})
		})
	}

	/**
	 * Toggle all notifications (Portal + Agent) to next state
	 */
	function toggleAllNotifications() {

		// Get all notification toggle groups (both portal and agent)
		const groups = document.querySelectorAll('.state-toggle-group[data-notification-type]')
		const states = []
		groups.forEach(group => {
			const activeButton = group.querySelector('.state-button.active')
			if (activeButton) {
				states.push(activeButton.dataset.state)
			}
		})

		// Determine target state using same cycle as CI classes
		// disabled -> forced -> user_choice -> disabled
		let targetState = 'forced'
		if (states.every(s => s === 'forced')) {
			targetState = 'user_choice'
		} else if (states.every(s => s === 'user_choice')) {
			targetState = 'disabled'
		}

		// Update all notification groups
		groups.forEach(group => {
			group.querySelectorAll('.state-button').forEach(btn => {
				btn.classList.remove('active')
				if (btn.dataset.state === targetState) {
					btn.classList.add('active')
				}
			})
		})
	}

	/**
	 * Check for app version updates
	 *
	 * @param {string} currentVersion - Current version of the app
	 */
	function checkAppVersion(currentVersion) {

		fetch(generateUrl('/apps/integration_itop/version-check'), {
			method: 'GET',
			headers: {
				requesttoken: getRequestToken(),
			},
		})
			.then(response => response.json())
			.then(data => {
				const resultSpan = document.getElementById('version-check-result')
				const statusCard = document.getElementById('version-status-card')
				const statusIcon = document.getElementById('version-status-icon')

				if (data.has_update) {
					if (resultSpan) {
						resultSpan.innerHTML = ' → <span style="color: #38a169;">v' + data.latest_version + ' ' + t('integration_itop', 'available') + '</span>'
					}
					if (statusCard) statusCard.classList.add('update-available')
					if (statusIcon) statusIcon.textContent = '🎁'
				} else {
					if (resultSpan) {
						resultSpan.innerHTML = ' <span style="color: #38a169;">✓</span>'
					}
				}
			})
			.catch(() => {
				// Silently fail
			})
	}

	/**
	 * Save ticket system type configuration
	 */
	function saveTicketSystemType() {

		const saveButton = document.getElementById('save-ticket-system-type')
		const selectedRadio = document.querySelector('input[name="ticket-system-type"]:checked')

		if (!selectedRadio) {
			showNotification(t('integration_itop', 'Please select a ticket system type'), true)
			return
		}

		const ticketSystemType = selectedRadio.value
		const simpleTypeField = (document.getElementById('simple-ticket-type-field')?.value || '').trim()
		const simpleIncidentValue = (document.getElementById('simple-ticket-incident-value')?.value || '').trim() || 'incident'
		const simpleRequestValue = (document.getElementById('simple-ticket-request-value')?.value || '').trim() || 'service_request'

		saveButton.disabled = true
		const originalText = saveButton.innerHTML
		saveButton.innerHTML = '<span class="btn-icon">⏳</span> ' + t('integration_itop', 'Saving...')

		fetch(generateUrl('/apps/integration_itop/ticket-system-type'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
			},
			body: JSON.stringify({
				ticketSystemType,
				simpleTypeField,
				simpleIncidentValue,
				simpleRequestValue,
			}),
		})
			.then(response => {
				if (!response.ok) throw new Error('Server error: ' + response.status)
				return response.json()
			})
			.then(() => {
				showNotification(t('integration_itop', 'Ticket system type saved'), false)
			})
			.catch(() => {
				showNotification(t('integration_itop', 'Error saving ticket system type'), true)
			})
			.finally(() => {
				saveButton.disabled = false
				saveButton.innerHTML = originalText
			})
	}

	/**
	 * Show notification to user
	 *
	 * @param {string} message - Message to display
	 * @param {boolean} isError - Whether this is an error message
	 */
	function showNotification(message, isError) {
		if (isError) {
			showError(message)
		} else {
			showSuccess(message)
		}
	}

	/**
	 * Update the empty-state hint for the custom CI section
	 */
	function updateCustomCIEmptyState() {
		const grid = document.getElementById('custom-ci-class-config-grid')
		const section = document.getElementById('custom-ci-classes-section')
		const hint = document.getElementById('custom-ci-empty-hint')
		const hasRows = grid && grid.querySelectorAll('.ci-class-config-row').length > 0
		if (section) section.style.display = hasRows ? '' : 'none'
		if (hint) hint.style.display = hasRows ? 'none' : ''
	}

	/**
	 * Build URL for cached custom class icon served by this app.
	 *
	 * @param {string} className
	 * @return {string}
	 */
	function getCustomClassIconUrl(className) {
		return generateUrl('/apps/integration_itop/ci-class-icon/' + encodeURIComponent(className))
	}

	/**
	 * Let the admin pick an SVG file and upload it as the icon for a custom CI class.
	 *
	 * @param {string} className
	 */
	function uploadCustomClassIcon(className) {
		if (!className) return
		const input = document.createElement('input')
		input.type = 'file'
		input.accept = '.svg,.png,image/svg+xml,image/png'
		input.addEventListener('change', function() {
			const file = input.files && input.files[0]
			if (!file) return
			if (file.size > 262144) {
				showNotification(t('integration_itop', 'Icon file too large (max 256 KB)'), true)
				return
			}
			const isPng = file.type === 'image/png' || /\.png$/i.test(file.name)
			file.arrayBuffer()
				.then(content => fetch(getCustomClassIconUrl(className), {
					method: 'POST',
					headers: {
						requesttoken: getRequestToken(),
						'Content-Type': isPng ? 'image/png' : 'image/svg+xml',
					},
					body: content,
				}))
				.then(response => response.json().then(data => ({ ok: response.ok, data })))
				.then(({ ok, data }) => {
					if (!ok) {
						showNotification(data.error || t('integration_itop', 'Failed to save icon'), true)
						return
					}
					showNotification(t('integration_itop', 'Icon saved successfully'), false)
					// Refresh the row's icon, bypassing the browser cache
					const img = document.querySelector('#custom-ci-row-' + className + ' .ci-class-icon img')
					if (img) {
						img.src = getCustomClassIconUrl(className) + '?v=' + Date.now()
					}
				})
				.catch(() => {
					showNotification(t('integration_itop', 'Failed to save icon'), true)
				})
		})
		input.click()
	}

	/**
	 * Browse available iTop CI classes (FunctionalCI subclasses not in built-in list)
	 */
	function browseItopClasses() {
		const btn = document.getElementById('browse-itop-classes')
		if (btn) {
			btn.disabled = true
			btn.querySelector('.btn-icon').textContent = '⏳'
		}

		const browser = document.getElementById('itop-class-browser')
		if (browser) browser.style.display = 'none'

		fetch(generateUrl('/apps/integration_itop/ci-class-available'), {
			method: 'GET',
			headers: { requesttoken: getRequestToken() },
		})
			.then(r => r.json())
			.then(data => {
				if (btn) {
					btn.disabled = false
					btn.querySelector('.btn-icon').textContent = '🔍'
				}
				if (data.error) {
					showNotification(data.error, true)
					return
				}

				const list = document.getElementById('itop-class-browser-list')
				const empty = document.getElementById('itop-class-browser-empty')
				const classIcons = data.class_icons || {}
				const currentCustom = Array.from(
					document.querySelectorAll('#custom-ci-class-config-grid .ci-class-config-row'),
				).map(row => row.id.replace('custom-ci-row-', ''))

				const available = (data.available_classes || []).filter(
					cls => !currentCustom.includes(cls),
				)

				if (list) list.innerHTML = ''

				if (available.length === 0) {
					if (empty) empty.style.display = ''
				} else {
					if (empty) empty.style.display = 'none'
					available.forEach(cls => {
						const iconUrl = classIcons[cls] || getCustomClassIconUrl(cls)
						const row = document.createElement('div')
						row.className = 'ci-class-config-row'
						row.style.cursor = 'pointer'
						row.innerHTML
							= '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1;">'
							+ '<span class="ci-class-icon"><img src="' + iconUrl + '" width="25" height="25" style="display:block;" /></span>'
							+ '<input type="checkbox" id="browser-check-' + cls + '" value="' + cls + '" style="width:18px;height:18px;">'
							+ '<span class="ci-class-label" style="font-size:1em;">' + cls + '</span>'
							+ '</label>'
						if (list) list.appendChild(row)
					})
				}

				if (browser) browser.style.display = ''
			})
			.catch(() => {
				if (btn) {
					btn.disabled = false
					btn.querySelector('.btn-icon').textContent = '🔍'
				}
				showNotification(t('integration_itop', 'Connection failed'), true)
			})
	}

	/**
	 * Save the custom CI classes list and their 3-state configuration
	 */
	function saveCustomCIClasses() {
		const saveBtn = document.getElementById('save-custom-ci-classes')
		if (saveBtn) saveBtn.disabled = true

		// 1. Collect already-configured custom class rows
		const existingRows = Array.from(
			document.querySelectorAll('#custom-ci-class-config-grid .ci-class-config-row'),
		).map(row => row.id.replace('custom-ci-row-', ''))

		// 2. Collect newly checked classes from browser
		const browserChecks = document.querySelectorAll('#itop-class-browser-list input[type="checkbox"]:checked')
		const newClasses = Array.from(browserChecks).map(cb => cb.value)

		// 3. Merge (deduplicate)
		const allCustom = [...new Set([...existingRows, ...newClasses])]

		// 4. POST the custom class list
		fetch(generateUrl('/apps/integration_itop/ci-class-custom'), {
			method: 'POST',
			headers: {
				requesttoken: getRequestToken(),
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({ customClasses: allCustom }),
		})
			.then(r => r.json())
			.then(data => {
				if (data.error) {
					showNotification(data.error, true)
					if (saveBtn) saveBtn.disabled = false
					return
				}

				// 5. Add rows for newly added classes to the configured grid
				const grid = document.getElementById('custom-ci-class-config-grid')
				newClasses.forEach(cls => {
					if (document.getElementById('custom-ci-row-' + cls)) return // already exists
					const genericIcon = getCustomClassIconUrl(cls)
					const row = document.createElement('div')
					row.className = 'ci-class-config-row'
					row.id = 'custom-ci-row-' + cls
					row.innerHTML
						= '<div class="ci-class-info">'
						+ '<span class="ci-class-icon"><img src="' + genericIcon + '" width="25" height="25" style="display:block;" /></span>'
						+ '<span class="ci-class-label">' + cls + '</span>'
						+ '<span class="custom-class-badge">' + t('integration_itop', 'custom') + '</span>'
						+ '<button type="button" class="remove-custom-class custom-ci-remove-btn custom-ci-remove-badge-btn" data-class="' + cls + '" title="' + t('integration_itop', 'Remove') + '">✕ ' + t('integration_itop', 'Remove') + '</button>'
						+ '</div>'
						+ '<div class="state-toggle-group" data-class="' + cls + '">'
						+ '<button type="button" class="state-button active" data-state="disabled"><span class="state-icon">🚫</span><span class="state-text">' + t('integration_itop', 'Disable') + '</span></button>'
						+ '<button type="button" class="state-button" data-state="forced"><span class="state-icon">✓</span><span class="state-text">' + t('integration_itop', 'Force Enable') + '</span></button>'
						+ '<button type="button" class="state-button" data-state="user_choice"><span class="state-icon">⚙️</span><span class="state-text">' + t('integration_itop', 'User Choice') + '</span></button>'
						+ '</div>'
					if (grid) grid.appendChild(row)
				})

				// Ensure remove controls are visible and normalized
				ensureCustomClassRemoveButtons()

				// Remove the newly added classes from the browser panel above
				newClasses.forEach(cls => {
					const cb = document.getElementById('browser-check-' + cls)
					const browserRow = cb ? cb.closest('.ci-class-config-row') : null
					if (browserRow) browserRow.remove()
				})

				// Clear remaining browser checkboxes and show empty state if nothing is left
				document.querySelectorAll('#itop-class-browser-list input[type="checkbox"]').forEach(cb => {
					cb.checked = false
				})
				const browserList = document.getElementById('itop-class-browser-list')
				const browserEmpty = document.getElementById('itop-class-browser-empty')
				if (browserList && browserEmpty && browserList.children.length === 0) {
					browserEmpty.style.display = ''
				}

				// Update empty state
				updateCustomCIEmptyState()

				// 6. Also save the 3-state config for ALL classes (standard + custom)
				const classConfig = {}
				document.querySelectorAll('.state-toggle-group[data-class]').forEach(function(group) {
					const cls = group.dataset.class
					const activeBtn = group.querySelector('.state-button.active')
					if (cls && activeBtn) {
						classConfig[cls] = activeBtn.dataset.state
					}
				})

				return fetch(generateUrl('/apps/integration_itop/ci-class-config'), {
					method: 'POST',
					headers: {
						requesttoken: getRequestToken(),
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ classConfig }),
				})
			})
			.then(r => {
				if (!r) return null
				return r.json()
			})
			.then(data => {
				if (saveBtn) saveBtn.disabled = false
				if (data && data.error) {
					showNotification(data.error, true)
				} else {
					showNotification(t('integration_itop', 'Custom CI classes saved successfully'), false)
				}
			})
			.catch(() => {
				if (saveBtn) saveBtn.disabled = false
				showNotification(t('integration_itop', 'Failed to save custom CI classes'), true)
			})
	}

	/**
	 * Ensure each configured custom CI row has a visible labeled remove button.
	 * Also upgrades older icon-only remove buttons.
	 */
	function ensureCustomClassRemoveButtons() {
		const grid = document.getElementById('custom-ci-class-config-grid')
		if (!grid) return

		const rowNodes = Array.from(grid.querySelectorAll('.ci-class-config-row'))
		rowNodes.forEach((row) => {
			const className = row.id.replace('custom-ci-row-', '')
			if (!className) return
			const infoContainer = row.querySelector('.ci-class-info')
			if (!infoContainer) return

			let removeBtn = row.querySelector('.remove-custom-class')
			if (!removeBtn) {

				removeBtn = document.createElement('button')
				removeBtn.type = 'button'
				removeBtn.className = 'remove-custom-class'
				removeBtn.dataset.class = className
				infoContainer.appendChild(removeBtn)
			} else if (removeBtn.parentElement !== infoContainer) {
				infoContainer.appendChild(removeBtn)
			}

			removeBtn.dataset.class = className
			removeBtn.classList.add('custom-ci-remove-btn', 'custom-ci-remove-badge-btn')
			removeBtn.title = t('integration_itop', 'Remove')
			removeBtn.textContent = '✕ ' + t('integration_itop', 'Remove')
			removeBtn.removeAttribute('style')

			// Make the class icon clickable for manual SVG upload/overwrite
			const iconEl = row.querySelector('.ci-class-icon')
			if (iconEl) {
				iconEl.classList.add('ci-class-icon-uploadable')
				iconEl.dataset.class = className
				iconEl.title = t('integration_itop', 'Upload icon (SVG or PNG)')
			}
		})
	}

	/**
	 * Remove a single custom CI class and persist immediately.
	 *
	 * @param {string} className
	 * @param {HTMLElement} buttonEl
	 */
	function removeCustomCIClass(className, buttonEl) {
		const row = document.getElementById('custom-ci-row-' + className)
		if (!row) return

		const remainingCustom = Array.from(
			document.querySelectorAll('#custom-ci-class-config-grid .ci-class-config-row'),
		).map(r => r.id.replace('custom-ci-row-', '')).filter(cls => cls !== className)

		const classConfig = {}
		document.querySelectorAll('.state-toggle-group[data-class]').forEach(function(group) {
			const cls = group.dataset.class
			if (!cls || cls === className) return
			const activeBtn = group.querySelector('.state-button.active')
			if (activeBtn) {
				classConfig[cls] = activeBtn.dataset.state
			}
		})

		buttonEl.disabled = true
		const originalHtml = buttonEl.innerHTML
		buttonEl.innerHTML = '⏳'

		fetch(generateUrl('/apps/integration_itop/ci-class-custom'), {
			method: 'POST',
			headers: {
				requesttoken: getRequestToken(),
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({ customClasses: remainingCustom }),
		})
			.then(r => r.json())
			.then(data => {
				if (data.error) {
					throw new Error(data.error)
				}

				return fetch(generateUrl('/apps/integration_itop/ci-class-config'), {
					method: 'POST',
					headers: {
						requesttoken: getRequestToken(),
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ classConfig }),
				})
			})
			.then(r => r.json())
			.then(data => {
				if (data.error) {
					throw new Error(data.error)
				}

				row.remove()

				// Also uncheck the class in the browser list if visible
				const browserCheck = document.getElementById('browser-check-' + className)
				if (browserCheck) {
					browserCheck.checked = false
				}

				updateCustomCIEmptyState()
				showNotification(t('integration_itop', 'Custom CI class removed'), false)
			})
			.catch((err) => {
				showNotification(err.message || t('integration_itop', 'Failed to remove custom CI class'), true)
			})
			.finally(() => {
				buttonEl.disabled = false
				buttonEl.innerHTML = originalHtml
			})
	}

})()
