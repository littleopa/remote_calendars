<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;

/**
 * Minimal CalDAV client. Every target is validated (https only, no private networks unless
 * allowed by the admin) and redirects are only followed on the same host so credentials cannot leak.
 */
class DavClient {
	public const NS_DAV = 'DAV:';
	public const NS_CAL = 'urn:ietf:params:xml:ns:caldav';
	public const NS_APPLE = 'http://apple.com/ns/ical/';

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
	) {
	}

	private function allowLocal(): bool {
		return $this->appConfig->getValueBool('remote_calendars', 'allow_local_addresses', false);
	}

	/**
	 * @param array{0:string,1:string} $auth
	 * @return array{status:int, body:string, etag:string, url:string}
	 */
	public function request(string $method, string $url, array $auth, ?string $body = null, array $headers = [], int $timeout = 30): array {
		$origHost = strtolower((string)parse_url($url, PHP_URL_HOST));
		for ($i = 0; $i < 6; $i++) {
			$resolve = self::checkUrl($url, $this->allowLocal());
			$opts = [
				'auth' => $auth,
				'headers' => $headers + ['User-Agent' => 'Nextcloud-RemoteCalendars/1.0'],
				'timeout' => $timeout,
				'connect_timeout' => min(10, $timeout),
				'http_errors' => false,
				'allow_redirects' => false,
				'curl' => [CURLOPT_RESOLVE => $resolve],
				'nextcloud' => ['allow_local_address' => true],
			];
			if ($body !== null) {
				$opts['body'] = $body;
			}
			$opts['stream'] = true;
			try {
				$resp = $this->clientService->newClient()->request($method, $url, $opts);
			} catch (\Throwable $e) {
				throw new UserError('Server not reachable: %s', [self::shortError($e->getMessage())]);
			}
			$status = $resp->getStatusCode();
			$location = $resp->getHeader('Location');
			if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
				$next = self::absolute($url, $location);
				if (strtolower((string)parse_url($next, PHP_URL_HOST)) !== $origHost) {
					throw new UserError('The server redirects to a different server (%s). Please enter that address directly.', [(string)parse_url($next, PHP_URL_HOST)]);
				}
				$url = $next;
				if ($status === 303) {
					$method = 'GET';
					$body = null;
				}
				continue;
			}
			return ['status' => $status, 'body' => $this->readLimited($resp), 'etag' => $resp->getHeader('ETag'), 'url' => $url];
		}
		throw new UserError('Too many redirects.');
	}

	private function maxBytes(): int {
		return max(1, $this->appConfig->getValueInt('remote_calendars', 'max_response_mb', 20)) * 1024 * 1024;
	}

	/** Reads the response body, but never more than the configured maximum (protects memory). */
	private function readLimited(\OCP\Http\Client\IResponse $resp): string {
		$max = $this->maxBytes();
		$len = $resp->getHeader('Content-Length');
		if ($len !== '' && ctype_digit($len) && (int)$len > $max) {
			throw new UserError('The remote server sent too much data (more than %s MB).', [(string)intdiv($max, 1048576)]);
		}
		$b = $resp->getBody();
		if (!is_resource($b)) {
			$b = (string)$b;
			if (strlen($b) > $max) {
				throw new UserError('The remote server sent too much data (more than %s MB).', [(string)intdiv($max, 1048576)]);
			}
			return $b;
		}
		$out = '';
		while (!feof($b)) {
			$chunk = fread($b, 65536);
			if ($chunk === false) {
				break;
			}
			$out .= $chunk;
			if (strlen($out) > $max) {
				fclose($b);
				throw new UserError('The remote server sent too much data (more than %s MB).', [(string)intdiv($max, 1048576)]);
			}
		}
		fclose($b);
		return $out;
	}

	private static function shortError(string $msg): string {
		$msg = preg_replace('/\s*\(see https?:\/\/curl[^)]*\)/', '', $msg) ?? $msg;
		return mb_substr($msg, 0, 200);
	}

	/** @return string[] CURLOPT_RESOLVE entries (prevents DNS rebinding) */
	public static function checkUrl(string $url, bool $allowLocal = false): array {
		$p = parse_url($url);
		if ($p === false || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
			throw new UserError('Please enter an https:// address.');
		}
		if (isset($p['user']) || isset($p['pass'])) {
			throw new UserError('Please do not put credentials into the address.');
		}
		$host = trim($p['host'], '[]');
		$port = (int)($p['port'] ?? 443);
		if (!$allowLocal && !in_array($port, [443, 8443], true)) {
			throw new UserError('Only the ports 443 and 8443 are allowed.');
		}
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			$ips = [$host];
		} else {
			$ips = gethostbynamel($host) ?: [];
			$v6 = @dns_get_record($host, DNS_AAAA) ?: [];
			foreach ($v6 as $r) {
				if (!empty($r['ipv6'])) {
					$ips[] = $r['ipv6'];
				}
			}
		}
		if ($ips === []) {
			throw new UserError('Server "%s" was not found.', [$host]);
		}
		foreach ($allowLocal ? [] : $ips as $ip) {
			if (self::isInternal($ip)) {
				throw new UserError('Addresses in local or private networks are not allowed.');
			}
		}
		$ip = $ips[0];
		return [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
	}

	/** Ranges PHP's filter flags do not cover (CGNAT, benchmarking, IETF, multicast, NAT64 …). */
	private const EXTRA_BLOCKED = [
		'100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4',
		'64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '100::/64', 'ff00::/8',
	];

	public static function isInternal(string $ip): bool {
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return true;
		}
		foreach (self::EXTRA_BLOCKED as $cidr) {
			if (self::inCidr($ip, $cidr)) {
				return true;
			}
		}
		// addresses of this server itself (public IPs reach its own internal services)
		static $own = null;
		if ($own === null) {
			$own = [];
			if (function_exists('net_get_interfaces')) {
				foreach (net_get_interfaces() ?: [] as $if) {
					foreach ($if['unicast'] ?? [] as $u) {
						if (!empty($u['address']) && filter_var($u['address'], FILTER_VALIDATE_IP)) {
							$own[] = inet_pton($u['address']);
						}
					}
				}
			}
		}
		return in_array(inet_pton($ip), $own, true);
	}

	private static function inCidr(string $ip, string $cidr): bool {
		[$net, $bits] = explode('/', $cidr);
		$a = inet_pton($ip);
		$b = inet_pton($net);
		if ($a === false || $b === false || strlen($a) !== strlen($b)) {
			return false;
		}
		$bits = (int)$bits;
		$bytes = intdiv($bits, 8);
		if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
			return false;
		}
		$rest = $bits % 8;
		if ($rest === 0) {
			return true;
		}
		$mask = (0xFF << (8 - $rest)) & 0xFF;
		return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
	}

	/** scheme://host:port of a URL (lower case, default port filled in). */
	public static function origin(string $url): string {
		$p = parse_url($url);
		$scheme = strtolower($p['scheme'] ?? '');
		$port = $p['port'] ?? ($scheme === 'http' ? 80 : 443);
		return $scheme . '://' . strtolower(trim($p['host'] ?? '', '[]')) . ':' . $port;
	}

	/**
	 * Discovery only: the calendar home may live on a sibling host of the entered server
	 * (e.g. caldav.example.com → p12-caldav.example.com). Allowed when the other host lies under the
	 * parent domain of the entered host. Conservative: parents that look like a public suffix
	 * ("co.uk", "com.au", "abc.de" …) only allow the identical host.
	 */
	public static function sameSite(string $entered, string $other): bool {
		$ha = strtolower((string)parse_url($entered, PHP_URL_HOST));
		$hb = strtolower((string)parse_url($other, PHP_URL_HOST));
		if ($ha === $hb) {
			return true;
		}
		if ($ha === '' || filter_var(trim($ha, '[]'), FILTER_VALIDATE_IP) || filter_var(trim($hb, '[]'), FILTER_VALIDATE_IP)) {
			return false;
		}
		$labels = explode('.', $ha);
		if (count($labels) < 3) {
			return false;
		}
		$parent = implode('.', array_slice($labels, 1));
		if (preg_match('/^[a-z0-9-]{1,3}\.[a-z]{2}$/', $parent)) {
			return false;
		}
		return $hb === $parent || str_ends_with($hb, '.' . $parent);
	}

	public static function absolute(string $base, string $href): string {
		if (preg_match('#^https?://#i', $href)) {
			return $href;
		}
		$p = parse_url($base);
		$root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
		if (str_starts_with($href, '/')) {
			return $root . $href;
		}
		$path = $p['path'] ?? '/';
		$dir = str_ends_with($path, '/') ? $path : (dirname($path) . '/');
		return $root . $dir . $href;
	}

	/** Comparison key for URLs (ignores encoding differences and trailing slash). */
	public static function key(string $url): string {
		$path = (string)(parse_url($url, PHP_URL_PATH) ?? $url);
		return rtrim(rawurldecode($path), '/');
	}

	public function propfind(string $url, array $auth, int $depth, string $xml): array {
		$r = $this->request('PROPFIND', $url, $auth, $xml, [
			'Depth' => (string)$depth,
			'Content-Type' => 'application/xml; charset=utf-8',
		]);
		$r['responses'] = $r['status'] === 207 ? self::parseMultistatus($r['body'], $r['url']) : [];
		return $r;
	}

	public function report(string $url, array $auth, string $xml): array {
		$r = $this->request('REPORT', $url, $auth, $xml, [
			'Depth' => '1',
			'Content-Type' => 'application/xml; charset=utf-8',
		], 120);
		$r['responses'] = $r['status'] === 207 ? self::parseMultistatus($r['body'], $r['url']) : [];
		return $r;
	}

	/**
	 * @return list<array{href:string, props:array<string,\DOMElement>}>  href absolut
	 */
	public static function parseMultistatus(string $xml, string $baseUrl): array {
		$doc = new \DOMDocument();
		if (trim($xml) === '' || !@$doc->loadXML($xml, LIBXML_NONET)) {
			return [];
		}
		$out = [];
		foreach ($doc->getElementsByTagNameNS(self::NS_DAV, 'response') as $resp) {
			$href = null;
			$props = [];
			foreach ($resp->childNodes as $c) {
				if (!$c instanceof \DOMElement || $c->namespaceURI !== self::NS_DAV) {
					continue;
				}
				if ($c->localName === 'href' && $href === null) {
					$href = trim($c->textContent);
				} elseif ($c->localName === 'propstat') {
					$ok = true;
					$propEl = null;
					foreach ($c->childNodes as $pc) {
						if (!$pc instanceof \DOMElement) {
							continue;
						}
						if ($pc->localName === 'status' && !preg_match('#\s2\d\d\s#', ' ' . $pc->textContent . ' ')) {
							$ok = false;
						}
						if ($pc->localName === 'prop') {
							$propEl = $pc;
						}
					}
					if ($ok && $propEl) {
						foreach ($propEl->childNodes as $p) {
							if ($p instanceof \DOMElement) {
								$props['{' . $p->namespaceURI . '}' . $p->localName] = $p;
							}
						}
					}
				}
			}
			if ($href !== null) {
				$out[] = ['href' => self::absolute($baseUrl, $href), 'props' => $props];
			}
		}
		return $out;
	}

	public static function text(array $props, string $name): string {
		return isset($props[$name]) ? trim($props[$name]->textContent) : '';
	}

	/** First <d:href> inside a property. */
	public static function hrefIn(array $props, string $name): string {
		if (!isset($props[$name])) {
			return '';
		}
		$h = $props[$name]->getElementsByTagNameNS(self::NS_DAV, 'href')->item(0);
		return $h ? trim($h->textContent) : '';
	}

	/** @return string[] child element names, e.g. "{DAV:}collection" */
	public static function childNames(array $props, string $name): array {
		$out = [];
		if (isset($props[$name])) {
			foreach ($props[$name]->getElementsByTagName('*') as $el) {
				$out[] = '{' . $el->namespaceURI . '}' . $el->localName;
			}
		}
		return $out;
	}
}
