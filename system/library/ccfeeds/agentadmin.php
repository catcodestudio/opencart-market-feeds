<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * Admin side of the AI-agent endpoints, shared by the OpenCart 4 / 3 / 2.3
 * builds: field normalisation, the web-server rewrite snippets and the
 * "Check" button that requests the public URLs the way an agent would.
 *
 * PHP 5.6+ syntax.
 */
class AgentAdmin {

	/**
	 * Normalise the AI fields of a settings POST in place.
	 *
	 * @param array  $data   settings about to be saved
	 * @param string $prefix setting key prefix (module_cc_feeds_ / feed_cc_feeds_ / cc_feeds_)
	 */
	public static function normalize(&$data, $prefix) {
		$accepts = isset($data[$prefix . 'ai_accepts_returns']) ? (string)$data[$prefix . 'ai_accepts_returns'] : '';
		$data[$prefix . 'ai_accepts_returns'] = in_array($accepts, array('yes', 'no'), true) ? $accepts : '';

		$days = isset($data[$prefix . 'ai_return_days']) ? (int)$data[$prefix . 'ai_return_days'] : 0;
		$data[$prefix . 'ai_return_days'] = ($data[$prefix . 'ai_accepts_returns'] === 'yes' && $days > 0 && $days <= 3650) ? (string)$days : '';

		$url = isset($data[$prefix . 'ai_return_policy_url']) ? trim((string)$data[$prefix . 'ai_return_policy_url']) : '';
		$data[$prefix . 'ai_return_policy_url'] = preg_match('~^https?://[^\s<>"]+$~i', $url) ? $url : '';

		foreach (array('ai_privacy_info_id', 'ai_terms_info_id') as $key) {
			$data[$prefix . $key] = (string)max(0, isset($data[$prefix . $key]) ? (int)$data[$prefix . $key] : 0);
		}

		$count = isset($data[$prefix . 'llms_products']) ? (int)$data[$prefix . 'llms_products'] : 50;
		$data[$prefix . 'llms_products'] = (string)max(0, min(500, $count));

		$summary = isset($data[$prefix . 'llms_summary']) ? (string)$data[$prefix . 'llms_summary'] : '';
		$summary = trim((string)preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($summary, ENT_QUOTES, 'UTF-8'))));
		$data[$prefix . 'llms_summary'] = function_exists('mb_substr') ? mb_substr($summary, 0, 500, 'UTF-8') : substr($summary, 0, 500);

		$seller = isset($data[$prefix . 'ai_seller_name']) ? (string)$data[$prefix . 'ai_seller_name'] : '';
		$seller = trim((string)preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($seller, ENT_QUOTES, 'UTF-8'))));
		$data[$prefix . 'ai_seller_name'] = function_exists('mb_substr') ? mb_substr($seller, 0, 200, 'UTF-8') : substr($seller, 0, 200);

		foreach (array('openai_enabled', 'llms_enabled', 'ucp_enabled') as $key) {
			$data[$prefix . $key] = (isset($data[$prefix . $key]) && (string)$data[$prefix . $key] === '1') ? '1' : '0';
		}
	}

	/** Path part of the store URL with slashes on both ends: "/" or "/shop/". */
	public static function basePath($storeUrl) {
		$path = trim((string)parse_url((string)$storeUrl, PHP_URL_PATH), '/');

		return $path === '' ? '/' : '/' . $path . '/';
	}

	/**
	 * Rewrite rules for the pretty paths.
	 *
	 * @param string $storeUrl
	 * @param array  $routes llms, ucp, rest — index.php query strings; rest ends with "op="
	 * @return array apache, nginx
	 */
	public static function snippets($storeUrl, $routes) {
		$base   = self::basePath($storeUrl);
		$prefix = Ucp::REST_PREFIX;

		$apache = "# CatCode Market Feeds — AI agents. Place right after \"RewriteBase " . $base . "\".\n"
			. "RewriteRule ^llms\\.txt$ index.php?" . $routes['llms'] . " [L]\n"
			. "RewriteRule ^\\.well-known/ucp$ index.php?" . $routes['ucp'] . " [L]\n"
			. "RewriteRule ^" . $prefix . "/catalog/(search|lookup|product)$ index.php?" . $routes['rest'] . "$1 [L,QSA]\n"
			. "# And in the <FilesMatch> line that denies *.txt, let llms.txt through:\n"
			. "#   (?<!robots)\\.txt   ->   (?<!robots|llms)\\.txt\n";

		$nginx = "# CatCode Market Feeds — AI agents (inside the server { } block of the shop)\n"
			. "location = " . $base . "llms.txt { rewrite ^ " . $base . "index.php?" . $routes['llms'] . " last; }\n"
			. "location = /.well-known/ucp { rewrite ^ " . $base . "index.php?" . $routes['ucp'] . " last; }\n"
			. "location ~ ^" . $base . $prefix . "/catalog/(search|lookup|product)$ { rewrite ^" . $base . $prefix . "/catalog/(.*)$ " . $base . "index.php?" . $routes['rest'] . "$1 last; }\n";

		return array('apache' => $apache, 'nginx' => $nginx);
	}

	/**
	 * Request the public URLs the way an agent would and report what came
	 * back. Uses the settings already saved.
	 *
	 * @return array[] array(key, url, ok, status, note)
	 */
	public static function check($storeUrl, $llmsOn, $ucpOn) {
		$out    = array();
		$origin = Ucp::origin($storeUrl);
		$base   = rtrim((string)$storeUrl, '/') . '/';

		if ($llmsOn) {
			$r = self::request('GET', $base . 'llms.txt');
			$ok = $r['status'] === 200 && strpos(ltrim($r['body']), '# ') === 0;
			$out[] = array('key' => 'llms', 'url' => $base . 'llms.txt', 'ok' => $ok && $r['tls'] === '', 'status' => $r['status'], 'note' => $ok ? $r['tls'] : self::why($r, 'text/plain, starting with "# "'));
		}

		if ($ucpOn) {
			if ($origin === '') {
				$out[] = array('key' => 'ucp', 'url' => '', 'ok' => false, 'status' => 0, 'note' => 'https');

				return $out;
			}

			$url  = $origin . '/.well-known/ucp';
			$r    = self::request('GET', $url);
			$json = json_decode($r['body'], true);
			$cc   = isset($r['headers']['cache-control']) ? strtolower($r['headers']['cache-control']) : '';
			$ok   = $r['status'] === 200 && is_array($json) && isset($json['ucp']['version']) && $json['ucp']['version'] === Ucp::VERSION
				&& strpos($cc, 'public') !== false && strpos($cc, 'no-cache') === false && strpos($cc, 'no-store') === false;
			$out[] = array('key' => 'ucp', 'url' => $url, 'ok' => $ok && $r['tls'] === '', 'status' => $r['status'], 'note' => $ok ? $r['tls'] : self::why($r, 'JSON with ucp.version ' . Ucp::VERSION . ' and Cache-Control: public'));

			// Without UCP-Agent a working endpoint must refuse with its own error code.
			$url  = Ucp::endpoint($storeUrl) . '/catalog/search';
			$r    = self::request('POST', $url, '{"query":"test"}');
			$json = json_decode($r['body'], true);
			$ok   = $r['status'] === 400 && is_array($json) && isset($json['messages'][0]['code']) && $json['messages'][0]['code'] === 'invalid_profile_url';
			$out[] = array('key' => 'rest', 'url' => $url, 'ok' => $ok && $r['tls'] === '', 'status' => $r['status'], 'note' => $ok ? $r['tls'] : self::why($r, 'HTTP 400 invalid_profile_url (request without UCP-Agent)'));
		}

		return $out;
	}

	private static function why($r, $expected) {
		if ($r['error'] !== '') {
			return $r['error'];
		}
		if ($r['tls'] !== '') {
			return $r['tls'];
		}

		$type = isset($r['headers']['content-type']) ? $r['headers']['content-type'] : '?';
		$location = isset($r['headers']['location']) ? ' → ' . $r['headers']['location'] : '';

		return 'HTTP ' . $r['status'] . $location . ', ' . $type . '; expected ' . $expected;
	}

	/** One HTTP request without redirects; TLS problems reported, not fatal. */
	private static function request($method, $url, $body = null) {
		$out = array('status' => 0, 'body' => '', 'headers' => array(), 'error' => '', 'tls' => '');

		if (!function_exists('curl_init')) {
			$out['error'] = 'cURL is not available on this server';

			return $out;
		}

		foreach (array(true, false) as $verify) {
			$headers = array();
			$ch = curl_init();
			$opts = array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_SSL_VERIFYPEER => $verify,
				CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
				CURLOPT_USERAGENT      => 'CatCode-Market-Feeds-Check',
				CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers) {
					$pos = strpos($line, ':');
					if ($pos !== false) {
						$headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
					}

					return strlen($line);
				},
			);
			if ($method === 'POST') {
				$opts[CURLOPT_POST]       = true;
				$opts[CURLOPT_POSTFIELDS] = (string)$body;
				$opts[CURLOPT_HTTPHEADER] = array('Content-Type: application/json', 'Accept: application/json');
			}
			curl_setopt_array($ch, $opts);
			$response = curl_exec($ch);
			$errno    = curl_errno($ch);
			$error    = curl_error($ch);
			$status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			// 35/51/58/60 and friends: the certificate is not trusted. Try once
			// more without verification to still check the routing, and say so.
			if ($verify && in_array($errno, array(35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91), true)) {
				$out['tls'] = 'TLS: ' . $error;
				continue;
			}

			if ($errno !== 0) {
				$out['error'] = 'cURL ' . $errno . ': ' . $error;
			}
			$out['status']  = $status;
			$out['body']    = is_string($response) ? $response : '';
			$out['headers'] = $headers;
			break;
		}

		return $out;
	}

	/** Last OpenAI feed run, as streamOpenAi() recorded it. */
	public static function stats($cacheDir) {
		$file = rtrim((string)$cacheDir, '/') . '/openai_stats.json';
		$raw  = is_file($file) ? Ucp::quiet(function () use ($file) {
			return file_get_contents($file);
		}) : null;
		$data = is_string($raw) ? json_decode($raw, true) : null;

		return is_array($data) && isset($data['time'], $data['rows']) ? $data : null;
	}
}
