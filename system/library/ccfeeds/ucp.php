<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * Universal Commerce Protocol — business side of the Catalog capability
 * (search + lookup over the REST transport) for CatCode Market Feeds.
 *
 * Built against the stable release 2026-08-25 of the specification at ucp.dev
 * (Overview, Catalog, Catalog Search, Catalog Lookup, Catalog REST binding —
 * read 15.10.2026). What the module implements, and nothing more, is what the
 * profile advertises: dev.ucp.shopping.catalog.search and
 * dev.ucp.shopping.catalog.lookup over REST, with an empty payment-handler
 * registry. There is no agent checkout here and the profile never promises one.
 *
 * Rules taken from the spec and enforced below:
 *  - the profile is served over HTTPS, without redirects, with
 *    `Cache-Control: public, max-age >= 60` and a validator (ETag);
 *  - every REST request MUST carry `UCP-Agent: profile="<url>"`; the business
 *    MUST fetch and validate that platform profile (cached), MUST validate the
 *    platform's protocol version and MUST compute the capability intersection;
 *  - fetching a platform profile: HTTPS only, no redirects, timeouts, a body
 *    size bound (>= 128 KiB), a 60 s minimum cache TTL, special-use IP
 *    addresses rejected (RFC 6890) and the resolved address pinned against DNS
 *    rebinding, a fixed-size cache and a global discovery rate limit;
 *  - error codes and HTTP statuses: invalid_profile_url 400,
 *    profile_unreachable 424, profile_malformed 422, version_unsupported 422,
 *    capabilities_incompatible 200 (application outcome), request_too_large 400.
 *
 * Generated from the OpenCart 3 build (sync.py): the same PHP 5.6-compatible
 * code, under the OpenCart 4 namespace. Edit the 3.x source, not this copy.
 */
class Ucp {
	/** Protocol release this implementation follows. */
	const VERSION = '2026-08-25';

	const SERVICE    = 'dev.ucp.shopping';
	const CAP_SEARCH = 'dev.ucp.shopping.catalog.search';
	const CAP_LOOKUP = 'dev.ucp.shopping.catalog.lookup';

	/** Path segment the REST endpoint hangs off: https://shop/cc-ucp/catalog/search. */
	const REST_PREFIX = 'cc-ucp';

	/** Seconds the business profile may be cached by platforms (spec floor: 60). */
	const PROFILE_TTL = 300;

	/** Platform profile fetching. */
	const FETCH_CONNECT_TIMEOUT = 3;
	const FETCH_TIMEOUT         = 5;
	const FETCH_MAX_BYTES       = 262144; // spec: SHOULD NOT bound below 128 KiB
	const CACHE_MIN_TTL         = 60;     // spec: minimum TTL floor
	const CACHE_MAX_TTL         = 3600;
	const CACHE_DEFAULT_TTL     = 300;
	const CACHE_FAIL_TTL        = 60;     // backoff on a failing profile URL
	const CACHE_MAX_FILES       = 256;    // fixed-size discovery footprint
	const DISCOVERY_PER_MINUTE  = 30;     // global limit on outbound fetches

	/** REST request limits. */
	const MAX_BODY_BYTES   = 65536;
	const MAX_LOOKUP_IDS   = 50;
	const DEFAULT_PAGE     = 10;  // spec: a default of 10 is RECOMMENDED
	const MAX_PAGE         = 50;
	const RATE_PER_MINUTE  = 120; // per client IP

	/**
	 * ISO 4217 currencies whose minor unit is not 1/100 (the rest use 2).
	 * UCP amounts are integers in minor units.
	 */
	private static $exponents = array(
		'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
		'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
		'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
	);

	/**
	 * Run $fn with warnings swallowed. OpenCart 4's error handler ignores the
	 * @ operator and redirects the visitor on any warning, so a failed
	 * unlink() or DNS lookup — which the code below checks anyway — would turn
	 * an agent request into an HTML error page.
	 */
	public static function quiet($fn) {
		set_error_handler(function () {
			return true;
		});

		try {
			return call_user_func($fn);
		} finally {
			restore_error_handler();
		}
	}

	public static function exponent($currency) {
		$currency = strtoupper((string)$currency);

		return isset(self::$exponents[$currency]) ? self::$exponents[$currency] : 2;
	}

	/** Major-unit amount → integer minor units. */
	public static function minor($value, $currency) {
		return (int)round((float)$value * pow(10, self::exponent($currency)));
	}

