/**
 * Nextcloud - iTop Integration
 *
 * Admin settings JavaScript
 * Handles events and dynamic updates for server-rendered HTML
 */

(function() {
	'use strict'

	// Translation function (will return English since OC.L10N._bundles is empty, but keeping for consistency)
	const t = window.t || function(app, text) { return text }

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

		// Notification toggle all button
		const toggleAllNotificationsButton = document.getElementById('toggle-all-notifications')

		if (toggleAllNotificationsButton) {
			toggleAllNotificationsButton.addEventListener('click', function(e) {
				e.preventDefault()
				toggleAllNotifications()
			})
		}

		// CI class state toggle buttons
		const stateButtons = document.querySelectorAll('.state-button')
		stateButtons.forEach(function(button) {
			button.addEventListener('click', function(e) {
				e.preventDefault()

				const toggleGroup = button.closest('.state-toggle-group')
				if (!toggleGroup) return

				// Remove active class from all buttons in this group
				toggleGroup.querySelectorAll('.state-button').forEach(function(btn) {
					btn.classList.remove('active')
				})

				// Add active class to clicked button
				button.classList.add('active')
			})
		})
	}

	/**
	 *
	 */
	function loadConnectedUsersCount() {
		fetch(OC.generateUrl('/apps/integration_itop/admin-config'), {
			method: 'GET',
			headers: {
				requesttoken: OC.requestToken,
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

		fetch(OC.generateUrl('/apps/integration_itop/admin-config'), {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
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

		fetch(OC.generateUrl('/apps/integration_itop/admin-config/test'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
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

		fetch(OC.generateUrl('/apps/integration_itop/admin-config/test-token'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
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

		fetch(OC.generateUrl('/apps/integration_itop/notification-settings'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
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

		fetch(OC.generateUrl('/apps/integration_itop/notification-config'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify({
				defaultInterval,
				portalConfig: JSON.stringify(portalConfig),
				agentConfig: JSON.stringify(agentConfig)
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

		fetch(OC.generateUrl('/apps/integration_itop/admin-config'), {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
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

		fetch(OC.generateUrl('/apps/integration_itop/clear-cache'), {
			method: 'DELETE',
			headers: {
				requesttoken: OC.requestToken,
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

		fetch(OC.generateUrl('/apps/integration_itop/ci-class-config'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
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

		fetch(OC.generateUrl('/apps/integration_itop/version-check'), {
			method: 'GET',
			headers: {
				requesttoken: OC.requestToken,
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
	 * Show notification to user
	 *
	 * @param {string} message - Message to display
	 * @param {boolean} isError - Whether this is an error message
	 */
	function showNotification(message, isError) {
		if (OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(message + (isError ? ' ❌' : ' ✅'))
		}
	}

})()
