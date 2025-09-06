<?php
/**
 * @var array $_ Template parameters
 */
style('integration_itop', 'personal-settings');
?>

<div id="itop_prefs" class="section">
	<h2>
		<span class="icon icon-itop"></span>
		<?php p($l->t('iTop integration')); ?>
	</h2>
	<p class="settings-hint">
		<?php p($l->t('Connect your iTop account to access tickets and configuration items')); ?>
	</p>
	
	<div class="itop-personal-settings">
		<?php if (!empty($_['admin_url'])): ?>
		<div class="field">
			<label><?php p($l->t('iTop instance URL (configured by admin)')); ?></label>
			<input type="text" 
				value="<?php p($_['admin_url']); ?>" 
				disabled
			/>
		</div>
		<?php else: ?>
		<div class="field">
			<label for="itop-instance-url">
				<span class="icon icon-link"></span>
				<?php p($l->t('iTop instance URL')); ?>
			</label>
			<input id="itop-instance-url"
				type="url"
				value="<?php p($_['url']); ?>"
				placeholder="https://your-itop.example.com"
			/>
		</div>
		<?php endif; ?>
		
		<div class="field">
			<label for="itop-api-token">
				<span class="icon icon-password"></span>
				<?php p($l->t('Personal API token')); ?>
			</label>
			<input id="itop-api-token"
				type="password"
				placeholder="<?php echo $_['token'] ? '••••••••••••••••' : $l->t('Enter your iTop API token'); ?>"
			/>
			<p class="hint">
				<?php p($l->t('Personal API token from your iTop account')); ?> 
				<a href="<?php echo $_['admin_url'] ?: $_['url']; ?>/pages/UI.php?c[menu]=MyShortcuts" target="_blank" rel="noopener noreferrer">
					<?php p($l->t('Get your token')); ?> ↗
				</a>
			</p>
		</div>
		
		<div class="field">
			<input id="itop-navigation-enabled" type="checkbox" <?php echo $_['navigation_enabled'] ? 'checked' : ''; ?>>
			<label for="itop-navigation-enabled"><?php p($l->t('Enable navigation link')); ?></label>
			<p class="hint"><?php p($l->t('Add iTop to the main navigation menu')); ?></p>
		</div>
		
		<div class="field">
			<input id="itop-notification-enabled" type="checkbox" <?php echo $_['notification_enabled'] ? 'checked' : ''; ?>>
			<label for="itop-notification-enabled"><?php p($l->t('Enable notifications')); ?></label>
			<p class="hint"><?php p($l->t('Get notified when new tickets are assigned to you')); ?></p>
		</div>
		
		<button id="itop-save" class="button">
			<span class="icon icon-checkmark"></span>
			<?php p($l->t('Save')); ?>
		</button>
		
		<div id="itop-result" class="result hidden">
			<span class="icon"></span>
			<span class="message"></span>
		</div>
	</div>
	
	<div class="itop-personal-info">
		<h3><?php p($l->t('How to get your API token')); ?></h3>
		<ol>
			<li><?php p($l->t('Log into your iTop instance')); ?></li>
			<li><?php p($l->t('Go to "My Account" menu')); ?></li>
			<li><?php p($l->t('Create a new Personal Token with "REST API" scope')); ?></li>
			<li><?php p($l->t('Copy and paste the token above')); ?></li>
		</ol>
		
		<h3><?php p($l->t('Available features')); ?></h3>
		<ul>
			<li><?php p($l->t('Dashboard widget with your assigned tickets')); ?></li>
			<li><?php p($l->t('Search tickets and CIs from Nextcloud search')); ?></li>
			<li><?php p($l->t('Rich previews when sharing iTop links')); ?></li>
			<li><?php p($l->t('Notifications for newly assigned tickets')); ?></li>
		</ul>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	const saveButton = document.getElementById('itop-save');
	const instanceUrlField = document.getElementById('itop-instance-url');
	const apiTokenField = document.getElementById('itop-api-token');
	const navigationEnabledField = document.getElementById('itop-navigation-enabled');
	const notificationEnabledField = document.getElementById('itop-notification-enabled');
	const resultDiv = document.getElementById('itop-result');
	
	function showResult(message, isError = false) {
		const icon = resultDiv.querySelector('.icon');
		const messageSpan = resultDiv.querySelector('.message');
		
		icon.className = 'icon ' + (isError ? 'icon-error' : 'icon-checkmark');
		messageSpan.textContent = message;
		resultDiv.classList.remove('hidden');
		
		setTimeout(() => {
			resultDiv.classList.add('hidden');
		}, 5000);
	}
	
	saveButton.addEventListener('click', function() {
		const instanceUrl = instanceUrlField ? instanceUrlField.value.trim() : '';
		const apiToken = apiTokenField.value.trim();
		const navigationEnabled = navigationEnabledField.checked ? '1' : '0';
		const notificationEnabled = notificationEnabledField.checked ? '1' : '0';
		
		if (instanceUrl && !instanceUrl.match(/^https?:\/\/.+/)) {
			showResult('<?php p($l->t('Please enter a valid URL')); ?>', true);
			return;
		}
		
		saveButton.disabled = true;
		
		const params = {
			navigation_enabled: navigationEnabled,
			notification_enabled: notificationEnabled
		};
		
		if (instanceUrl) {
			params.url = instanceUrl;
		}
		
		if (apiToken && apiToken !== '••••••••••••••••') {
			params.token = apiToken;
		}
		
		const req = new XMLHttpRequest();
		req.open('PUT', OC.generateUrl('/apps/integration_itop/config'));
		req.setRequestHeader('requesttoken', OC.requestToken);
		req.setRequestHeader('Content-Type', 'application/json');
		
		req.onreadystatechange = function() {
			if (req.readyState === 4) {
				saveButton.disabled = false;
				if (req.status === 200) {
					const response = JSON.parse(req.responseText);
					showResult(response.message || '<?php p($l->t('Settings saved')); ?>');
					
					// Update token placeholder if token was saved
					if (apiToken && apiToken !== '••••••••••••••••') {
						apiTokenField.placeholder = '••••••••••••••••';
						apiTokenField.value = '';
					}
				} else {
					const response = req.responseText ? JSON.parse(req.responseText) : {};
					showResult(response.message || '<?php p($l->t('Failed to save settings')); ?>', true);
				}
			}
		};
		
		req.send(JSON.stringify(params));
	});
	
	// Clear token field when focused to allow easy replacement
	if (apiTokenField.placeholder === '••••••••••••••••') {
		apiTokenField.addEventListener('focus', function() {
			if (this.value === '') {
				this.placeholder = '<?php p($l->t('Enter your iTop API token')); ?>';
			}
		});
	}
});
</script>
