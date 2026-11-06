<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\AppInfo;

use OCA\DAV\Events\CalendarDeletedEvent;
use OCA\DAV\Events\CalendarMovedToTrashEvent;
use OCA\RemoteCalendars\Listener\CalendarListener;
use OCA\RemoteCalendars\Listener\UserDeletedListener;
use OCA\RemoteCalendars\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'remote_calendars';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		foreach ([
			CalendarObjectCreatedEvent::class, CalendarObjectUpdatedEvent::class,
			CalendarObjectDeletedEvent::class, CalendarObjectMovedToTrashEvent::class,
			CalendarObjectRestoredEvent::class, CalendarObjectMovedEvent::class,
			CalendarDeletedEvent::class, CalendarMovedToTrashEvent::class,
		] as $event) {
			$context->registerEventListener($event, CalendarListener::class);
		}
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
