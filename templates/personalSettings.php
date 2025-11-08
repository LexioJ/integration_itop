<?php
/**
 * @var array $_ Template parameters
 */
style('integration_itop', 'personal-settings');
script('integration_itop', 'integration_itop-personal-settings');

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
	<div class="itop-personal-header">
		<div class="icon-container">
			<div class="app-icon"></div>
		</div>
		<div class="header-content">
			<h2><?php p($l->t('%s Integration', [$_['display_name']])); ?></h2>
			<p class="subtitle"><?php p($l->t('Configure your %s system integration settings', [$_['display_name']])); ?></p>
		</div>
		<div class="version-badge">v<?php p($_['version']); ?></div>
	</div>

	<?php if (!$_['has_application_token']): ?>
	<div class="itop-personal-warning">
		<span class="icon icon-error"></span>
		<div>
			<strong><?php p($l->t('Administrator configuration required')); ?></strong>
			<p><?php p($l->t('The administrator must configure the application token before users can connect. Please contact your administrator.')); ?></p>
		</div>
	</div>
	<?php endif; ?>

	<!-- Status Dashboard -->
	<div class="itop-personal-status">
		<h3><?php p($l->t('Current Status')); ?></h3>
		<div class="itop-personal-status-grid">
			<div class="itop-personal-status-card itop-connection-status <?php echo $_['person_id_configured'] ? 'success' : ''; ?>" id="itop-personal-connection-status">
				<div class="itop-status-header">
					<span class="itop-status-icon">🔌</span>
					<span class="itop-status-title"><?php p($l->t('Connection')); ?></span>
				</div>
				<div class="itop-status-value" id="itop-personal-connection-value">
					<?php echo $_['person_id_configured'] ? $l->t('Configured') : $l->t('Not configured'); ?>
				</div>
			</div>

			<div class="itop-personal-status-card <?php echo $_['person_id_configured'] ? 'connected' : ''; ?>" id="itop-personal-user-info">
				<div class="itop-status-header">
					<span class="itop-status-icon">👤</span>
					<span class="itop-status-title"><?php p($l->t('Connected as')); ?></span>
				</div>
				<div class="itop-status-value" id="itop-personal-user-value">
					<?php echo $_['person_id_configured'] ? $l->t('Loading...') : '-'; ?>
				</div>
			</div>

			<div class="itop-personal-status-card <?php echo $_['person_id_configured'] ? 'connected' : ''; ?>" id="itop-personal-tickets-info">
				<div class="itop-status-header">
					<span class="itop-status-icon">🎫</span>
					<span class="itop-status-title"><?php p($l->t('Open Tickets')); ?></span>
				</div>
				<div class="itop-status-value" id="itop-personal-tickets-value">
					<?php echo $_['person_id_configured'] ? $l->t('Loading...') : '-'; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="itop-personal-settings">
		<h3><?php p($l->t('My Settings')); ?></h3>

		<div class="field token-field">
			<label for="itop-personal-token" class="token-label">
				<span class="token-icon">🔑</span>
				<?php p($l->t('Personal Token (used once for verification)')); ?>
			</label>
			<input id="itop-personal-token"
				type="text"
				value=""
				placeholder="<?php echo $_['person_id_configured'] ? $l->t('(Configuration is saved - enter new token to update)') : $l->t('Paste your personal token here'); ?>"
				class="password-style token-input"
				autocomplete="off"
				<?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>
			/>
			<p class="hint">
				<strong><?php p($l->t('Security:')); ?></strong>
				<?php p($l->t('Your personal token is used ONLY to verify your identity. It will NOT be stored. ')); ?>
				<?php if (!empty($_['admin_url'])): ?>
				<a href="<?php p($_['admin_url']); ?>/pages/exec.php/user/user-profile?sDisplayMode=_self&sTab=personal-tokens&exec_module=itop-portal-base&exec_page=index.php&portal_id=itop-portal" target="_blank" rel="noopener noreferrer">
					<?php p($l->t('Get your token')); ?> ↗
				</a>
				<?php endif; ?>
			</p>
		</div>

		<!-- Search Settings -->
		<div class="field search-section">
			<h4>🔎 <?php p($l->t('Search Settings')); ?></h4>
			<p class="hint"><?php p($l->t('Search your %s tickets from the search bar (tickets you created or are assigned to you)',[$_['display_name']])); ?></p>
			<div class="notification-user-list">
				<div class="notification-user-toggle">
					<input id="itop-search-enabled" type="checkbox" <?php echo $_['search_enabled'] ? 'checked' : ''; ?> <?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
					<label for="itop-search-enabled" class="notification-user-label-container">
						<span class="notification-user-label"><?php p($l->t('Enable unified search')); ?></span>
					</label>
				</div>
			</div>
		</div>

		<!-- Notification Settings -->
		<div class="field notification-section">
			<h4>🔔 <?php p($l->t('Notification Settings')); ?></h4>
			<p class="hint"><?php p($l->t('Receive notifications about ticket updates and changes')); ?></p>
			
			<!-- Master toggle for all notifications -->
			<div class="notification-user-list">
				<div class="notification-user-toggle">
					<input id="itop-notification-enabled" type="checkbox" <?php echo $_['notification_enabled'] ? 'checked' : ''; ?> <?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
					<label for="itop-notification-enabled" class="notification-user-label-container">
						<span class="notification-user-label"><strong><?php p($l->t('Enable iTop Notifications')); ?></strong></span>
					</label>
				</div>
			</div>
			
			<div id="notification-settings-content" style="<?php echo !$_['notification_enabled'] ? 'display: none;' : ''; ?>">
				<!-- Notification check interval -->
				<div class="notification-interval-field" style="margin-left: 24px; margin-top: 12px;">
					<label for="notification-check-interval"><?php p($l->t('Check for new notifications every')); ?></label>
					<input id="notification-check-interval" type="number" min="5" max="1440" 
						value="<?php p($_['notification_check_interval'] ?? 60); ?>" 
						style="width: 80px; margin: 0 8px;"
						<?php echo !$_['has_application_token'] ? 'disabled' : ''; ?> />
					<span><?php p($l->t('minutes')); ?></span>
					<p class="hint" style="margin-bottom: 10px;"><?php p($l->t('Default: %d minutes (set by administrator)', [$_['admin_default_interval'] ?? 60])); ?></p>
				</div>
				
				<!-- Forced notifications info -->
				<?php if (!empty($_['forced_portal_notifications']) || !empty($_['forced_agent_notifications'])): ?>
				<div class="notification-forced-info" style="margin: 16px 0 16px 24px; padding: 12px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;">
					<strong style="color: #0c4a6e;">ℹ️ <?php p($l->t('Mandatory Notifications')); ?></strong>
					<p style="margin: 8px 0 0 0; color: #0c4a6e; font-size: 0.9em;">
						<?php p($l->t('Your administrator has enabled the following mandatory notifications that cannot be disabled:')); ?>
					</p>
					<ul style="margin: 8px 0 0 20px; color: #0c4a6e; font-size: 0.9em;">
						<?php foreach ($_['forced_portal_notifications'] as $type): ?>
							<li><?php p($l->t('notification_' . $type)); ?></li>
						<?php endforeach; ?>
						<?php foreach ($_['forced_agent_notifications'] as $type): ?>
							<li><?php p($l->t('notification_' . $type)); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
				
				<!-- Portal notification types (user_choice only) -->
				<?php if (!empty($_['user_choice_portal_notifications'])): ?>
				<div class="notification-types" style="margin-left: 24px;">
					<h5><?php p($l->t('My Ticket Notifications')); ?></h5>
					<?php 
					$disabledPortal = $_['disabled_portal_notifications'];
					$allPortalDisabled = $disabledPortal === 'all';
					// Icon mapping for portal notifications
					$portalNotificationIcons = [
						'ticket_status_changed' => 'user-request-deadline.svg',
						'agent_responded' => 'discussion-forum.svg',
						'ticket_resolved' => 'checkmark.svg',
						'agent_assigned' => 'customer.svg'
					];
					?>
					<div class="notification-user-list">
						<?php foreach ($_['user_choice_portal_notifications'] as $type): ?>
						<?php 
						$isDisabled = $allPortalDisabled || (is_array($disabledPortal) && in_array($type, $disabledPortal));
						$isChecked = !$isDisabled;
						$iconFile = isset($portalNotificationIcons[$type]) ? $portalNotificationIcons[$type] : 'notification.svg';
						$iconPath = \OC::$server->getURLGenerator()->imagePath('integration_itop', $iconFile);
						?>
						<div class="notification-user-toggle">
							<input id="notify-portal-<?php p($type); ?>" 
								type="checkbox" 
								data-notification-type="portal"
								data-notification="<?php p($type); ?>"
								<?php echo $isChecked ? 'checked' : ''; ?>
								<?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
							<label for="notify-portal-<?php p($type); ?>" class="notification-user-label-container">
								<span class="notification-user-icon">
									<img src="<?php p($iconPath); ?>" alt="notification" width="24" height="24" />
								</span>
								<span class="notification-user-label"><?php p($l->t('notification_' . $type)); ?></span>
							</label>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>
				
				<!-- Agent notification types (user_choice only) -->
				<?php if (!empty($_['user_choice_agent_notifications'])): ?>
				<div class="notification-types" style="margin-left: 24px; margin-top: 16px;">
					<h5><?php p($l->t('Agent Notifications')); ?></h5>
					<?php 
					$disabledAgent = $_['disabled_agent_notifications'];
					$allAgentDisabled = $disabledAgent === 'all';
					
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
					
					// Desired display order for agent notifications
					$agentNotificationOrder = [
						'ticket_assigned',
						'team_unassigned_new',
						'ticket_reassigned',
						'ticket_comment',
						'ticket_ttr_warning',
						'ticket_tto_warning',
						'ticket_sla_breach',
						'ticket_priority_critical'
					];
					
					// Filter and sort agent notifications by desired order
					$orderedAgentNotifications = [];
					foreach ($agentNotificationOrder as $type) {
						if (in_array($type, $_['user_choice_agent_notifications'])) {
							$orderedAgentNotifications[] = $type;
						}
					}
					// Add any remaining types not in the order list
					foreach ($_['user_choice_agent_notifications'] as $type) {
						if (!in_array($type, $orderedAgentNotifications)) {
							$orderedAgentNotifications[] = $type;
						}
					}
					?>
					<div class="notification-user-list">
						<?php foreach ($orderedAgentNotifications as $type): ?>
						<?php 
						$isDisabled = $allAgentDisabled || (is_array($disabledAgent) && in_array($type, $disabledAgent));
						$isChecked = !$isDisabled;
						$iconFile = isset($agentNotificationIcons[$type]) ? $agentNotificationIcons[$type] : 'notification.svg';
						$iconPath = \OC::$server->getURLGenerator()->imagePath('integration_itop', $iconFile);
						?>
						<div class="notification-user-toggle">
							<input id="notify-agent-<?php p($type); ?>" 
								type="checkbox" 
								data-notification-type="agent"
								data-notification="<?php p($type); ?>"
								<?php echo $isChecked ? 'checked' : ''; ?>
								<?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
							<label for="notify-agent-<?php p($type); ?>" class="notification-user-label-container">
								<span class="notification-user-icon">
									<img src="<?php p($iconPath); ?>" alt="notification" width="24" height="24" />
								</span>
								<span class="notification-user-label"><?php p($l->t('notification_' . $type)); ?></span>
							</label>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>


		<!-- CI Class Preferences Section -->
		<?php if (!empty($_['forced_ci_classes']) || !empty($_['user_choice_ci_classes'])): ?>
		<div class="field ci-class-preferences">
			<h4>🎯 <?php p($l->t('Configuration Item Classes')); ?></h4>
			<p class="hint"><?php p($l->t('Choose which CI types you want to see in search, smart picker, and previews')); ?></p>
			
			<!-- Forced CI classes info -->
			<?php if (!empty($_['forced_ci_classes'])): ?>
			<div class="notification-forced-info" style="margin: 16px 0; padding: 12px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;">
				<strong style="color: #0c4a6e;">ℹ️ <?php p($l->t('Mandatory Configuration Items')); ?></strong>
				<p style="margin: 8px 0 0 0; color: #0c4a6e; font-size: 0.9em;">
					<?php p($l->t('Your administrator has enabled the following mandatory CI classes that cannot be disabled:')); ?>
				</p>
				<ul style="margin: 8px 0 0 20px; color: #0c4a6e; font-size: 0.9em;">
					<?php foreach ($_['forced_ci_classes'] as $className): ?>
						<li><?php p(isset($ciClassLabels[$className]) ? $ciClassLabels[$className] : $className); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			
			<?php if (!empty($_['user_choice_ci_classes'])): ?>
			<div id="ci-class-user-toggles" class="ci-class-user-list">
				<?php foreach ($_['user_choice_ci_classes'] as $className): ?>
				<div class="ci-class-user-toggle">
					<input
						type="checkbox"
						name="user_ci_class"
						id="ci-class-<?php p($className); ?>"
						value="<?php p($className); ?>"
						<?php echo !in_array($className, $_['user_disabled_ci_classes']) ? 'checked' : ''; ?>
						<?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>
					/>
					<label for="ci-class-<?php p($className); ?>" class="ci-class-user-label-container">
						<span class="ci-class-user-icon">
							<img src="<?php p(\OC::$server->getURLGenerator()->imagePath('integration_itop', $className . '.svg')); ?>" alt="<?php p($className); ?>" width="24" height="24" />
						</span>
						<span class="ci-class-user-label"><?php p(isset($ciClassLabels[$className]) ? $ciClassLabels[$className] : $className); ?></span>
					</label>
			</div>
			<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<button id="itop-save" class="button" <?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
			<span class="icon icon-checkmark"></span>
			<?php p($l->t('Save')); ?>
		</button>

		<div id="itop-result" class="result hidden">
			<span class="icon"></span>
			<span class="message"></span>
		</div>
	</div>

	<div class="itop-personal-info">
		<h3><?php p($l->t('How to get your Personal Token')); ?></h3>
		<ol>
			<li><?php p($l->t('Log into %s', [$_['display_name']])); ?></li>
			<li><?php p($l->t('Navigate to "My Account" → "Personal Tokens"')); ?></li>
			<li><?php p($l->t('Click "Create New Token"')); ?></li>
			<li><?php p($l->t('Configure your token:')); ?>
				<ul>
					<li><strong><?php p($l->t('Application name:')); ?></strong> <?php p($l->t('Nextcloud Integration')); ?></li>
					<li><strong><?php p($l->t('Scope:')); ?></strong> <?php p($l->t('REST/JSON (Required!)')); ?></li>
					<li><strong><?php p($l->t('Expiration:')); ?></strong> <?php p($l->t('Choose based on your policy')); ?></li>
				</ul>
			</li>
			<li><?php p($l->t('Copy the generated token immediately (it won\'t be shown again)')); ?></li>
			<li><?php p($l->t('Paste the token in the field above and click "Save"')); ?></li>
			<li><strong><?php p($l->t('Important:')); ?></strong> <?php p($l->t('The token is used ONCE to verify your Identity, then discarded for security.')); ?></li>
		</ol>

		<h3><?php p($l->t('Available features')); ?></h3>
		<ul>
			<li><?php p($l->t('Dashboard widget with your assigned tickets')); ?></li>
			<li><?php p($l->t('Search tickets and CIs from Nextcloud search')); ?></li>
			<li><?php p($l->t('Rich previews when sharing %s links', [$_['display_name']])); ?></li>
			<li><?php p($l->t('Notifications for newly assigned tickets')); ?></li>
		</ul>
	</div>
</div>
