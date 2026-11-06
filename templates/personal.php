<?php
/** @var \OCP\IL10N $l */
?>
<div id="rc-personal" class="section">
	<h2><?php p($l->t('Remote calendars')); ?></h2>
	<p class="settings-hint" id="rc-intro"></p>

	<div id="rc-accounts" aria-live="polite"></div>

	<h3 id="rc-add-title"><?php p($l->t('Connect a calendar')); ?></h3>
	<form id="rc-form" class="rc-form" autocomplete="off">
		<div id="rc-presets" class="rc-presets"></div>
		<label for="rc-url"><?php p($l->t('Server address')); ?></label>
		<input type="url" id="rc-url" name="url" required placeholder="https://caldav.example.com/" />
		<label for="rc-user"><?php p($l->t('Username')); ?></label>
		<input type="text" id="rc-user" name="username" required autocomplete="off" />
		<label for="rc-pass"><?php p($l->t('Password')); ?></label>
		<input type="password" id="rc-pass" name="password" required autocomplete="new-password" />
		<div class="rc-actions">
			<button type="submit" class="primary" id="rc-search"><?php p($l->t('Find calendars')); ?></button>
		</div>
		<p class="rc-msg" id="rc-form-msg" role="status"></p>
	</form>
	<div id="rc-found"></div>
</div>
