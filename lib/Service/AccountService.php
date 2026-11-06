<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RemoteCalendars\Service\DavClient as D;

class AccountService {
	public function __construct(
		private Store $store,
		private Discovery $discovery,
		private SyncService $sync,
		private CalDavBackend $cal,
	) {
	}

	/** Public view of a user's accounts and connected calendars (no passwords). */
	public function overview(string $uid): array {
		$out = [];
		foreach ($this->store->accountsOf($uid) as $a) {
			$bindings = [];
			foreach ($this->store->bindingsOfAccount((int)$a['id']) as $b) {
				$cal = $this->cal->getCalendarById((int)$b['calendar_id']);
				$bindings[] = [
					'id' => (int)$b['id'],
					'name' => $cal['{DAV:}displayname'] ?? $b['name'],
					'remoteName' => $b['name'],
					'remoteUrl' => $b['remote_url'],
					'color' => $cal['{http://apple.com/ns/ical/}calendar-color'] ?? '',
					'readonly' => (int)$b['readonly'] === 1,
					'lastSync' => (int)$b['last_sync'],
					'error' => $b['last_error'] ? json_decode($b['last_error'], true) : null,
				];
			}
			$out[] = ['id' => (int)$a['id'], 'url' => $a['url'], 'username' => $a['username'], 'calendars' => $bindings];
		}
		return $out;
	}

	/** Marks calendars that are already connected for this user. */
	public function markBound(string $uid, array $calendars): array {
		$bound = [];
		foreach ($this->store->accountsOf($uid) as $a) {
			foreach ($this->store->bindingsOfAccount((int)$a['id']) as $b) {
				$bound[D::key($b['remote_url'])] = true;
			}
		}
		foreach ($calendars as &$c) {
			$c['connected'] = isset($bound[D::key($c['url'])]);
		}
		return $calendars;
	}

	/**
	 * Connects the selected calendars. The list is re-discovered on the server, so only
	 * calendars that really belong to this account can be connected.
	 */
	public function connect(string $uid, string $url, string $username, string $password, array $selectedUrls, ?int $accountId = null): array {
		$url = Discovery::normalizeUrl($url);
		$found = $this->discovery->discover($url, $username, $password);
		$wanted = array_flip(array_map(fn ($u) => D::key((string)$u), $selectedUrls));

		if ($accountId !== null) {
			$account = $this->store->account($accountId, $uid) ?? throw new UserError('Account not found.');
		} else {
			$account = $this->store->findAccount($uid, $url, $username);
		}
		$id = $account !== null ? (int)$account['id'] : $this->store->createAccount($uid, $url, $username, $password);
		if ($account !== null && $accountId === null) {
			$this->store->setPassword($id, $password);
		}

		$results = [];
		foreach ($this->markBound($uid, $found) as $c) {
			if (!isset($wanted[D::key($c['url'])]) || $c['connected']) {
				continue;
			}
			$calendarId = (int)$this->cal->createCalendar('principals/users/' . $uid, 'remote-' . bin2hex(random_bytes(6)), [
				'{DAV:}displayname' => $c['name'],
				'{http://apple.com/ns/ical/}calendar-color' => $c['color'] ?: '#0082c9',
				'components' => implode(',', $c['components']),
			]);
			$bindingId = $this->store->createBinding($id, $uid, $calendarId, $c['url'], $c['name'], !$c['writable']);
			$res = ['name' => $c['name'], 'ok' => true, 'stats' => null, 'error' => null];
			try {
				@set_time_limit(600);
				$res['stats'] = $this->sync->sync($this->store->binding($bindingId), false, 30);
			} catch (UserError $e) {
				$res['ok'] = false;
				$res['error'] = ['m' => $e->getTemplate(), 'p' => $e->getParams()];
			}
			$results[] = $res;
		}
		if ($results === [] && $account === null) {
			$this->store->deleteAccount($id);
		}
		return $results;
	}

	public function discoverForAccount(string $uid, int $accountId): array {
		$a = $this->store->account($accountId, $uid) ?? throw new UserError('Account not found.');
		[$u, $p] = $this->store->credentials($a);
		return $this->markBound($uid, $this->discovery->discover($a['url'], $u, $p));
	}

	public function addToAccount(string $uid, int $accountId, array $urls): array {
		$a = $this->store->account($accountId, $uid) ?? throw new UserError('Account not found.');
		[$u, $p] = $this->store->credentials($a);
		return $this->connect($uid, $a['url'], $u, $p, $urls, $accountId);
	}

	public function changePassword(string $uid, int $accountId, string $password): void {
		$a = $this->store->account($accountId, $uid) ?? throw new UserError('Account not found.');
		$this->discovery->discover($a['url'], $a['username'], $password); // throws on wrong password
		$this->store->setPassword($accountId, $password);
		foreach ($this->store->bindingsOfAccount($accountId) as $b) {
			$this->store->updateBinding((int)$b['id'], ['error_since' => 0, 'last_error' => null]);
			try {
				$this->sync->sync($this->store->binding((int)$b['id']));
			} catch (UserError) {
			}
		}
	}

	/** Removes the connection and the local copy. The remote calendar is not touched. */
	public function removeBinding(string $uid, int $bindingId): void {
		$b = $this->store->binding($bindingId, $uid) ?? throw new UserError('Calendar not found.');
		$this->store->deleteBinding($bindingId);
		if ($this->cal->getCalendarById((int)$b['calendar_id']) !== null) {
			$this->cal->deleteCalendar((int)$b['calendar_id'], true);
		}
	}

	public function removeAccount(string $uid, int $accountId): void {
		$this->store->account($accountId, $uid) ?? throw new UserError('Account not found.');
		foreach ($this->store->bindingsOfAccount($accountId) as $b) {
			$this->removeBinding($uid, (int)$b['id']);
		}
		$this->store->deleteAccount($accountId);
	}

	public function syncNow(string $uid, int $bindingId): array {
		$b = $this->store->binding($bindingId, $uid) ?? throw new UserError('Calendar not found.');
		@set_time_limit(600);
		return $this->sync->sync($b);
	}
}
