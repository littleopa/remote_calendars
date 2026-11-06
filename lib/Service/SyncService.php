<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\RemoteCalendars\AppInfo\Application;
use OCA\RemoteCalendars\Service\DavClient as D;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Two-way sync between one remote CalDAV collection and one local Nextcloud calendar.
 *
 * State per object (table extkal_objects): local uri + local etag, remote href + remote etag.
 *  push: local objects whose etag differs from the mapping → PUT (If-Match), missing → DELETE.
 *  pull: remote hrefs whose etag differs → calendar-multiget → write locally; vanished → delete locally.
 * Conflicts (412) are resolved in favour of the remote server.
 */
class SyncService {
	/** true while this app writes to the local calendar – listeners must ignore those events */
	public static bool $writing = false;

	private const PROPS_ETAG = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/><d:resourcetype/></d:prop></d:propfind>';
	private const MULTIGET_BATCH = 50;

	public function __construct(
		private Store $store,
		private CalDavBackend $cal,
		private DavClient $dav,
		private ILockingProvider $locking,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{pushed:int,pulled:int,deleted:int,conflicts:int,skipped:int,failed:int,locked?:bool}
	 * @throws UserError
	 */
	public function sync(array $binding, bool $pushOnly = false, int $timeout = 30): array {
		$stats = ['pushed' => 0, 'pulled' => 0, 'deleted' => 0, 'conflicts' => 0, 'skipped' => 0, 'failed' => 0];
		$lockKey = 'remote_calendars/binding/' . $binding['id'];
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return $stats + ['locked' => true];
		}
		try {
			$calendarId = (int)$binding['calendar_id'];
			$calendar = $this->cal->getCalendarById($calendarId);
			if ($calendar === null || !empty($calendar['{http://nextcloud.com/ns}deleted-at'])) {
				// local calendar was deleted by the user → forget the connection (remote stays untouched)
				$this->store->deleteBinding((int)$binding['id']);
				throw new UserError('The local calendar no longer exists, the connection was removed.');
			}
			$account = $this->store->account((int)$binding['account_id']);
			if ($account === null) {
				$this->store->deleteBinding((int)$binding['id']);
				throw new UserError('The account no longer exists.');
			}
			$auth = $this->store->credentials($account);

			$this->push($binding, $auth, $stats, $timeout);
			if (!$pushOnly) {
				$this->pull($binding, $auth, $stats);
			}
			$fields = ['last_sync' => time()];
			if ($stats['failed'] > 0) {
				$fields['last_error'] = (new UserError('%s entries could not be synchronised.', [(string)$stats['failed']]))->toJson();
			} else {
				$fields['last_error'] = null;
				$fields['error_since'] = 0;
				if ((int)$binding['notified'] === 1) {
					$fields['notified'] = 0;
					$this->dismissNotification($binding);
				}
			}
			$this->store->updateBinding((int)$binding['id'], $fields);
			return $stats;
		} catch (UserError $e) {
			if ($this->store->binding((int)$binding['id']) !== null) {
				$this->recordError($binding, $e);
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('Remote calendar sync failed for binding ' . $binding['id'] . ': ' . $e->getMessage(), ['exception' => $e, 'app' => Application::APP_ID]);
			$ue = new UserError('Unexpected error: %s', [mb_substr($e->getMessage(), 0, 200)]);
			$this->recordError($binding, $ue);
			throw $ue;
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function recordError(array $binding, UserError $e): void {
		$since = (int)$binding['error_since'] ?: time();
		$fields = ['last_error' => $e->toJson(), 'error_since' => $since, 'last_sync' => time()];
		// notify immediately on login problems, otherwise after 6 hours of failure
		if ((int)$binding['notified'] === 0 && ($e instanceof AuthError || time() - $since > 6 * 3600)) {
			$fields['notified'] = 1;
			$this->notify($binding, $e instanceof AuthError ? 'auth_failed' : 'sync_failed');
		}
		$this->store->updateBinding((int)$binding['id'], $fields);
	}

	private function notify(array $binding, string $subject): void {
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($binding['user_id'])
				->setDateTime(new \DateTime())
				->setObject('binding', (string)$binding['id'])
				->setSubject($subject, ['name' => $binding['name']]);
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not send notification: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	private function dismissNotification(array $binding): void {
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)->setUser($binding['user_id'])->setObject('binding', (string)$binding['id']);
		$this->notifications->markProcessed($n);
	}

	private function localObjects(int $calendarId): array {
		$out = [];
		foreach ($this->cal->getCalendarObjects($calendarId) as $o) {
			$out[$o['uri']] = $o['etag'];
		}
		return $out;
	}

	// ------------------------------------------------------------------ push
	private function push(array $binding, array $auth, array &$stats, int $timeout): void {
		$calendarId = (int)$binding['calendar_id'];
		$bid = (int)$binding['id'];
		$readonly = (int)$binding['readonly'] === 1;
		$collection = rtrim($binding['remote_url'], '/') . '/';
		$local = $this->localObjects($calendarId);
		$byUri = [];
		foreach ($this->store->mappings($bid) as $m) {
			if (D::origin($m['remote_href']) !== D::origin($collection)) {
				$this->store->deleteMapping((int)$m['id']);
				continue;
			}
			if ($m['local_uri'] !== '') {
				$byUri[$m['local_uri']] = $m;
			}
		}

		foreach ($local as $uri => $etag) {
			$m = $byUri[$uri] ?? null;
			if ($m !== null && $m['local_etag'] === $etag) {
				continue; // unchanged
			}
			if ($readonly) {
				$this->revertLocal($calendarId, $uri, $m, $stats);
				continue;
			}
			$obj = $this->cal->getCalendarObject($calendarId, $uri);
			if ($obj === null) {
				continue;
			}
			$headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
			if ($m !== null) {
				$href = $m['remote_href'];
				if ($m['remote_etag'] !== '') {
					$headers['If-Match'] = $m['remote_etag'];
				}
			} else {
				$href = $collection . rawurlencode($uri);
				$headers['If-None-Match'] = '*';
			}
			$r = $this->dav->request('PUT', $href, $auth, $obj['calendardata'], $headers, $timeout);
			if ($r['status'] === 412 && $m === null) {
				// name already taken on the server → try a fresh one
				$href = $collection . bin2hex(random_bytes(16)) . '.ics';
				$r = $this->dav->request('PUT', $href, $auth, $obj['calendardata'], $headers, $timeout);
			}
			$s = $r['status'];
			if ($s === 401) {
				throw new AuthError();
			}
			if ($s >= 200 && $s < 300) {
				$remoteEtag = ($r['etag'] !== '' && !str_starts_with($r['etag'], 'W/')) ? $r['etag'] : '';
				if ($m !== null) {
					$this->store->updateMapping((int)$m['id'], ['remote_etag' => $remoteEtag, 'local_etag' => $etag]);
				} else {
					$this->store->insertMapping($bid, $uri, $href, $remoteEtag, $etag);
				}
				$stats['pushed']++;
			} elseif ($s === 412 && $m !== null) {
				// changed on the server meanwhile → server wins on next pull
				$this->store->updateMapping((int)$m['id'], ['remote_etag' => '']);
				$stats['conflicts']++;
			} elseif ($s === 403 || $s === 405) {
				// server refuses writing this entry → restore server state
				$this->revertLocal($calendarId, $uri, $m, $stats);
			} else {
				$stats['failed']++;
				$this->logger->warning("PUT $href → HTTP $s", ['app' => Application::APP_ID]);
			}
		}

		foreach ($byUri as $uri => $m) {
			if (isset($local[$uri])) {
				continue;
			}
			if ($readonly) {
				$this->store->updateMapping((int)$m['id'], ['local_uri' => '', 'remote_etag' => '']);
				$stats['skipped']++;
				continue;
			}
			$headers = $m['remote_etag'] !== '' ? ['If-Match' => $m['remote_etag']] : [];
			$r = $this->dav->request('DELETE', $m['remote_href'], $auth, null, $headers, $timeout);
			$s = $r['status'];
			if ($s === 401) {
				throw new AuthError();
			}
			if (($s >= 200 && $s < 300) || $s === 404 || $s === 410) {
				$this->store->deleteMapping((int)$m['id']);
				$stats['pushed']++;
			} elseif (in_array($s, [403, 405, 412], true)) {
				// not allowed or changed on server → bring it back on next pull
				$this->store->updateMapping((int)$m['id'], ['local_uri' => '', 'remote_etag' => '']);
				$stats[$s === 412 ? 'conflicts' : 'skipped']++;
			} else {
				$stats['failed']++;
				$this->logger->warning('DELETE ' . $m['remote_href'] . " → HTTP $s", ['app' => Application::APP_ID]);
			}
		}
	}

	private function revertLocal(int $calendarId, string $uri, ?array $m, array &$stats): void {
		if ($m !== null) {
			$this->store->updateMapping((int)$m['id'], ['remote_etag' => '']); // re-fetch from server
		} else {
			$this->writeLocal(fn () => $this->cal->deleteCalendarObject($calendarId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true));
		}
		$stats['skipped']++;
	}

	private function writeLocal(callable $fn): mixed {
		$prev = self::$writing;
		self::$writing = true;
		try {
			return $fn();
		} finally {
			self::$writing = $prev;
		}
	}

	// ------------------------------------------------------------------ pull
	private function pull(array $binding, array $auth, array &$stats): void {
		$calendarId = (int)$binding['calendar_id'];
		$bid = (int)$binding['id'];
		$collection = rtrim($binding['remote_url'], '/') . '/';

		$r = $this->dav->propfind($collection, $auth, 1, self::PROPS_ETAG);
		if ($r['status'] === 401) {
			throw new AuthError();
		}
		if ($r['status'] === 404) {
			throw new UserError('The calendar no longer exists on the remote server.');
		}
		if ($r['status'] !== 207) {
			throw new UserError('The remote server answered with HTTP %s.', [(string)$r['status']]);
		}
		$collKey = D::key($collection);
		$origin = D::origin($collection);
		$remote = []; // key => [href, etag]
		foreach ($r['responses'] as $resp) {
			if (D::origin($resp['href']) !== $origin) {
				continue; // entries on other servers are ignored (credentials stay on this server)
			}
			$key = D::key($resp['href']);
			if ($key === $collKey || in_array('{DAV:}collection', D::childNames($resp['props'], '{DAV:}resourcetype'), true)) {
				continue;
			}
			$remote[$key] = [$resp['href'], D::text($resp['props'], '{DAV:}getetag')];
		}

		$byKey = [];
		foreach ($this->store->mappings($bid) as $m) {
			$byKey[D::key($m['remote_href'])] = $m;
		}

		// safety net: an empty answer for a calendar we know to be non-empty is suspicious
		if ($remote === [] && count($byKey) > 10) {
			throw new UserError('The remote server suddenly reports an empty calendar – nothing was deleted, please check.');
		}

		$need = [];
		foreach ($remote as $key => [$href, $etag]) {
			$m = $byKey[$key] ?? null;
			if ($m === null || $m['remote_etag'] === '' || $etag === '' || $m['remote_etag'] !== $etag) {
				$need[$key] = $href;
			}
		}

		$local = $this->localObjects($calendarId);
		foreach (array_chunk($need, self::MULTIGET_BATCH, true) as $chunk) {
			foreach ($this->fetch($collection, $auth, $chunk) as $key => [$data, $etag]) {
				$href = $chunk[$key];
				$etag = $etag !== '' ? $etag : $remote[$key][1];
				$m = $byKey[$key] ?? null;
				$this->storeLocal($bid, $calendarId, $local, $m, $href, $etag, $data, $stats);
			}
		}

		foreach ($byKey as $key => $m) {
			if (isset($remote[$key])) {
				continue;
			}
			if ($m['local_uri'] !== '' && isset($local[$m['local_uri']])) {
				$this->writeLocal(fn () => $this->cal->deleteCalendarObject($calendarId, $m['local_uri'], CalDavBackend::CALENDAR_TYPE_CALENDAR, true));
				$stats['deleted']++;
			}
			$this->store->deleteMapping((int)$m['id']);
		}
	}

	/** @return array<string, array{0:string,1:string}> key => [calendar-data, etag] */
	private function fetch(string $collection, array $auth, array $hrefs): array {
		$xml = '<?xml version="1.0" encoding="utf-8"?><c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop>';
		foreach ($hrefs as $href) {
			$xml .= '<d:href>' . htmlspecialchars((string)parse_url($href, PHP_URL_PATH), ENT_XML1) . '</d:href>';
		}
		$xml .= '</c:calendar-multiget>';
		$out = [];
		$r = $this->dav->report($collection, $auth, $xml);
		if ($r['status'] === 401) {
			throw new AuthError();
		}
		foreach ($r['responses'] as $resp) {
			if (D::origin($resp['href']) !== D::origin($collection)) {
				continue;
			}
			$data = D::text($resp['props'], '{' . D::NS_CAL . '}calendar-data');
			if ($data !== '') {
				$out[D::key($resp['href'])] = [$data, D::text($resp['props'], '{DAV:}getetag')];
			}
		}
		// fallback for servers without (complete) multiget support
		foreach ($hrefs as $key => $href) {
			if (isset($out[$key])) {
				continue;
			}
			$g = $this->dav->request('GET', $href, $auth, null, ['Accept' => 'text/calendar']);
			if ($g['status'] === 401) {
				throw new AuthError();
			}
			if ($g['status'] === 200 && str_contains($g['body'], 'BEGIN:VCALENDAR')) {
				$out[$key] = [$g['body'], $g['etag']];
			}
		}
		return $out;
	}

	private function storeLocal(int $bid, int $calendarId, array &$local, ?array $m, string $href, string $etag, string $data, array &$stats): void {
		$uri = ($m !== null && $m['local_uri'] !== '' && isset($local[$m['local_uri']])) ? $m['local_uri'] : null;
		try {
			if ($uri !== null) {
				$newEtag = $this->writeLocal(fn () => $this->cal->updateCalendarObject($calendarId, $uri, $data));
			} else {
				$uri = $this->freeUri($href, $local);
				$newEtag = $this->writeLocal(fn () => $this->cal->createCalendarObject($calendarId, $uri, $data));
			}
			$local[$uri] = $newEtag;
			$stats['pulled']++;
		} catch (\Throwable $e) {
			// invalid or duplicate entry: remember the etag so it is retried only when it changes
			$this->logger->info('Skipped remote entry ' . $href . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			$uri = '';
			$newEtag = '';
			$stats['skipped']++;
		}
		if ($m !== null) {
			$this->store->updateMapping((int)$m['id'], ['local_uri' => $uri, 'remote_href' => $href, 'remote_etag' => $etag, 'local_etag' => (string)$newEtag]);
		} else {
			$this->store->insertMapping($bid, $uri, $href, $etag, (string)$newEtag);
		}
	}

	private function freeUri(string $href, array $local): string {
		$name = rawurldecode(basename((string)parse_url($href, PHP_URL_PATH)));
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._@+-]{0,190}$/', $name) && !isset($local[$name])) {
			return $name;
		}
		return strtoupper(bin2hex(random_bytes(16))) . '.ics';
	}
}
