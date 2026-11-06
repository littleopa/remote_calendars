<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Service;

class AuthError extends UserError {
	public function __construct() {
		parent::__construct('Login failed: the username or password is not (or no longer) correct.');
	}
}
