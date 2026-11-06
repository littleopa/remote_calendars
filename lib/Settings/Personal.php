<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Settings;

use OCA\RemoteCalendars\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(private IInitialState $state, private IAppConfig $appConfig) {
	}

	public function getForm(): TemplateResponse {
		$this->state->provideInitialState('presets', $this->appConfig->getValueArray(Application::APP_ID, 'presets', []));
		$this->state->provideInitialState('interval', (int)round($this->appConfig->getValueInt(Application::APP_ID, 'sync_interval', 300) / 60));
		Util::addScript(Application::APP_ID, 'personal');
		Util::addStyle(Application::APP_ID, 'personal');
		return new TemplateResponse(Application::APP_ID, 'personal', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
