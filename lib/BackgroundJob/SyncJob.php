<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\BackgroundJob;

use OCA\RemoteCalendars\Service\Store;
use OCA\RemoteCalendars\Service\SyncService;
use OCA\RemoteCalendars\Service\UserError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

class SyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Store $store,
		private SyncService $sync,
		IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(max(60, $appConfig->getValueInt('remote_calendars', 'sync_interval', 300)));
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$started = time();
		$bindings = $this->store->allBindings();
		// least recently synchronised first, so one slow or broken server cannot starve the others
		usort($bindings, fn ($x, $y) => (int)$x['last_sync'] <=> (int)$y['last_sync']);
		foreach ($bindings as $b) {
			if (time() - $started > 240) {
				break; // time budget per run; the rest follows in the next run
			}
			// back off when a calendar fails for a long time (max. once per hour)
			if ((int)$b['error_since'] > 0 && time() - (int)$b['error_since'] > 6 * 3600 && time() - (int)$b['last_sync'] < 3600) {
				continue;
			}
			try {
				$this->sync->sync($b);
			} catch (UserError) {
				// stored on the binding and shown in the settings
			} catch (\Throwable $e) {
				\OCP\Server::get(\Psr\Log\LoggerInterface::class)->error('Remote calendar sync crashed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'remote_calendars']);
			}
		}
	}
}
