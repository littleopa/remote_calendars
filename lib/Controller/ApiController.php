<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Controller;

use OCA\RemoteCalendars\AppInfo\Application;
use OCA\RemoteCalendars\Service\AccountService;
use OCA\RemoteCalendars\Service\Discovery;
use OCA\RemoteCalendars\Service\UserError;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AccountService $accounts,
		private Discovery $discovery,
		private IAppConfig $appConfig,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		return $this->userSession->getUser()->getUID();
	}

	private function run(callable $fn): JSONResponse {
		try {
			return new JSONResponse($fn() ?? ['ok' => true]);
		} catch (UserError $e) {
			return new JSONResponse(['error' => $this->l->t($e->getTemplate(), $e->getParams())], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e, 'app' => Application::APP_ID]);
			return new JSONResponse(['error' => $this->l->t('Unexpected error, see the server log.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function translateErrors(array $list): array {
		foreach ($list as &$item) {
			if (isset($item['error']['m'])) {
				$item['error'] = $this->l->t($item['error']['m'], $item['error']['p'] ?? []);
			}
			if (isset($item['calendars'])) {
				$item['calendars'] = $this->translateErrors($item['calendars']);
			}
		}
		return $list;
	}

	#[NoAdminRequired]
	public function accounts(): JSONResponse {
		return $this->run(fn () => ['accounts' => $this->translateErrors($this->accounts->overview($this->uid()))]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function discover(string $url = '', string $username = '', string $password = ''): JSONResponse {
		return $this->run(function () use ($url, $username, $password) {
			if (trim($url) === '' || $username === '' || $password === '') {
				throw new UserError('Please fill in address, username and password.');
			}
			return ['calendars' => $this->accounts->markBound($this->uid(), $this->discovery->discover($url, $username, $password))];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function connect(string $url = '', string $username = '', string $password = '', array $calendars = []): JSONResponse {
		return $this->run(fn () => ['results' => $this->translateErrors($this->accounts->connect($this->uid(), $url, $username, $password, $calendars))]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function accountDiscover(int $id): JSONResponse {
		return $this->run(fn () => ['calendars' => $this->accounts->discoverForAccount($this->uid(), $id)]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function accountAdd(int $id, array $calendars = []): JSONResponse {
		return $this->run(fn () => ['results' => $this->translateErrors($this->accounts->addToAccount($this->uid(), $id, $calendars))]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function accountPassword(int $id, string $password = ''): JSONResponse {
		return $this->run(fn () => $this->accounts->changePassword($this->uid(), $id, $password));
	}

	#[NoAdminRequired]
	public function accountDelete(int $id): JSONResponse {
		return $this->run(fn () => $this->accounts->removeAccount($this->uid(), $id));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	public function bindingSync(int $id): JSONResponse {
		return $this->run(fn () => ['stats' => $this->accounts->syncNow($this->uid(), $id)]);
	}

	#[NoAdminRequired]
	public function bindingDelete(int $id): JSONResponse {
		return $this->run(fn () => $this->accounts->removeBinding($this->uid(), $id));
	}

	/** Admin only (no NoAdminRequired attribute). */
	public function adminSave(string $presets = '', bool $allowLocal = false, int $interval = 5): JSONResponse {
		return $this->run(function () use ($presets, $allowLocal, $interval) {
			$list = [];
			foreach (preg_split('/\R/', $presets) as $line) {
				$parts = array_map('trim', explode('|', $line, 2));
				if (count($parts) === 2 && $parts[0] !== '' && preg_match('#^https://#i', $parts[1])) {
					$list[] = ['name' => mb_substr($parts[0], 0, 80), 'url' => $parts[1]];
				}
			}
			$this->appConfig->setValueArray(Application::APP_ID, 'presets', $list);
			$this->appConfig->setValueBool(Application::APP_ID, 'allow_local_addresses', $allowLocal);
			$this->appConfig->setValueInt(Application::APP_ID, 'sync_interval', max(1, min(60, $interval)) * 60);
			return ['presets' => $list];
		});
	}
}
