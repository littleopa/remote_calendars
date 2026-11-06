<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Service;

use OCA\RemoteCalendars\Service\DavClient as D;

/**
 * Finds the calendars of a CalDAV account, starting from whatever address the user entered
 * (server root, principal, calendar home or a single calendar).
 */
class Discovery {
	private const PROPS_START = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:resourcetype/><d:current-user-principal/><c:calendar-home-set/></d:prop></d:propfind>';
	private const PROPS_HOME = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/></d:prop></d:propfind>';
	private const PROPS_CAL = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/"><d:prop><d:resourcetype/><d:displayname/><a:calendar-color/><c:supported-calendar-component-set/><d:current-user-privilege-set/></d:prop></d:propfind>';

	public function __construct(private DavClient $dav) {
	}

	public static function normalizeUrl(string $url): string {
		$url = trim($url);
		if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
			$url = 'https://' . $url;
		}
		if (preg_match('#^(webcal|caldav)s?://#i', $url)) {
			$url = preg_replace('#^[a-z]+://#i', 'https://', $url);
		}
		return $url;
	}

	/**
	 * @return list<array{url:string,name:string,color:string,components:string[],writable:bool}>
	 */
	public function discover(string $url, string $user, string $password): array {
		$url = self::normalizeUrl($url);
		$auth = [$user, $password];

		$r = $this->dav->propfind($url, $auth, 0, self::PROPS_START);
		if ($r['status'] === 401) {
			throw new AuthError();
		}
		if ($r['status'] !== 207) {
			$p = parse_url($url);
			$wk = 'https://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . '/.well-known/caldav';
			$r2 = $this->dav->propfind($wk, $auth, 0, self::PROPS_START);
			if ($r2['status'] === 401) {
				throw new AuthError();
			}
			if ($r2['status'] !== 207) {
				throw new UserError('No CalDAV server was found at this address (HTTP %s).', [(string)$r['status']]);
			}
			$r = $r2;
		}
		$base = $r['url'];
		$props = $r['responses'][0]['props'] ?? [];

		if (in_array('{' . D::NS_CAL . '}calendar', D::childNames($props, '{DAV:}resourcetype'), true)) {
			$single = $this->dav->propfind($base, $auth, 0, self::PROPS_CAL);
			$cals = array_values(array_filter(
				$this->calendarsFrom($single['responses'], null),
				fn ($c) => D::origin($c['url']) === D::origin($single['url']),
			));
			if ($cals !== []) {
				return $cals;
			}
		}

		$home = D::hrefIn($props, '{' . D::NS_CAL . '}calendar-home-set');
		if ($home === '') {
			$principal = D::hrefIn($props, '{DAV:}current-user-principal');
			if ($principal !== '' && !D::sameSite($base, D::absolute($base, $principal))) {
				$principal = ''; // never send the credentials to a foreign server
			}
			if ($principal !== '') {
				$pr = $this->dav->propfind(D::absolute($base, $principal), $auth, 0, self::PROPS_HOME);
				if ($pr['status'] === 207) {
					$home = D::hrefIn($pr['responses'][0]['props'] ?? [], '{' . D::NS_CAL . '}calendar-home-set');
					if ($home !== '') {
						$home = D::absolute($pr['url'], $home);
					}
				}
			}
		} else {
			$home = D::absolute($base, $home);
		}
		if ($home !== '' && !D::sameSite($base, $home)) {
			throw new UserError('The server points to a calendar storage on a different domain (%s). For security reasons this is not followed.', [(string)parse_url($home, PHP_URL_HOST)]);
		}
		$homeUrl = $home !== '' ? $home : $base;

		$list = $this->dav->propfind($homeUrl, $auth, 1, self::PROPS_CAL);
		if ($list['status'] === 401) {
			throw new AuthError();
		}
		$origin = D::origin($list['url']);
		$cals = array_values(array_filter(
			$this->calendarsFrom($list['responses'], D::key($homeUrl)),
			fn ($c) => D::origin($c['url']) === $origin,
		));
		if ($cals === []) {
			throw new UserError('Login worked, but no calendars were found for this account.');
		}
		usort($cals, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $cals;
	}

	private function calendarsFrom(array $responses, ?string $skipKey): array {
		$out = [];
		foreach ($responses as $resp) {
			$p = $resp['props'];
			if ($skipKey !== null && D::key($resp['href']) === $skipKey) {
				continue;
			}
			if (!in_array('{' . D::NS_CAL . '}calendar', D::childNames($p, '{DAV:}resourcetype'), true)) {
				continue;
			}
			$components = [];
			if (isset($p['{' . D::NS_CAL . '}supported-calendar-component-set'])) {
				foreach ($p['{' . D::NS_CAL . '}supported-calendar-component-set']->getElementsByTagNameNS(D::NS_CAL, 'comp') as $comp) {
					$components[] = strtoupper($comp->getAttribute('name'));
				}
				$components = array_values(array_intersect(['VEVENT', 'VTODO'], $components));
				if ($components === []) {
					continue; // e.g. journal-only calendars
				}
			} else {
				$components = ['VEVENT', 'VTODO'];
			}
			$writable = true;
			if (isset($p['{DAV:}current-user-privilege-set'])) {
				$privs = D::childNames($p, '{DAV:}current-user-privilege-set');
				$writable = (bool)array_intersect(['{DAV:}all', '{DAV:}write', '{DAV:}write-content', '{DAV:}bind'], $privs);
			}
			$color = D::text($p, '{' . D::NS_APPLE . '}calendar-color');
			$color = preg_match('/^#[0-9a-f]{6}/i', $color) ? substr($color, 0, 7) : '';
			$name = D::text($p, '{DAV:}displayname');
			if ($name === '') {
				$name = rawurldecode(basename(rtrim((string)parse_url($resp['href'], PHP_URL_PATH), '/')));
			}
			$out[] = [
				'url' => rtrim($resp['href'], '/') . '/',
				'name' => mb_substr($name, 0, 200),
				'color' => $color,
				'components' => $components,
				'writable' => $writable,
			];
		}
		return $out;
	}
}
