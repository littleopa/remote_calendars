<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Settings;

use OCA\RemoteCalendars\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(private IAppConfig $appConfig) {
	}

	public function getForm(): TemplateResponse {
		$presets = $this->appConfig->getValueArray(Application::APP_ID, 'presets', []);
		$lines = implode("\n", array_map(fn ($p) => $p['name'] . ' | ' . $p['url'], $presets));
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'personal');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'presets' => $lines,
			'allowLocal' => $this->appConfig->getValueBool(Application::APP_ID, 'allow_local_addresses', false),
			'interval' => (int)round($this->appConfig->getValueInt(Application::APP_ID, 'sync_interval', 300) / 60),
		], '');
	}

	public function getSection(): string {
		return 'groupware';
	}

	public function getPriority(): int {
		return 60;
	}
}