	/**
	 * Origin of a store URL ("https://shop.example:8443"), or '' when the URL
	 * cannot carry a UCP profile (not HTTPS).
	 */
	public static function origin($storeUrl) {
		$parts = parse_url((string)$storeUrl);

		if (!is_array($parts) || empty($parts['host']) || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https') {
			return '';
		}

		return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
	}

	/** True when the shop lives at the origin root, where /.well-known/ucp must be. */
	public static function atOriginRoot($storeUrl) {
		$path = (string)parse_url((string)$storeUrl, PHP_URL_PATH);

		return trim($path, '/') === '';
	}

	public static function profileUrl($storeUrl) {
		$origin = self::origin($storeUrl);

		return $origin !== '' ? $origin . '/.well-known/ucp' : '';
	}

	/** Base the OpenAPI paths (/catalog/search …) are appended to. */
	public static function endpoint($storeUrl) {
		return rtrim((string)$storeUrl, '/') . '/' . self::REST_PREFIX;
	}

	/**
	 * The business profile (/.well-known/ucp).
	 *
	 * `services` and `payment_handlers` are required registries even when
	 * empty; an empty JSON object, not an array, is what goes on the wire.
	 */
	public static function profile($storeUrl) {
		$spec   = 'https://ucp.dev/' . self::VERSION . '/specification/';
		$schema = 'https://ucp.dev/' . self::VERSION . '/schemas/';

		return array(
			'ucp' => array(
				'version'          => self::VERSION,
				'services'         => array(
					self::SERVICE => array(
						array(
							'version'   => self::VERSION,
							'spec'      => $spec . 'overview/',
							'transport' => 'rest',
							'schema'    => 'https://ucp.dev/' . self::VERSION . '/services/shopping/rest.openapi.json',
							'endpoint'  => self::endpoint($storeUrl),
						),
					),
				),
				'capabilities'     => array(
					self::CAP_SEARCH => array(
						array(
							'version' => self::VERSION,
							'spec'    => $spec . 'shopping/catalog/search',
							'schema'  => $schema . 'shopping/catalog_search.json',
						),
					),
					self::CAP_LOOKUP => array(
						array(
							'version' => self::VERSION,
							'spec'    => $spec . 'shopping/catalog/lookup',
							'schema'  => $schema . 'shopping/catalog_lookup.json',
						),
					),
				),
				'payment_handlers' => new \stdClass(),
			),
		);
	}

	public static function json($value) {
		$flags = 0;
		if (defined('JSON_UNESCAPED_SLASHES')) {
			$flags |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		}
		if (defined('JSON_PRESERVE_ZERO_FRACTION')) {
			$flags |= JSON_PRESERVE_ZERO_FRACTION;
		}
		if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}

		$out = json_encode($value, $flags);

