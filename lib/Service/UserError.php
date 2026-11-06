<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Service;

/**
 * Error whose (translatable) message may be shown to the user.
 * The message is an English l10n template, parameters are substituted on display.
 */
class UserError extends \RuntimeException {
	public function __construct(string $template, private array $params = []) {
		parent::__construct(vsprintf($template, $params));
		$this->template = $template;
	}

	private string $template;

	public function getTemplate(): string {
		return $this->template;
	}

	public function getParams(): array {
		return $this->params;
	}

	/** Serialised form, stored in the database and translated when displayed. */
	public function toJson(): string {
		return json_encode(['m' => $this->template, 'p' => $this->params], JSON_UNESCAPED_UNICODE);
	}
}
