<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Listener;

use OCA\RemoteCalendars\Service\Store;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Removes stored accounts (incl. encrypted passwords) of deleted users.
 * Their calendars are removed by the DAV app itself.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(private Store $store) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		foreach ($this->store->accountsOf($event->getUser()->getUID()) as $a) {
			foreach ($this->store->bindingsOfAccount((int)$a['id']) as $b) {
				$this->store->deleteBinding((int)$b['id']);
			}
			$this->store->deleteAccount((int)$a['id']);
		}
	}
}
