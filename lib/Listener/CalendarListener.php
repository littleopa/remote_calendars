<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Listener;

use OCA\DAV\Events\CalendarDeletedEvent;
use OCA\DAV\Events\CalendarMovedToTrashEvent;
use OCA\RemoteCalendars\Service\Store;
use OCA\RemoteCalendars\Service\SyncService;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Local change in a connected calendar → push it to the remote server at the end of the
 * request (outside the database transaction). A deleted local calendar ends the connection.
 *
 * @template-implements IEventListener<Event>
 */
class CalendarListener implements IEventListener {
	/** @var array<int,true> bindings scheduled for push in this request */
	private static array $pending = [];

	public function __construct(private Store $store) {
	}

	public function handle(Event $event): void {
		if ($event instanceof CalendarDeletedEvent || $event instanceof CalendarMovedToTrashEvent) {
			$b = $this->store->bindingByCalendar($event->getCalendarId());
			if ($b !== null) {
				$this->store->deleteBinding((int)$b['id']);
			}
			return;
		}
		if (SyncService::$writing) {
			return;
		}
		$ids = [];
		if ($event instanceof CalendarObjectMovedEvent) {
			$ids = [$event->getSourceCalendarId(), $event->getTargetCalendarId()];
		} elseif ($event instanceof AbstractCalendarObjectEvent) {
			$ids = [$event->getCalendarId()];
		}
		foreach ($ids as $calendarId) {
			$b = $this->store->bindingByCalendar((int)$calendarId);
			if ($b === null || isset(self::$pending[(int)$b['id']])) {
				continue;
			}
			// do not slow down saving while the remote server is known to be failing; the job retries
			if ((int)$b['error_since'] > 0 && time() - (int)$b['error_since'] < 900) {
				continue;
			}
			self::$pending[(int)$b['id']] = true;
			$bindingId = (int)$b['id'];
			register_shutdown_function(static function () use ($bindingId): void {
				try {
					$store = Server::get(Store::class);
					$binding = $store->binding($bindingId);
					if ($binding !== null) {
						Server::get(SyncService::class)->sync($binding, true, 15);
					}
				} catch (\Throwable $e) {
					Server::get(LoggerInterface::class)->info('Immediate push failed, background job will retry: ' . $e->getMessage(), ['app' => 'remote_calendars']);
				}
			});
		}
	}
}
