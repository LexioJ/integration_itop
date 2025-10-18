<?php
/**
 * @var array $_ Template parameters
 */
style('integration_itop', 'personal-settings');
script('integration_itop', 'personal-settings');
?>

<div id="itop_prefs" class="section">
	<div class="itop-personal-header">
		<div class="icon-container">
			<div class="app-icon"></div>
		</div>
		<div class="header-content">
			<h2><?php p($l->t('%s Integration', [$_['display_name']])); ?></h2>
			<p class="subtitle"><?php p($l->t('Configure your iTop system integration settings')); ?></p>
		</div>
		<div class="version-badge">V1.0.0</div>
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
		<h3>📊 Current Status</h3>
		<div class="itop-personal-status-grid">
			<div class="itop-personal-status-card itop-connection-status <?php echo $_['person_id_configured'] ? 'success' : ''; ?>" id="itop-personal-connection-status">
				<div class="itop-status-header">
					<span class="itop-status-icon">🔌</span>
					<span class="itop-status-title">Connection</span>
				</div>
				<div class="itop-status-value" id="itop-personal-connection-value">
					<?php echo $_['person_id_configured'] ? 'Configured' : 'Not configured'; ?>
				</div>
			</div>

			<div class="itop-personal-status-card <?php echo $_['person_id_configured'] ? 'connected' : ''; ?>" id="itop-personal-user-info">
				<div class="itop-status-header">
					<span class="itop-status-icon">👤</span>
					<span class="itop-status-title">Connected as</span>
				</div>
				<div class="itop-status-value" id="itop-personal-user-value">
					<?php echo $_['person_id_configured'] ? 'Loading...' : '-'; ?>
				</div>
			</div>

			<div class="itop-personal-status-card <?php echo $_['person_id_configured'] ? 'connected' : ''; ?>" id="itop-personal-tickets-info">
				<div class="itop-status-header">
					<span class="itop-status-icon">🎫</span>
					<span class="itop-status-title">Open Tickets</span>
				</div>
				<div class="itop-status-value" id="itop-personal-tickets-value">
					<?php echo $_['person_id_configured'] ? 'Loading...' : '-'; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="itop-personal-settings">
		<h3>⚙️ My Settings</h3>

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

		<div class="field">
			<input id="itop-notification-enabled" type="checkbox" <?php echo $_['notification_enabled'] ? 'checked' : ''; ?> <?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
			<label for="itop-notification-enabled"><?php p($l->t('Enable notifications')); ?></label>
			<p class="hint"><?php p($l->t('Get notified when new tickets are assigned to you')); ?></p>
		</div>

		<div class="field">
			<input id="itop-search-enabled" type="checkbox" <?php echo $_['search_enabled'] ? 'checked' : ''; ?> <?php echo !$_['has_application_token'] ? 'disabled' : ''; ?>>
			<label for="itop-search-enabled"><?php p($l->t('Enable unified search')); ?></label>
			<p class="hint"><?php p($l->t('Search your %s tickets from the search bar (tickets you created or are assigned to you)',[$_['display_name']])); ?></p>
		</div>

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
