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

// Request-access links, pre-built in lib/Settings/Personal.php. Rendered in two
// places -- inline where self-service is off, and inside a <template> that
// src/personal-settings.js clones after a discovery that cannot link -- so the
// markup lives in this one closure rather than being written twice.
$requestLinks = $_['request_links'] ?? [];
$renderRequestLinks = function () use ($requestLinks, $l) {
	if (empty($requestLinks)) {
		return;
	}
	?>
	<div class="itop-request-access">
		<p class="hint"><?php p($l->t('You can ask for this here:')); ?></p>
		<ul class="itop-request-access-links">
			<?php foreach ($requestLinks as $requestLink): ?>
			<li>
				<a href="<?php p($requestLink['url']); ?>" target="_blank" rel="noopener noreferrer">
					<?php p($requestLink['label']); ?> ↗
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
};

// Whether the two blocks of the .itop-personal-info footer have anything to
// say. Computed once and used by the blocks and by their wrapper: the wrapper
// is styled, so rendering it around two hidden blocks painted an empty grey
// box at the foot of the page, and a third copy of these conditions is a third
// thing that has to be kept in agreement.
$showTokenHelp = $_['binding_token_enabled'] ?? false;
$showFeatures = ($_['person_id_configured'] ?? false) || $showTokenHelp || ($_['binding_discovery_enabled'] ?? false);
?>

