<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Service;

/**
 * Login for a remote CalDAV account.
 *
 * Passed around instead of a plain password so it never shows up in a logged stack trace:
 * Nextcloud's exception serializer only reads public properties of objects found in trace
 * arguments, and both properties here are private. Keep it that way.
 */
final class Credentials {
	public function __construct(
		private readonly string $username,
		#[\SensitiveParameter]
		private readonly string $password,
	) {
	}

	public function username(): string {
		return $this->username;
	}

	public function password(): string {
		return $this->password;
	}

	/** @return array{0:string,1:string} the Guzzle "auth" option */
	public function toAuthOption(): array {
		return [$this->username, $this->password];
	}

	public function __debugInfo(): array {
		return ['username' => $this->username, 'password' => '***'];
	}

	public function __serialize(): array {
		throw new \LogicException('Credentials must not be serialized.');
	}
}
