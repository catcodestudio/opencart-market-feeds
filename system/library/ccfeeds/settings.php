<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * Settings repository over the OpenCart config for CatCode Market Feeds.
 * Top-level keys live under module_cc_feeds_*. No encrypted secrets here —
 * the only sensitive value is the feed access token, which is a shared URL
 * secret (not a third-party credential), so it is stored in the clear like
 * any other public feed key.
 */
class Settings {
	private $config;
	private ?array $cache = null;

	public function __construct($config) {
		$this->config = $config;
	}

	public static function defaults(): array {
		return [
			'status'               => '0',
			'feed_token'           => '',
			'currency'             => '',   // '' => config_currency
			'language_id'          => '',   // '' => config_language_id
			'price_multiplier'     => '1',
			'price_decimals'       => '2',
			'include_tax'          => '1',  // prices exactly as the storefront shows them
			'use_special'          => '1',  // special price becomes <price>, base becomes <price_old>
			'include_out_of_stock' => '0',
			'stock_threshold'      => '0',
			'availability_in'      => 'true',
			'availability_out'     => 'false',
			'category_ids'         => [],   // empty => all
			'manufacturer_ids'     => [],   // empty => all
			'description_mode'     => 'full', // full | short | none
			'strip_html'           => '0',
			'image_limit'          => '10',
			'sku_field'            => 'sku',  // sku | model
			'export_params'        => '1',   // product attributes as <param>
			'name_suffix'          => '',
			'cache_enabled'        => '0',
			'rozetka_enabled'      => '1',
			'prom_enabled'         => '1',
		];
	}

	/** Keys stored as serialized arrays. */
	public const ARRAY_KEYS = ['category_ids', 'manufacturer_ids'];

	public function all(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}
		$out = self::defaults();
		foreach ($out as $key => $default) {
			$val = $this->config->get('module_cc_feeds_' . $key);
			if ($val === null) {
				continue;
			}
			if (in_array($key, self::ARRAY_KEYS, true)) {
				$out[$key] = is_array($val) ? $val : [];
			} elseif ($val !== '') {
				$out[$key] = $val;
			}
		}
		$this->cache = $out;
		return $out;
	}

	public function get(string $key, $default = null) {
		$all = $this->all();
		return $all[$key] ?? $default;
	}

	/** Passthrough to a raw OpenCart config value (config_name, config_url, …). */
	public function getConfig(string $key) {
		return $this->config->get($key);
	}

	/**
	 * Effective feed currency.
	 *
	 * ⚠ Empty means "whatever the shop sells in". This used to default to UAH,
	 * which is how a shop whose base currency is USD published dollar amounts
	 * labelled `<currencyId>UAH</currencyId>` — the marketplace then read 90 UAH
	 * for a 3 735 UAH phone.
	 */
	public function currencyCode(): string {
		$code = trim((string)$this->get('currency', ''));

		if ($code !== '') {
			return strtoupper($code);
		}

		$base = trim((string)$this->config->get('config_currency'));

		return $base !== '' ? strtoupper($base) : 'UAH';
	}

	/** Directory holding the cached feed files. */
	public static function cacheDir(): string {
		return DIR_STORAGE . 'cc_feeds/';
	}

	/**
	 * Path of a cached feed.
	 *
	 * 🔴 The name carries a digest of the feed token, and that is a security
	 * control, not decoration. DIR_STORAGE is only outside the web root when
	 * the shop was set up that way — plenty of installs keep it under
	 * `system/storage/`, where a plain GET of the cache file hands over the
	 * whole catalogue with no token at all (confirmed on nginx, which serves
	 * the file itself and never consults .htaccess). An unguessable filename
	 * makes the direct path exactly as secret as the feed URL.
	 */
	public static function cacheFile(string $dialect, string $secret): string {
		$dialect = preg_replace('/[^a-z]/', '', $dialect);
		$digest  = substr(sha1('cc_feeds|' . $secret . '|' . $dialect), 0, 16);

		return self::cacheDir() . 'feed_' . $dialect . '_' . $digest . '.xml';
	}

	/**
	 * Create the cache directory and shut the door behind it: `.htaccess` for
	 * Apache, an empty `index.html` against directory listing. Neither is
	 * enough on its own — see cacheFile().
	 */
	public static function prepareCacheDir(): string {
		$dir = self::cacheDir();

		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		if (is_dir($dir) && !is_file($dir . '.htaccess')) {
			@file_put_contents($dir . '.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
		}

		if (is_dir($dir) && !is_file($dir . 'index.html')) {
			@file_put_contents($dir . 'index.html', '');
		}

		return $dir;
	}

	/** Drop every cached feed, whatever token it was written under. */
	public static function purgeCache(array $keep = []): void {
		$files = glob(self::cacheDir() . 'feed_*.xml');

		if (!is_array($files)) {
			return;
		}

		foreach ($files as $file) {
			if (!in_array(basename($file), $keep, true)) {
				@unlink($file);
			}
		}
	}

	/** Effective language id for feed descriptions/names. */
	public function languageId(): int {
		$lid = (int)$this->get('language_id', 0);
		if ($lid > 0) {
			return $lid;
		}
		return (int)($this->config->get('config_language_id') ?: 1);
	}

	public function tokenValid(string $given): bool {
		$token = (string)$this->get('feed_token', '');
		return $token !== '' && hash_equals($token, $given);
	}

	public static function generateToken(): string {
		return bin2hex(random_bytes(16));
	}
}
