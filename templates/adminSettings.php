<?php
/**
 * @var array $_ Template parameters
 */
$appId = OCA\Itop\AppInfo\Application::APP_ID;
script($appId, $appId . '-admin-settings');
style($appId, 'admin-settings');

// CI class label mapping
$ciClassLabels = [
	'PC' => $l->t('Computers (PC)'),
	'Phone' => $l->t('Phones'),
	'IPPhone' => $l->t('IP Phones'),
	'MobilePhone' => $l->t('Mobile Phones'),
	'Tablet' => $l->t('Tablets'),
	'Printer' => $l->t('Printers'),
	'Peripheral' => $l->t('Peripherals'),
	'PCSoftware' => $l->t('PC Software'),
	'OtherSoftware' => $l->t('Other Software'),
	'WebApplication' => $l->t('Web Applications'),
	'Software' => $l->t('Software Catalog')
];
?>

<div id="itop_prefs" class="section">
	<div class="itop-settings-wrapper">
		<div class="itop-admin-header">
			<div class="icon-container">
				<div class="app-icon"></div>
			</div>
			<div class="header-content">
				<h2><?php p($l->t('%s integration', [$_['user_facing_name']])); ?></h2>
				<p class="subtitle"><?php p($l->t('Configure your iTop system integration settings')); ?></p>
			</div>
			<div class="version-badge">v<?php p($_['version']); ?></div>
		</div>

		<!-- Current Status Section -->
		<div class="settings-section">
			<div class="section-header">
				<h3><?php p($l->t('📊 Current Status')); ?></h3>
			</div>

			<div class="status-grid">
				<div class="status-card">
					<div class="status-header">
						<span class="status-icon">👥</span>
						<span class="status-title"><?php p($l->t('Connected Users')); ?></span>
					</div>
					<div class="status-value" id="connected-users-count"><?php p($_['connected_users']); ?> <?php p($l->t('users')); ?></div>
				</div>

				<div class="status-card connection-status">
					<div class="status-header">
						<span class="status-icon">🔌</span>
						<span class="status-title"><?php p($l->t('Connection')); ?></span>
					</div>
					<div class="status-value" id="connection-status"
						data-text-testing="<?php p($l->t('Testing...')); ?>"
						data-text-connected="<?php p($l->t('Connected')); ?>"
						data-text-error="<?php p($l->t('Error')); ?>"
						data-text-not-configured="<?php p($l->t('No URL configured')); ?>"
						data-text-not-tested="<?php p($l->t('Not tested')); ?>"
						data-text-failed="<?php p($l->t('Connection failed')); ?>">
						<?php echo !empty($_['admin_instance_url']) ? $l->t('Not tested') : $l->t('No URL configured'); ?>
					</div>
				</div>

				<div class="status-card version-status" id="version-status-card">
					<div class="status-header">
						<span class="status-icon" id="version-status-icon">📦</span>
						<span class="status-title"><?php p($l->t('App Version')); ?></span>
					</div>
					<div class="status-value" id="version-status">
						<span id="version-current">v<?php p($_['version']); ?></span>
						<span id="version-check-result"></span>
					</div>
				</div>

				<div class="status-card">
					<div class="status-header">
						<span class="status-icon">🏷️</span>
						<span class="status-title"><?php p($l->t('Display Name')); ?></span>
					</div>
					<div class="status-value" id="current-name"><?php p($_['user_facing_name']); ?></div>
				</div>

				<div class="status-card">
					<div class="status-header">
						<span class="status-icon">🌐</span>
						<span class="status-title"><?php p($l->t('Server URL')); ?></span>
					</div>
					<div class="status-value" id="current-url"><?php echo !empty($_['admin_instance_url']) ? p($_['admin_instance_url']) : p($l->t('Not configured')); ?></div>
				</div>

				<div class="status-card">
					<div class="status-header">
						<span class="status-icon">⏰</span>
						<span class="status-title"><?php p($l->t('Last Updated')); ?></span>
					</div>
					<div class="status-value"><?php p($_['last_updated']); ?></div>
				</div>
			</div>
		</div>

		<!-- Connection Configuration Section -->
		<div class="settings-section">
			<div class="section-header">
				<h3><?php p($l->t('📡 Connection Configuration')); ?></h3>
				<p class="section-description"><?php p($l->t('Configure the connection to your iTop system instance')); ?></p>
			</div>

			<div class="settings-form">
				<div class="form-group">
					<label for="itop-user-facing-name" class="form-label">
						<span class="icon">🏷️</span>
						<?php p($l->t('Display Name')); ?>
					</label>
					<input
						type="text"
						id="itop-user-facing-name"
						value="<?php p($_['user_facing_name']); ?>"
						placeholder="<?php p($l->t('e.g., ServicePoint, Helpdesk, iTop')); ?>"
						class="form-input"
						maxlength="100"
					/>
					<p class="form-hint"><?php p($l->t('The name users will see throughout Nextcloud (e.g., "ServicePoint Integration")')); ?></p>
				</div>

				<div class="form-group">
					<label for="itop-instance-url" class="form-label">
						<span class="icon">🌐</span>
						<?php p($l->t('Server URL')); ?>
					</label>
					<div class="form-input-group">
						<input
							type="url"
							id="itop-instance-url"
							value="<?php p($_['admin_instance_url']); ?>"
							placeholder="<?php p($l->t('https://your-itop-server.com')); ?>"
							class="form-input"
						/>
						<button id="test-connection" class="btn-secondary btn-inline" <?php echo empty($_['admin_instance_url']) ? 'disabled' : ''; ?>
							data-text-test="<?php p($l->t('Test Connection')); ?>"
							data-text-testing="<?php p($l->t('Testing...')); ?>">
							<span class="btn-icon">🔍</span>
							<?php p($l->t('Test Connection')); ?>
							</button>
						</div>
						<p class="form-hint"><?php p($l->t('The complete URL to your iTop system instance')); ?></p>
						<div class="form-info-box info">
							<strong><?php p($l->t('ℹ️ Note:')); ?></strong> <?php p($l->t('If using a private/local IP address (e.g., 192.168.x.x, 10.x.x.x), you must enable')); ?> <code>allow_local_remote_servers</code> <?php p($l->t('in Nextcloud config')); ?> (<a href="https://docs.nextcloud.com/server/32/admin_manual/configuration_server/config_sample_php_parameters.html#proxy-configurations" target="_blank"><?php p($l->t('See documentation →')); ?></a> <?php p($l->t('Nextcloud 30+')); ?>).<br>
							<?php p($l->t('Run:')); ?> <code>occ config:system:set allow_local_remote_servers --value=true --type=boolean</code>
						</div>
					</div>

				<div class="form-group">
					<label for="itop-application-token" class="form-label">
						<span class="icon">🔑</span>
						<?php p($l->t('Application Token (Administrator)')); ?>
					</label>
					<div class="form-input-group">
						<input
							type="text"
							id="itop-application-token"
							value=""
							placeholder="<?php echo $_['has_application_token'] ? '••••••••••••••••  ' . $l->t('(Configuration is saved - enter new token to update)') : $l->t('Paste your personal token here'); ?>"
							class="form-input password-style"
							autocomplete="off"
						/>
						<button id="test-application-token" class="btn-secondary btn-inline" disabled>
							<span class="btn-icon">🔍</span>
							<?php p($l->t('Test Token')); ?>
						</button>
					</div>
					<p class="form-hint">
						<?php p($l->t('Required Administrator-level token for querying user data')); ?>.
						<a href="https://github.com/LexioJ/integration_itop#admin-configuration-phase-2" target="_blank"><?php p($l->t('How to create →')); ?></a>
					</p>
					<div class="form-info-box">
						<strong><?php p($l->t('⚠️ Important:')); ?></strong> <?php p($l->t('This token must have')); ?> <strong><?php p($l->t('Administrator')); ?></strong> + <strong><?php p($l->t('REST Services User')); ?></strong> <?php p($l->t('profiles')); ?>. <?php p($l->t('It will be used securely to query user data (filtered by Person ID for security)')); ?>.
					</div>
				</div>

				<div class="form-actions">
					<button id="save-itop-config" class="btn-primary">
						<span class="btn-icon">💾</span>
						<?php p($l->t('Save Configuration')); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- User Permission Requirements Section -->
		<div class="settings-section">
			<div class="section-header">
				<h3><?php p($l->t('⚙️ User Permission Requirements')); ?></h3>
				<p class="section-description"><?php p($l->t('Important: Configure these iTop user permissions to prevent connection issues')); ?></p>
			</div>
			<div class="info-cards">
				<div class="info-card permission-steps">
					<div class="info-icon">📋</div>
					<div class="info-content">
						<h4><?php p($l->t('How to Configure User Permissions')); ?></h4>
						<ol>
							<li><strong><?php p($l->t('Admin Setup')); ?>:</strong> <?php p($l->t('Log into %s', ['iTop'])); ?></li>
							<li><strong><?php p($l->t('User Profiles')); ?>:</strong> <?php p($l->t('Admin Tools → User Management → User Accounts')); ?></li>
							<li><strong><?php p($l->t('Edit User')); ?>:</strong> <?php p($l->t('Each user must create a Personal Token with specific settings')); ?></li>
							<li><strong><?php p($l->t('Add Profile')); ?>:</strong> <?php p($l->t('Profiles')); ?></li>
							<li><strong><?php p($l->t('Save User')); ?>:</strong> <?php p($l->t('Save User')); ?></li>
							<li><strong><?php p($l->t('User Token')); ?>:</strong> <?php p($l->t('Each user must create a Personal Token with specific settings')); ?></li>
							<li><strong><?php p($l->t('⚠️ CRITICAL')); ?>:</strong> <?php p($l->t('"REST API"')); ?></li>
						</ol>
					</div>
				</div>

				<div class="info-card token-requirements">
					<div class="info-icon">🔑</div>
					<div class="info-content">
						<h4><?php p($l->t('Personal Token Requirements')); ?></h4>
						<p><strong><?php p($l->t('Each user must create a Personal Token with specific settings')); ?>:</strong></p>
						<ul>
							<li><strong><?php p($l->t('Location')); ?>:</strong> <?php p($l->t('Navigate to "My Account" → "Personal Tokens"')); ?></li>
							<li><strong><?php p($l->t('Application Name')); ?>:</strong> <?php p($l->t('Nextcloud Integration')); ?></li>
							<li><strong><?php p($l->t('⚠️ REQUIRED')); ?>:</strong> <?php p($l->t('REST/JSON (Required!)')); ?></li>
							<li><strong><?php p($l->t('Expiration')); ?>:</strong> <?php p($l->t('Choose based on your policy')); ?></li>
							<li><strong><?php p($l->t('Important')); ?>:</strong> <?php p($l->t('Copy the generated token immediately (it won\'t be shown again)')); ?></li>
						</ul>
					</div>
				</div>
			</div>
		</div>

		<!-- Notification Configuration Section -->
		<div class="settings-section">
			<div class="section-header">
				<h3>🔔 <?php p($l->t('Notification Configuration')); ?></h3>
				<p class="section-description"><?php p($l->t('Configure which notifications are available to users and set default check interval')); ?></p>
			</div>

			<div class="settings-form">
				<!-- Default Check Interval -->
				<div class="form-group">
					<label for="default-notification-interval" class="form-label">
						<span class="icon">⏱️</span>
						<?php p($l->t('Default Notification Check Interval (minutes)')); ?>
					</label>
					<input
						type="number"
						id="default-notification-interval"
						value="<?php p($_['default_notification_interval']); ?>"
						min="5"
						max="1440"
						class="form-input"
					/>
					<p class="form-hint"><?php p($l->t('Default interval for all users (5-1440 minutes). Users can customize their own interval in personal settings')); ?></p>
				</div>

				<!-- Portal Notifications Configuration -->
				<h4 style="margin-top: 20px; margin-bottom: 12px;"><?php p($l->t('Portal Notifications')); ?></h4>
				<p class="form-hint" style="margin-bottom: 16px;"><?php p($l->t('Configure which notifications portal users can receive (My Tickets)')); ?></p>
				
				<div class="notification-config-grid">
					<?php 
					$portalNotificationLabels = [
						'ticket_status_changed' => $l->t('Ticket status changed'),
						'agent_responded' => $l->t('Agent responded to ticket'),
						'ticket_resolved' => $l->t('Ticket resolved'),
						'agent_assigned' => $l->t('Agent assignment changed')
					];
					// Icon mapping for portal notifications
					$portalNotificationIcons = [
						'ticket_status_changed' => 'user-request-deadline.svg',
						'agent_responded' => 'discussion-forum.svg',
						'ticket_resolved' => 'checkmark.svg',
						'agent_assigned' => 'customer.svg'
					];
				foreach ($_['portal_notification_types'] as $notificationType): 
						$currentState = $_['portal_notification_config'][$notificationType] ?? 'user_choice';
						$label = $portalNotificationLabels[$notificationType] ?? $notificationType;
						$iconFile = isset($portalNotificationIcons[$notificationType]) ? $portalNotificationIcons[$notificationType] : 'notification.svg';
						$iconPath = \OC::$server->getURLGenerator()->imagePath($appId, $iconFile);
					?>
					<div class="notification-config-row">
						<div class="notification-info">
							<span class="notification-icon">
								<img src="<?php p($iconPath); ?>" alt="notification" width="25" height="25" style="display: block;" />
							</span>
							<span class="notification-label"><?php p($label); ?></span>
						</div>
						<div class="state-toggle-group" data-notification-type="portal" data-notification="<?php p($notificationType); ?>">
							<button type="button" class="state-button <?php echo $currentState === 'disabled' ? 'active' : ''; ?>" data-state="disabled">
								<span class="state-icon">🚫</span>
								<span class="state-text"><?php p($l->t('Disable')); ?></span>
							</button>
							<button type="button" class="state-button <?php echo $currentState === 'forced' ? 'active' : ''; ?>" data-state="forced">
								<span class="state-icon">✓</span>
								<span class="state-text"><?php p($l->t('Force Enable')); ?></span>
							</button>
							<button type="button" class="state-button <?php echo $currentState === 'user_choice' ? 'active' : ''; ?>" data-state="user_choice">
								<span class="state-icon">⚙️</span>
								<span class="state-text"><?php p($l->t('User Choice')); ?></span>
							</button>
						</div>
					</div>
					<?php endforeach; ?>
				</div>

				<!-- Agent Notifications Configuration -->
				<h4 style="margin-top: 24px; margin-bottom: 12px;"><?php p($l->t('Agent Notifications')); ?></h4>
				<p class="form-hint" style="margin-bottom: 16px;"><?php p($l->t('Configure which notifications IT agents can receive (Assignments, SLA, Priority)')); ?></p>
				
				<div class="notification-config-grid">
					<?php 
					$agentNotificationLabels = [
						'ticket_assigned' => $l->t('Ticket assigned to me'),
						'ticket_reassigned' => $l->t('Ticket reassigned to me'),
						'team_unassigned_new' => $l->t('New unassigned ticket in team'),
						'ticket_tto_warning' => $l->t('TTO SLA warning'),
						'ticket_ttr_warning' => $l->t('TTR SLA warning'),
						'ticket_sla_breach' => $l->t('SLA breach'),
						'ticket_priority_critical' => $l->t('Priority escalated to critical'),
						'ticket_comment' => $l->t('New comment on ticket')
					];
					// Icon mapping for agent notifications
					$agentNotificationIcons = [
						'ticket_assigned' => 'security-pass.svg',
						'team_unassigned_new' => 'team.svg',
						'ticket_reassigned' => 'change-normal.svg',
						'ticket_comment' => 'discussion-forum.svg',
						'ticket_ttr_warning' => 'user-request-deadline.svg',
						'ticket_tto_warning' => 'user-request-deadline.svg',
						'ticket_sla_breach' => 'incident-escalated.svg',
						'ticket_priority_critical' => 'notification.svg'
					];
					foreach ($_['agent_notification_types'] as $notificationType): 
						$currentState = $_['agent_notification_config'][$notificationType] ?? 'user_choice';
						$label = $agentNotificationLabels[$notificationType] ?? $notificationType;
						$iconFile = isset($agentNotificationIcons[$notificationType]) ? $agentNotificationIcons[$notificationType] : 'notification.svg';
						$iconPath = \OC::$server->getURLGenerator()->imagePath($appId, $iconFile);
					?>
					<div class="notification-config-row">
						<div class="notification-info">
							<span class="notification-icon">
								<img src="<?php p($iconPath); ?>" alt="notification" width="25" height="25" style="display: block;" />
							</span>
							<span class="notification-label"><?php p($label); ?></span>
						</div>
						<div class="state-toggle-group" data-notification-type="agent" data-notification="<?php p($notificationType); ?>">
							<button type="button" class="state-button <?php echo $currentState === 'disabled' ? 'active' : ''; ?>" data-state="disabled">
								<span class="state-icon">🚫</span>
								<span class="state-text"><?php p($l->t('Disable')); ?></span>
							</button>
							<button type="button" class="state-button <?php echo $currentState === 'forced' ? 'active' : ''; ?>" data-state="forced">
								<span class="state-icon">✓</span>
								<span class="state-text"><?php p($l->t('Force Enable')); ?></span>
							</button>
							<button type="button" class="state-button <?php echo $currentState === 'user_choice' ? 'active' : ''; ?>" data-state="user_choice">
								<span class="state-icon">⚙️</span>
								<span class="state-text"><?php p($l->t('User Choice')); ?></span>
							</button>
						</div>
					</div>
					<?php endforeach; ?>
				</div>

				<!-- Info Box -->
				<div class="form-info-box" style="margin-top: 16px;">
					<strong><?php p($l->t('🎯 Configuration States')); ?>:</strong><br>
					<strong style="color: #e53e3e;">🚫 <?php p($l->t('Disable')); ?></strong> - <?php p($l->t('Notification not available (hidden from users)')); ?><br>
					<strong style="color: #38a169;">✓ <?php p($l->t('Force Enable')); ?></strong> - <?php p($l->t('Mandatory for all users (cannot be disabled)')); ?><br>
					<strong style="color: #3182ce;">⚙️ <?php p($l->t('User Choice')); ?></strong> - <?php p($l->t('Enabled by default, users can opt-out')); ?>
				</div>

				<div class="form-actions">
					<button id="save-notification-config" class="btn-primary">
						<span class="btn-icon">💾</span>
						<?php p($l->t('Save Notification Configuration')); ?>
					</button>
					<button id="toggle-all-notifications" class="btn-secondary">
						<span class="btn-icon">🔄</span>
						<?php p($l->t('Toggle All')); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Cache & Performance Settings Section -->
		<div class="settings-section">
			<div class="section-header">
				<h3><?php p($l->t('⚡ Cache & Performance Settings')); ?></h3>
				<p class="section-description"><?php p($l->t('Configure cache TTL (Time To Live) settings to balance performance and data freshness')); ?></p>
			</div>

			<div class="settings-form">
				<div class="cache-settings-grid">
					<div class="form-group">
						<label for="cache-ttl-ci-preview" class="form-label">
							<span class="icon">📄</span>
							<?php p($l->t('CI Preview Cache TTL (seconds)')); ?>
						</label>
						<input
							type="number"
							id="cache-ttl-ci-preview"
							value="<?php p($_['cache_ttl_ci_preview']); ?>"
							min="10"
							max="3600"
							class="form-input"
						/>
						<p class="form-hint"><?php p($l->t('How long to cache Configuration Item preview data (10s–1h). Lower = fresher data, higher = better performance')); ?></p>
					</div>

					<div class="form-group">
						<label for="cache-ttl-ticket-info" class="form-label">
							<span class="icon">🎫</span>
							<?php p($l->t('Ticket Info Cache TTL (seconds)')); ?>
						</label>
						<input
							type="number"
							id="cache-ttl-ticket-info"
							value="<?php p($_['cache_ttl_ticket_info']); ?>"
							min="10"
							max="3600"
							class="form-input"
						/>
						<p class="form-hint"><?php p($l->t('How long to cache ticket preview data (10s–1h)')); ?></p>
					</div>

					<div class="form-group">
						<label for="cache-ttl-search" class="form-label">
							<span class="icon">🔍</span>
							<?php p($l->t('Search Results Cache TTL (seconds)')); ?>
						</label>
						<input
							type="number"
							id="cache-ttl-search"
							value="<?php p($_['cache_ttl_search']); ?>"
							min="10"
							max="300"
							class="form-input"
						/>
						<p class="form-hint"><?php p($l->t('How long to cache search results (10s–5min). Shorter TTLs ensure fresher results')); ?></p>
					</div>

					<div class="form-group">
						<label for="cache-ttl-picker" class="form-label">
							<span class="icon">🎯</span>
							<?php p($l->t('Picker Suggestions Cache TTL (seconds)')); ?>
						</label>
						<input
							type="number"
							id="cache-ttl-picker"
							value="<?php p($_['cache_ttl_picker']); ?>"
							min="10"
							max="300"
							class="form-input"
						/>
						<p class="form-hint"><?php p($l->t('How long to cache Smart Picker suggestions for CI links in Text/Talk (10s–5min)')); ?></p>
					</div>

					<div class="form-group">
						<label for="cache-ttl-profile" class="form-label">
							<span class="icon">👤</span>
							<?php p($l->t('Profile Cache TTL (seconds)')); ?>
						</label>
						<input
							type="number"
							id="cache-ttl-profile"
							value="<?php p($_['cache_ttl_profile']); ?>"
							min="10"
							max="3600"
							class="form-input"
						/>
						<p class="form-hint"><?php p($l->t('How long to cache user profile data for access control (10s–1h). Default: 30 minutes')); ?></p>
					</div>
				</div>

				<div class="form-actions cache-actions">
					<button id="save-cache-settings" class="btn-primary">
						<span class="btn-icon">💾</span>
						<?php p($l->t('Save Cache Settings')); ?>
					</button>
					<button id="clear-all-cache" class="btn-warning">
						<span class="btn-icon">🗑️</span>
						<?php p($l->t('Clear All Cache')); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- CI Class Configuration Section -->
		<div class="settings-section">
			<div class="section-header">
				<h3><?php p($l->t('🎯 CI Class Configuration')); ?></h3>
				<p class="section-description"><?php p($l->t('Configure access levels for Configuration Item types in search, smart picker, and previews')); ?></p>
			</div>

			<div class="settings-form">
				<div class="ci-class-config-grid">
					<?php foreach ($_['supported_ci_classes'] as $className): ?>
						<?php
							$currentState = $_['ci_class_config'][$className] ?? 'disabled';
							$classLabel = $ciClassLabels[$className] ?? $className;
							$iconPath = \OC::$server->getURLGenerator()->imagePath($appId, $className . '.svg');
						?>
						<div class="ci-class-config-row">
							<div class="ci-class-info">
								<span class="ci-class-icon" data-class="<?php p($className); ?>">
									<img src="<?php p($iconPath); ?>" alt="<?php p($className); ?>" width="25" height="25" style="display: block;" />
								</span>
								<span class="ci-class-label"><?php p($classLabel); ?></span>
							</div>
							<div class="state-toggle-group" data-class="<?php p($className); ?>">
								<button type="button" class="state-button <?php echo $currentState === 'disabled' ? 'active' : ''; ?>" data-state="disabled">
									<span class="state-icon">🚫</span>
									<span class="state-text"><?php p($l->t('Disable')); ?></span>
								</button>
								<button type="button" class="state-button <?php echo $currentState === 'forced' ? 'active' : ''; ?>" data-state="forced">
									<span class="state-icon">✓</span>
									<span class="state-text"><?php p($l->t('Force Enable')); ?></span>
								</button>
								<button type="button" class="state-button <?php echo $currentState === 'user_choice' ? 'active' : ''; ?>" data-state="user_choice">
									<span class="state-icon">⚙️</span>
									<span class="state-text"><?php p($l->t('User Choice')); ?></span>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="form-info-box" style="margin-top: 16px;">
					<strong><?php p($l->t('🎯 Configuration States')); ?>:</strong><br>
					<strong style="color: #e53e3e;">🚫 <?php p($l->t('Disable')); ?></strong> - <?php p($l->t('CI class not available (hidden from users)')); ?><br>
					<strong style="color: #38a169;">✓ <?php p($l->t('Force Enable')); ?></strong> - <?php p($l->t('Mandatory for all users (cannot be disabled)')); ?><br>
					<strong style="color: #3182ce;">⚙️ <?php p($l->t('User Choice')); ?></strong> - <?php p($l->t('Enabled by default, users can opt-out')); ?>
				</div>

				<div class="form-actions">
					<button id="save-ci-classes" class="btn-primary">
						<span class="btn-icon">💾</span>
						<?php p($l->t('Save CI Class Configuration')); ?>
					</button>
					<button id="toggle-all-ci-classes" class="btn-secondary">
						<span class="btn-icon">🔄</span>
						<?php p($l->t('Toggle All')); ?>
					</button>
				</div>
			</div>
		</div>

	<!-- Custom CI Classes Section -->
	<div class="settings-section">
		<div class="section-header">
			<h3><?php p($l->t('🔧 Custom CI Classes')); ?></h3>
			<p class="section-description"><?php p($l->t('Add iTop CI classes not in the built-in list (e.g. Monitor, Scanner, NetworkDevice) to search, smart picker, and previews')); ?></p>
		</div>

		<div class="settings-form">

			<!-- Browse available classes from iTop -->
			<div class="form-group">
				<p class="form-hint"><?php p($l->t('Click "Browse iTop Classes" to discover all FunctionalCI subclasses present in your iTop instance. Select the ones you want to make available to users.')); ?></p>
				<div class="form-actions" style="margin-top: 8px; margin-bottom: 0;">
					<button id="browse-itop-classes" class="btn-secondary"
						data-text-browse="<?php p($l->t('Browse iTop Classes')); ?>"
						data-text-loading="<?php p($l->t('Loading...')); ?>">
						<span class="btn-icon">🔍</span>
						<?php p($l->t('Browse iTop Classes')); ?>
					</button>
				</div>
			</div>

			<!-- Browse result panel (hidden until Browse is clicked) -->
			<div id="itop-class-browser" style="display:none; margin-top: 16px;">
				<div class="form-info-box info" style="margin-bottom: 12px;">
					<strong><?php p($l->t('ℹ️ Available Custom Classes')); ?></strong><br>
					<?php p($l->t('These are FunctionalCI subclasses found in your iTop CMDB that are not already in the built-in class list. Check the classes you want to add and click "Save Custom CI Classes".')); ?>
				</div>
				<div id="itop-class-browser-list" class="ci-class-config-grid">
					<!-- Populated dynamically by JavaScript -->
				</div>
				<div id="itop-class-browser-empty" style="display:none; color: var(--color-text-maxcontrast); padding: 12px 0;">
					<?php p($l->t('No additional CI classes found in iTop, or all available classes are already in the built-in list.')); ?>
				</div>
			</div>

			<!-- Currently configured custom classes -->
			<div id="custom-ci-classes-section" style="margin-top: 16px; <?php echo empty($_['custom_ci_classes']) ? 'display:none;' : ''; ?>">
				<h4 style="margin-bottom: 12px;"><?php p($l->t('Configured Custom CI Classes')); ?></h4>
				<div id="custom-ci-class-config-grid" class="ci-class-config-grid">
					<?php foreach ($_['custom_ci_classes'] as $customClass): ?>
						<?php
							$currentState = $_['ci_class_config'][$customClass] ?? 'disabled';
							$customIconPath = \OC::$server->getURLGenerator()->linkToRoute('integration_itop.config.getCIClassIcon', ['class' => $customClass]);
						?>
						<div class="ci-class-config-row" id="custom-ci-row-<?php p($customClass); ?>">
							<div class="ci-class-info">
								<span class="ci-class-icon">
									<img src="<?php p($customIconPath); ?>" alt="<?php p($customClass); ?>" width="25" height="25" style="display: block;" />
								</span>
								<span class="ci-class-label"><?php p($customClass); ?></span>
								<span class="custom-class-badge"><?php p($l->t('custom')); ?></span>
								<button type="button" class="remove-custom-class custom-ci-remove-btn custom-ci-remove-badge-btn" data-class="<?php p($customClass); ?>" title="<?php p($l->t('Remove')); ?>">✕ <?php p($l->t('Remove')); ?></button>
							</div>
							<div class="state-toggle-group" data-class="<?php p($customClass); ?>">
								<button type="button" class="state-button <?php echo $currentState === 'disabled' ? 'active' : ''; ?>" data-state="disabled">
									<span class="state-icon">🚫</span>
									<span class="state-text"><?php p($l->t('Disable')); ?></span>
								</button>
								<button type="button" class="state-button <?php echo $currentState === 'forced' ? 'active' : ''; ?>" data-state="forced">
									<span class="state-icon">✓</span>
									<span class="state-text"><?php p($l->t('Force Enable')); ?></span>
								</button>
								<button type="button" class="state-button <?php echo $currentState === 'user_choice' ? 'active' : ''; ?>" data-state="user_choice">
									<span class="state-icon">⚙️</span>
									<span class="state-text"><?php p($l->t('User Choice')); ?></span>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div id="custom-ci-empty-hint" style="<?php echo !empty($_['custom_ci_classes']) ? 'display:none;' : ''; ?> color: var(--color-text-maxcontrast); margin-top: 8px; font-style: italic;">
				<?php p($l->t('No custom CI classes configured yet. Use "Browse iTop Classes" above to add some.')); ?>
			</div>

			<div class="form-actions" style="margin-top: 16px;">
				<button id="save-custom-ci-classes" class="btn-primary">
					<span class="btn-icon">💾</span>
					<?php p($l->t('Save Custom CI Classes')); ?>
				</button>
			</div>
		</div>
	</div>
	</div>
</div>