		return $out === false ? '{}' : $out;
	}

	/**
	 * Send the profile with the hosting headers the spec requires, then stop.
	 * Answers If-None-Match with 304.
	 */
	public static function serveProfile($storeUrl) {
		$body = self::json(self::profile($storeUrl));
		$etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';

		self::cleanBuffers();
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: public, max-age=' . self::PROFILE_TTL);
		header('ETag: ' . $etag);
		header('Access-Control-Allow-Origin: *');
		header('X-Content-Type-Options: nosniff');

		$match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) : '';
		if ($match !== '' && $match === $etag) {
			self::status(304);
			exit;
		}

		self::status(200);
		if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET') !== 'HEAD') {
			echo $body;
		}
		exit;
	}

	/** Send a JSON response and stop. */
	public static function send($status, $body, $headers = array()) {
		self::cleanBuffers();
		self::status((int)$status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		foreach ($headers as $line) {
			header($line);
		}
		echo self::json($body);
		exit;
	}

	public static function status($code) {
		$texts = array(
			200 => 'OK', 304 => 'Not Modified', 400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed',
			415 => 'Unsupported Media Type', 422 => 'Unprocessable Entity', 424 => 'Failed Dependency',
			429 => 'Too Many Requests', 500 => 'Internal Server Error', 503 => 'Service Unavailable',
		);
		$protocol = isset($_SERVER['SERVER_PROTOCOL']) ? (string)$_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($protocol . ' ' . (int)$code . ' ' . (isset($texts[$code]) ? $texts[$code] : ''), true, (int)$code);
	}

	private static function cleanBuffers() {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
	}

	/**
	 * Error Response envelope: `ucp.status` is "error" and `messages` says why.
	 */
	public static function errorBody($code, $content, $continueUrl = '', $severity = 'unrecoverable') {
		$body = array(
			'ucp'      => array('version' => self::VERSION, 'status' => 'error'),
			'messages' => array(array(
				'type'     => 'error',
				'code'     => (string)$code,
				'content'  => (string)$content,
				'severity' => (string)$severity,
			)),
		);

		if ($continueUrl !== '') {
			$body['continue_url'] = (string)$continueUrl;
		}

		return $body;
	}

	/** `ucp` member of a successful catalog response. */
	public static function responseMeta($capability) {
		return array(
			'version'      => self::VERSION,
			'capabilities' => array(
				$capability => array(array('version' => self::VERSION)),
			),
		);
	}

	/**
	 * Profile URL from `UCP-Agent: profile="https://…"` (RFC 8941 dictionary).
	 * Returns '' when the header or its `profile` member is missing.
	 */
	public static function agentProfileUrl($header) {
		$header = trim((string)$header);

		if ($header === '') {
			return '';
		}

		if (!preg_match('/(?:^|[,;\s])profile\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $header, $m)) {
			return '';
		}

		return stripcslashes($m[1]);
	}

	/**
	 * Resolve and validate the platform profile named by the request.
	 *
	 * @param string $url      Profile URL from UCP-Agent.
	 * @param string $cacheDir Writable directory for the discovery cache.
	 * @param bool   $allowPrivate Local development only: accept private and
	 *                         loopback addresses and an untrusted TLS
	 *                         certificate. Never on a production shop.
	 * @return array array('ok' => true, 'profile' => array) on success, or
	 *               array('ok' => false, 'status' => int, 'code' => string,
	 *               'message' => string, 'retry' => int)
	 */
	public static function resolvePlatform($url, $cacheDir, $allowPrivate = false) {
		return self::quiet(function () use ($url, $cacheDir, $allowPrivate) {
			return Ucp::resolvePlatformNow($url, $cacheDir, $allowPrivate);
		});
	}

	/** resolvePlatform() without the warning guard; public only for the closure above. */
	public static function resolvePlatformNow($url, $cacheDir, $allowPrivate) {
		$url = trim((string)$url);

		if ($url === '') {
			return self::fail(400, 'invalid_profile_url', 'UCP-Agent header with a profile URL is required.');
		}

		$parts = parse_url($url);
		if (!is_array($parts) || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https' || empty($parts['host'])
			|| isset($parts['user']) || isset($parts['pass']) || strlen($url) > 2048) {
			return self::fail(400, 'invalid_profile_url', 'Platform profile URL must be an absolute https:// URL.');
		}

		$cacheDir = rtrim((string)$cacheDir, '/') . '/';
		$key      = $cacheDir . 'p_' . sha1($url) . '.json';

		$cached = self::readCache($key);
		if ($cached !== null) {
			return $cached;
		}

		// Global discovery budget: a flood of unknown profile URLs costs at most
		// this many outbound fetches a minute. Over budget the caller answers
		// 503 + Retry-After, which the spec lists as an accepted strategy.
		if (!self::hit($cacheDir, 'discovery', self::DISCOVERY_PER_MINUTE, 60)) {
			return self::fail(503, 'discovery_busy', 'Platform profile discovery is rate limited, retry shortly.', 30);
		}

		$result = self::fetchProfile($url, $parts, $allowPrivate);

		if ($result['ok']) {
			$check = self::validateProfile($result['profile']);
			if ($check !== '') {
				$result = self::fail(422, 'profile_malformed', $check);
			}
		}

		$ttl = $result['ok'] ? $result['ttl'] : self::CACHE_FAIL_TTL;
		unset($result['ttl']);
		if (!$result['ok'] && (int)$result['status'] === 503) {
			return $result; // our own budget, not the platform's fault — do not cache
		}

		self::writeCache($cacheDir, $key, $result, $ttl);

		return $result;
	}

	/**
	 * Minimal structural validation of a profile: `ucp.version` as a dated
	 * release, and the `services` and `payment_handlers` registries that the
	 * spec makes mandatory for platform profiles too.
	 *
	 * @return string '' when valid, otherwise the reason
	 */
	public static function validateProfile($profile) {
		if (!is_array($profile) || !isset($profile['ucp']) || !is_array($profile['ucp'])) {
			return 'Platform profile has no "ucp" object.';
		}

		$ucp = $profile['ucp'];

		if (!isset($ucp['version']) || !is_string($ucp['version']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ucp['version'])) {
			return 'Platform profile "ucp.version" must be a YYYY-MM-DD release.';
		}
		if (!array_key_exists('services', $ucp) || !is_array($ucp['services'])) {
			return 'Platform profile is missing the "ucp.services" registry.';
		}
		if (!array_key_exists('payment_handlers', $ucp) || !is_array($ucp['payment_handlers'])) {
			return 'Platform profile is missing the "ucp.payment_handlers" registry.';
		}
		if (isset($ucp['capabilities']) && !is_array($ucp['capabilities'])) {
			return 'Platform profile "ucp.capabilities" must be an object.';
		}

		return '';
	}

	/**
	 * Request-time negotiation for one operation.
	 *
	 * @return array array('ok' => true) or a failure as resolvePlatform() returns it
	 */
	public static function negotiate($profile, $capability, $continueUrl) {
		$version = (string)$profile['ucp']['version'];

		// No supported_versions here: this business implements exactly one release.
		if ($version !== self::VERSION) {
			return self::fail(422, 'version_unsupported', 'Version ' . $version . ' is not supported. This business implements version ' . self::VERSION . '.');
		}

		$caps = isset($profile['ucp']['capabilities']) && is_array($profile['ucp']['capabilities']) ? $profile['ucp']['capabilities'] : array();

		if (isset($caps[$capability]) && is_array($caps[$capability])) {
			foreach ($caps[$capability] as $entry) {
				if (is_array($entry) && isset($entry['version']) && (string)$entry['version'] === self::VERSION) {
					return array('ok' => true);
				}
			}
		}

		// Application outcome, not a transport error: HTTP 200 + error envelope.
		return array(
			'ok'      => false,
			'status'  => 200,
			'code'    => 'capabilities_incompatible',
			'message' => 'No compatible version of ' . $capability . ' in the platform profile; this business supports ' . self::VERSION . '.',
			'retry'   => 0,
			'continue' => $continueUrl,
		);
	}

	private static function fail($status, $code, $message, $retry = 0) {
		return array('ok' => false, 'status' => (int)$status, 'code' => (string)$code, 'message' => (string)$message, 'retry' => (int)$retry);
	}

	/**
	 * GET the profile: HTTPS only, the address checked against special-use
	 * ranges and pinned with CURLOPT_RESOLVE, no redirects, bounded time and size.
	 */
	private static function fetchProfile($url, $parts, $allowPrivate) {
		if (!function_exists('curl_init')) {
			return self::fail(424, 'profile_unreachable', 'cURL is not available on this server.');
		}

		$host = strtolower((string)$parts['host']);
		$host = trim($host, '[]');
		$port = isset($parts['port']) ? (int)$parts['port'] : 443;

		$ips = self::resolveHost($host);
		if (!$ips) {
			return self::fail(400, 'invalid_profile_url', 'Platform profile host does not resolve.');
		}

		foreach ($ips as $ip) {
			if (!$allowPrivate && !self::publicIp($ip)) {
				return self::fail(400, 'invalid_profile_url', 'Platform profile host resolves to a special-use address.');
			}
		}

		$ip      = $ips[0];
		$body    = '';
		$headers = array();
		$tooBig  = false;

		$ch = curl_init();
		$opts = array(
			CURLOPT_URL            => $url,
			CURLOPT_HTTPGET        => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS      => 0,
			CURLOPT_CONNECTTIMEOUT => self::FETCH_CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT,
			CURLOPT_HTTPHEADER     => array('Accept: application/json'),
			CURLOPT_USERAGENT      => 'CatCode-Market-Feeds-UCP/' . self::VERSION,
			CURLOPT_SSL_VERIFYPEER => !$allowPrivate,
			CURLOPT_SSL_VERIFYHOST => $allowPrivate ? 0 : 2,
			CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers) {
				$pos = strpos($line, ':');
				if ($pos !== false) {
					$headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
				}

				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION  => function ($handle, $chunk) use (&$body, &$tooBig) {
				if (strlen($body) + strlen($chunk) > Ucp::FETCH_MAX_BYTES) {
					$tooBig = true;

					return 0; // abort the transfer
				}
				$body .= $chunk;

				return strlen($chunk);
			},
		);

		if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
			$opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
		}
		if (defined('CURLOPT_RESOLVE') && filter_var($host, FILTER_VALIDATE_IP) === false) {
			$opts[CURLOPT_RESOLVE] = array($host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip));
		}

		curl_setopt_array($ch, $opts);
		curl_exec($ch);
		$errno  = curl_errno($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($tooBig) {
			return self::fail(422, 'profile_malformed', 'Platform profile exceeds ' . self::FETCH_MAX_BYTES . ' bytes.');
		}
		if ($errno !== 0) {
			return self::fail(424, 'profile_unreachable', 'Platform profile could not be fetched (cURL error ' . $errno . ').');
		}
		if ($status >= 300 && $status < 400) {
			return self::fail(424, 'profile_unreachable', 'Platform profile answered with a redirect (' . $status . '); redirects are not followed.');
		}
		if ($status < 200 || $status >= 300) {
			return self::fail(424, 'profile_unreachable', 'Platform profile answered HTTP ' . $status . '.');
		}

		$profile = json_decode($body, true);
		if (!is_array($profile)) {
			return self::fail(422, 'profile_malformed', 'Platform profile is not valid JSON.');
		}

		return array('ok' => true, 'profile' => $profile, 'ttl' => self::ttlFrom(isset($headers['cache-control']) ? $headers['cache-control'] : ''));
	}

	/** Cache TTL from the origin's Cache-Control, clamped to [60, 3600]. */
	private static function ttlFrom($cacheControl) {
		$ttl = self::CACHE_DEFAULT_TTL;

		if (preg_match('/max-age\s*=\s*(\d+)/i', (string)$cacheControl, $m)) {
			$ttl = (int)$m[1];
		}

		return max(self::CACHE_MIN_TTL, min(self::CACHE_MAX_TTL, $ttl));
	}

	/** @return string[] resolved addresses, IPv4 first */
	private static function resolveHost($host) {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return array($host);
		}

		$ips = @gethostbynamel($host);
		$ips = is_array($ips) ? $ips : array();

		if (!$ips && function_exists('dns_get_record') && defined('DNS_AAAA')) {
			$records = @dns_get_record($host, DNS_AAAA);
			foreach (is_array($records) ? $records : array() as $record) {
				if (!empty($record['ipv6'])) {
					$ips[] = (string)$record['ipv6'];
				}
			}
		}

		return array_values(array_unique($ips));
	}

	/**
	 * False for special-use addresses (RFC 6890): loopback, link-local
	 * (169.254.169.254 included), private, shared CGN, documentation,
	 * benchmarking, multicast and reserved ranges — IPv4 and IPv6, including
	 * IPv4-mapped IPv6.
	 */
	public static function publicIp($ip) {
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return false;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$blocked = array(
				'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
				'192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
				'198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
			);
			$long = ip2long($ip);
			foreach ($blocked as $cidr) {
				list($net, $bits) = explode('/', $cidr);
				$mask = $bits === '0' ? 0 : (~0 << (32 - (int)$bits)) & 0xFFFFFFFF;
				if (($long & $mask) === (ip2long($net) & $mask)) {
					return false;
				}
			}

			return true;
		}

		$bin = @inet_pton($ip);
		if ($bin === false || strlen($bin) !== 16) {
			return false;
		}

		// IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible forms: judge the IPv4 part.
		if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || substr($bin, 0, 12) === str_repeat("\0", 12)) {
			return self::publicIp(inet_ntop(substr($bin, 12)));
		}

		$first = ord($bin[0]);
		$second = ord($bin[1]);

		if ($first === 0xff) {                              // ff00::/8 multicast
			return false;
		}
		if (($first & 0xfe) === 0xfc) {                     // fc00::/7 unique local
			return false;
		}
		if ($first === 0xfe && ($second & 0xc0) === 0x80) { // fe80::/10 link-local
			return false;
		}
		if ($first === 0x20 && $second === 0x01 && ord($bin[2]) === 0x0d && ord($bin[3]) === 0xb8) { // 2001:db8::/32 documentation
			return false;
		}
		if ($first === 0x01 && $second === 0x00 && substr($bin, 2, 6) === str_repeat("\0", 6)) { // 100::/64 discard
			return false;
		}
		if ($first === 0x00 && $second === 0x64 && ord($bin[2]) === 0xff && ord($bin[3]) === 0x9b) { // 64:ff9b::/96 NAT64
			return false;
		}

		return true;
	}

	private static function readCache($file) {
		if (!is_file($file)) {
			return null;
		}

		$raw  = @file_get_contents($file);
		$data = is_string($raw) ? json_decode($raw, true) : null;

		if (!is_array($data) || !isset($data['expires'], $data['result']) || (int)$data['expires'] < time()) {
			return null;
		}

		return $data['result'];
	}

	private static function writeCache($dir, $file, $result, $ttl) {
		if (!self::prepareDir($dir)) {
			return;
		}

		@file_put_contents($file, json_encode(array('expires' => time() + (int)$ttl, 'result' => $result)), LOCK_EX);

		// Fixed-size footprint: every few writes, trim the oldest entries.
		if (mt_rand(1, 10) === 1) {
			$files = glob($dir . 'p_*.json');
			if (is_array($files) && count($files) > self::CACHE_MAX_FILES) {
				usort($files, function ($a, $b) {
					return (int)@filemtime($a) - (int)@filemtime($b);
				});
				foreach (array_slice($files, 0, count($files) - self::CACHE_MAX_FILES) as $old) {
					@unlink($old);
				}
			}
		}
	}

	/**
	 * Fixed-window counter shared by every PHP worker (flock on a file).
	 *
	 * @return bool true while the bucket is under $limit
	 */
	public static function hit($dir, $bucket, $limit, $window) {
		return self::quiet(function () use ($dir, $bucket, $limit, $window) {
			return Ucp::hitNow($dir, $bucket, $limit, $window);
		});
	}

	/** hit() without the warning guard; public only for the closure above. */
	public static function hitNow($dir, $bucket, $limit, $window) {
		if (!self::prepareDir($dir)) {
			return true; // fail open: no writable cache means no limiter, not an outage
		}

		$slot = (int)floor(time() / max(1, (int)$window));
		$file = $dir . 'r_' . preg_replace('/[^a-z0-9_]/i', '', (string)$bucket) . '.cnt';

		$fh = @fopen($file, 'c+');
		if (!$fh) {
			return true;
		}

		@flock($fh, LOCK_EX);
		$raw = stream_get_contents($fh);
		$parts = explode(':', trim((string)$raw));
		$count = (count($parts) === 2 && (int)$parts[0] === $slot) ? (int)$parts[1] : 0;
		$count++;
		ftruncate($fh, 0);
		rewind($fh);
		fwrite($fh, $slot . ':' . $count);
		fflush($fh);
		@flock($fh, LOCK_UN);
		fclose($fh);

		// Stale per-IP buckets are swept now and then.
		if (mt_rand(1, 50) === 1) {
			foreach ((array)glob($dir . 'r_ip_*.cnt') as $old) {
				if (is_string($old) && @filemtime($old) < time() - 3600) {
					@unlink($old);
				}
			}
		}

		return $count <= (int)$limit;
	}

	private static function prepareDir($dir) {
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		if (is_dir($dir) && !is_file($dir . '.htaccess')) {
			@file_put_contents($dir . '.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
			@file_put_contents($dir . 'index.html', '');
		}

		return is_dir($dir) && is_writable($dir);
	}

	/** Remove the discovery cache and rate-limit counters. */
	public static function purge($dir) {
		$dir = rtrim((string)$dir, '/') . '/';

		if (!is_dir($dir)) {
			return;
		}

		self::quiet(function () use ($dir) {
			foreach (array('p_*.json', 'r_*.cnt', '.htaccess', 'index.html') as $pattern) {
				foreach ((array)glob($dir . $pattern) as $file) {
					if (is_string($file) && is_file($file)) {
						unlink($file);
					}
				}
			}
			rmdir($dir);
		});
	}

	/**
	 * Request body as an array, or a failure array. Only JSON objects are
	 * accepted; the size is bounded before decoding.
	 */
	public static function readBody() {
		$raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

		if (strlen($raw) > self::MAX_BODY_BYTES) {
			return self::fail(400, 'request_too_large', 'Request body exceeds ' . self::MAX_BODY_BYTES . ' bytes.');
		}

		$data = json_decode($raw === '' ? '{}' : $raw, true);
		if (!is_array($data) || ($raw !== '' && substr(ltrim($raw), 0, 1) !== '{')) {
			return self::fail(400, 'invalid_request', 'Request body must be a JSON object.');
		}

		return array('ok' => true, 'data' => $data);
	}
}
