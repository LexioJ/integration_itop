<?php
/**
 * @var array $_ Template parameters
 */
style('integration_itop', 'admin-settings');
?>

<div id="itop_prefs" class="section">
	<h2>
		<?php p($l->t('iTop integration')); ?>
	</h2>
	<p class="settings-hint">
		<?php p($l->t('Configure global iTop integration settings')); ?>
	</p>
	
	<div class="itop-admin-settings">
		<h3><?php p($l->t('iTop instance')); ?></h3>
		<div class="field">
			<label for="itop-admin-instance-url">
				<span class="icon icon-link"></span>
				<?php p($l->t('iTop instance URL')); ?>
			</label>
			<input id="itop-admin-instance-url"
				type="url"
				value="<?php p($_['admin_instance_url']); ?>"
				placeholder="https://your-itop.example.com"
			/>
			<p class="hint">
				<?php p($l->t('URL of your iTop instance (e.g., https://your-itop.example.com)')); ?>
			</p>
		</div>
		
		<button id="itop-admin-save" class="button">
			<span class="icon icon-checkmark"></span>
			<?php p($l->t('Save')); ?>
		</button>
		
		<div id="itop-admin-result" class="result hidden">
			<span class="icon"></span>
			<span class="message"></span>
		</div>
	</div>
	
	<div class="itop-admin-info">
		<h3><?php p($l->t('How to configure')); ?></h3>
		<ol>
			<li><?php p($l->t('Install and enable the "Authentication by Token" extension in your iTop instance')); ?></li>
			<li><?php p($l->t('Configure the iTop instance URL above')); ?></li>
			<li><?php p($l->t('Users can then configure their personal API tokens in their personal settings')); ?></li>
		</ol>
		
		<h3><?php p($l->t('Features')); ?></h3>
		<ul>
			<li><?php p($l->t('Dashboard widget showing assigned tickets')); ?></li>
			<li><?php p($l->t('Unified search for tickets and configuration items')); ?></li>
			<li><?php p($l->t('Rich previews for iTop links in chat and documents')); ?></li>
			<li><?php p($l->t('Notifications for newly assigned tickets')); ?></li>
		</ul>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	const saveButton = document.getElementById('itop-admin-save');
	const instanceUrlField = document.getElementById('itop-admin-instance-url');
	const resultDiv = document.getElementById('itop-admin-result');
	
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
		const adminInstanceUrl = instanceUrlField.value.trim();
		
		if (adminInstanceUrl && !adminInstanceUrl.match(/^https?:\/\/.+/)) {
			showResult('<?php p($l->t('Please enter a valid URL')); ?>', true);
			return;
		}
		
		saveButton.disabled = true;
		
		const params = {
			admin_instance_url: adminInstanceUrl
		};
		
		const req = new XMLHttpRequest();
		req.open('PUT', OC.generateUrl('/apps/integration_itop/admin-config'));
		req.setRequestHeader('requesttoken', OC.requestToken);
		req.setRequestHeader('Content-Type', 'application/json');
		
		req.onreadystatechange = function() {
			if (req.readyState === 4) {
				saveButton.disabled = false;
				if (req.status === 200) {
					const response = JSON.parse(req.responseText);
					showResult(response.message || '<?php p($l->t('Settings saved')); ?>');
				} else {
					const response = req.responseText ? JSON.parse(req.responseText) : {};
					showResult(response.message || '<?php p($l->t('Failed to save settings')); ?>', true);
				}
			}
		};
		
		req.send(JSON.stringify(params));
	});
});
</script>
