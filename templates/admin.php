<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<div id="rc-admin" class="section">
	<h2><?php p($l->t('Remote calendars')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Users connect calendars of other CalDAV servers themselves under Personal settings → Remote calendars.')); ?></p>
	<form id="rc-admin-form" class="rc-form">
		<label for="rc-admin-presets"><?php p($l->t('Suggested servers (one per line: Name | https://address)')); ?></label>
		<textarea id="rc-admin-presets" rows="4" placeholder="Groupware | https://dav.example.com/"><?php p($_['presets']); ?></textarea>
		<label for="rc-admin-interval"><?php p($l->t('Fetch remote changes every … minutes')); ?></label>
		<input type="number" id="rc-admin-interval" min="1" max="60" value="<?php p((string)$_['interval']); ?>" />
		<p class="rc-check">
			<input type="checkbox" class="checkbox" id="rc-admin-local" <?php if ($_['allowLocal']) { print_unescaped('checked'); } ?> />
			<label for="rc-admin-local"><?php p($l->t('Allow servers in local/private networks (security risk: users could reach internal services)')); ?></label>
		</p>
		<div class="rc-actions"><button type="submit" class="primary"><?php p($l->t('Save')); ?></button></div>
		<p class="rc-msg" id="rc-admin-msg" role="status"></p>
	</form>
</div>