<div id="itop_prefs" class="section">
	<?php if (!empty($requestLinks)): ?>
	<!-- Page-level, not nested in a field: src/personal-settings.js clones this
	     onto the discovery outcomes that end at "ask an administrator", and it
	     has to be reachable independently of which self-service toggles are on.
	     Inert markup -- a <template> is never rendered as-is. -->
	<template id="itop-request-access-template"><?php $renderRequestLinks(); ?></template>
	<?php endif; ?>
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

		<?php if ($_['binding_token_enabled']): ?>
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
			<p class="hint itop-portal-hint">
				<strong><?php p($l->t('Portal user?')); ?></strong>
				<?php if ($_['binding_discovery_enabled']): ?>
					<?php p($l->t('Personal tokens are only issued to back-end users. If you cannot get one, try "Discover me in %s" below instead.', [$_['display_name']])); ?>
				<?php else: ?>
					<?php p($l->t('Personal tokens are only issued to back-end users. If you cannot get one, please ask an administrator to link your account. An administrator can also enable self-service discovery, or install the "iTop Portal Personal Tokens" extension to issue tokens to portal users.')); ?>
				<?php endif; ?>
			</p>
			<?php /* Token binding on, discovery off, and no token obtainable: a
			         portal user is told to ask an administrator with no way to do
			         it. The likeliest configuration to reach that message, so the
			         links belong here too -- not only in the both-toggles-off
			         case below. Suppressed once the account is linked: there is
			         then nothing left to request. */ ?>
			<?php if (!$_['binding_discovery_enabled'] && !$_['person_id_configured']): ?>
				<?php $renderRequestLinks(); ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ($_['binding_discovery_enabled']): ?>
		<div class="field discovery-field">
			<label class="discovery-label">
				<span class="token-icon">🔍</span>
				<?php p($l->t('Automatic linking')); ?>
			</label>
			<p class="hint"><?php p($l->t('Let %s try to match your account to your person record automatically. No token needed.', [$_['display_name']])); ?></p>
			<button id="itop-discover-button" type="button" class="button">
				<?php p($l->t('Discover me in %s', [$_['display_name']])); ?>
			</button>
			<div id="itop-discover-result" class="itop-discover-result hidden"></div>
		</div>
		<?php endif; ?>

		<?php /* person_id_configured is part of the condition, not just the two
		         toggles: an already-linked user needs neither the notice (their
		         account is linked, so asking an administrator to link it is
		         nonsense) nor the links (they have nothing to request). The
		         notice predates this change and was shown to linked users too;
		         the links must not inherit that. */ ?>
		<?php if (!$_['person_id_configured'] && !$_['binding_token_enabled'] && !$_['binding_discovery_enabled']): ?>
		<div class="field">
			<p class="hint"><?php p($l->t('Self-service account linking is disabled. Please ask an administrator to link your account to %s.', [$_['display_name']])); ?></p>
			<?php $renderRequestLinks(); ?>
		</div>
		<?php endif; ?>

		<?php /* Search, notifications and CI classes all act on the linked iTop
		         person, so they are meaningless -- and their saved values
		         unreachable -- until the account is linked. Hidden rather than
		         disabled: nothing here would do anything.
		         src/personal-settings.js must therefore treat every control in
		         these three sections as optional, including #itop-notification-enabled. */ ?>
		<?php if ($_['person_id_configured']): ?>

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

				<!-- Newsroom mirroring toggle (opt-in, independent of ticket notifications) -->
				<div class="notification-user-toggle" style="margin-top: 8px;">
					<input id="itop-newsroom-mirroring-enabled" type="checkbox"
						name="newsroom_mirroring_enabled"
						<?php echo $_['newsroom_mirroring_enabled'] ? 'checked' : ''; ?>
						<?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
					<label for="itop-newsroom-mirroring-enabled" class="notification-user-label-container">
						<span class="notification-user-label"><?php p($l->t('Mirror iTop Newsroom notifications')); ?></span>
					</label>
				</div>
				<p class="hint" style="margin-left: 28px; margin-top: 2px; margin-bottom: 8px;">
					<?php p($l->t('Receive iTop Newsroom announcements as Nextcloud notifications (requires ActionNewsroom triggers configured in iTop)')); ?>
				</p>
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
						$iconPath = \OCP\Server::get(\OCP\IURLGenerator::class)->imagePath('integration_itop', $iconFile);
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
						$iconPath = \OCP\Server::get(\OCP\IURLGenerator::class)->imagePath('integration_itop', $iconFile);
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
				<?php
				// Built-in classes: serve directly from img/. Custom classes: route through
				// getCIClassIcon controller which checks appdata and falls back to Peripheral.svg.
				$isStandardClass = in_array($className, \OCA\Itop\AppInfo\Application::SUPPORTED_CI_CLASSES, true);
				if ($isStandardClass) {
					$ciClassIconPath = \OCP\Server::get(\OCP\IURLGenerator::class)->imagePath('integration_itop', $className . '.svg');
				} else {
					$ciClassIconPath = \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('integration_itop.config.getCIClassIcon', ['class' => $className]);
				}
				?>
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
							<img src="<?php p($ciClassIconPath); ?>" alt="<?php p($className); ?>" width="24" height="24" />
						</span>
						<span class="ci-class-user-label"><?php p(isset($ciClassLabels[$className]) ? $ciClassLabels[$className] : $className); ?></span>
					</label>
			</div>
			<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php endif; /* person_id_configured */ ?>

		<?php /* Unlinked with token binding off leaves no persistable field on
		         the page at all: Save would post {} and the server would still
		         answer "Settings saved successfully", confirming a save that
		         saved nothing. Kept when token binding is on but unlinked -- the
		         token field is there and is submitted through this same button.
		         #itop-result stays unconditional; src/personal-settings.js gates
		         the save path on both, and no longer returns early without them. */ ?>
		<?php if ($_['person_id_configured'] || $_['binding_token_enabled']): ?>
		<button id="itop-save" class="button" <?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
			<span class="icon icon-checkmark"></span>
			<?php p($l->t('Save')); ?>
		</button>
		<?php endif; ?>

		<div id="itop-result" class="result hidden">
			<span class="icon"></span>
			<span class="message"></span>
		</div>
		<?php if ($_['binding_token_enabled'] && !empty($_['admin_url'])): ?>
		<div class="itop-personal-config-hint">
			<span class="icon icon-info"></span>
			<div>
				<strong><?php p($l->t('Can\'t create a personal token?')); ?></strong>
				<p><?php p($l->t('If the "Personal Tokens" option is missing from your %s account, your iTop profile may not be allowed to create tokens. Ask your iTop administrator to add your profile to personal_tokens_allowed_profiles in the iTop Configuration File Editor.', [$_['display_name']])); ?></p>
			</div>
		</div>
		<?php endif; ?>

	</div>

	<?php if ($showTokenHelp || $showFeatures): ?>
	<div class="itop-personal-info">
		<?php if ($showTokenHelp): ?>
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
		<?php endif; ?>

		<?php /* Same condition as the "ask an administrator" notice above. A user
		         who is not linked and has no self-service route cannot reach any
		         of these, so listing them only advertises what they cannot have.
		         Kept wherever a self-service route exists -- there the list is
		         the reason to bother linking. */ ?>
		<?php if ($showFeatures): ?>
		<h3><?php p($l->t('Available features')); ?></h3>
		<ul>
			<li><?php p($l->t('Dashboard widget with your assigned tickets')); ?></li>
			<li><?php p($l->t('Search tickets and CIs from Nextcloud search')); ?></li>
			<li><?php p($l->t('Rich previews when sharing %s links', [$_['display_name']])); ?></li>
			<li><?php p($l->t('Notifications for newly assigned tickets')); ?></li>
		</ul>
		<?php endif; ?>
	</div>
	<?php endif; /* $showTokenHelp || $showFeatures */ ?>
</div>
