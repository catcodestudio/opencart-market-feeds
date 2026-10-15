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

			// Google Merchant Center / Meta catalogue (RSS 2.0 + g: namespace).
			// Off by default: an update must not start publishing a new feed.
			'google_enabled'       => '0',
			'meta_enabled'         => '0',
			'size_option_ids'      => [],   // OpenCart options whose values are sizes
			'color_option_ids'     => [],   // OpenCart options whose values are colours
			'merchant_brand'       => '',   // brand when the product has no manufacturer
			'google_category'      => '',   // google_product_category, id or path
			'merchant_mpn'         => 'sku', // sku | model | none

			// Real dispatch time, e.g. "2-6" working days. '' => not published.
			'handling_time'        => '',
			'handling_attribute_id' => '0', // attribute that overrides it per product

			// Google product data specification of 14.04.2026. All empty by
			// default, so an update publishes exactly the feed the shop had
			// until the merchant fills these in.
			'handling_business_days'   => '', // M-F / M-S / M;W;F — days orders are packed
			'handling_cutoff_time'     => '', // "1500" — daily order deadline
			'handling_cutoff_timezone' => '', // IANA name; '' => the shop's own
			'min_order_value'          => '', // minimum_order_value, feed currency
			'merchant_country'         => '', // ISO 3166-1 alpha-2; '' => shop country

			// AI agents (1.4.0). Everything off/empty after the update, so an
			// existing shop publishes nothing new until the owner switches it on.
			'openai_enabled'           => '0',  // OpenAI Product Feed (jsonl.gz)
			'ai_seller_name'           => '',   // seller_name; '' => store name
			'ai_accepts_returns'       => '',   // '' (unspecified) | yes | no
			'ai_return_days'           => '',   // return window, days
			'ai_return_policy_url'     => '',   // '' => the store's Return Terms page
			'ai_privacy_info_id'       => '0',  // information page; 0 => the GDPR page, if set
			'ai_terms_info_id'         => '0',  // information page; 0 => the Checkout Terms page
			'llms_enabled'             => '0',  // /llms.txt
			'llms_products'            => '50', // how many products /llms.txt lists
			'llms_summary'             => '',   // blockquote; '' => store meta description
			'ucp_enabled'              => '0',  // /.well-known/ucp + UCP catalog REST
		];
	}

	/** Google's business-day letters, in week order. */
	public const DAY_LETTERS = ['M', 'T', 'W', 'R', 'F', 'S', 'U'];

	/**
	 * Every spelling Google accepts, plus the short Ukrainian/Russian names a
	 * shop owner types without thinking.
	 *
	 * ⚠ The single letters are Google's, not English: T is Tuesday, R is
	 * Thursday, S is Saturday, U is Sunday.
	 */
	private const DAY_ALIASES = [
		'm' => 'M', 'mon' => 'M', 'monday' => 'M', 'пн' => 'M',
		't' => 'T', 'tu' => 'T', 'tue' => 'T', 'tues' => 'T', 'tuesday' => 'T', 'вт' => 'T',
		'w' => 'W', 'we' => 'W', 'wed' => 'W', 'wednesday' => 'W', 'ср' => 'W',
		'r' => 'R', 'th' => 'R', 'thu' => 'R', 'thur' => 'R', 'thurs' => 'R', 'thursday' => 'R', 'чт' => 'R',
		'f' => 'F', 'fr' => 'F', 'fri' => 'F', 'friday' => 'F', 'пт' => 'F',
		's' => 'S', 'sa' => 'S', 'sat' => 'S', 'saturday' => 'S', 'сб' => 'S',
		'u' => 'U', 'su' => 'U', 'sun' => 'U', 'sunday' => 'U', 'нд' => 'U',
	];

	/**
	 * Cutoff time as the four digits Google wants: "1530", "0900", "2359".
	 *
	 * Accepts what a person types — "15:30", "15.30", "9:00", "1530". Anything
	 * that is not a real time of day comes back empty, and empty means "do not
	 * publish the attribute" rather than "midnight": a wrong cutoff is worse
	 * than none, because Google then promises a delivery date the shop misses.
	 */
	public static function normalizeCutoff(string $text): string {
		$digits = (string)preg_replace('/\D+/', '', $text);

		if (strlen($digits) === 3) {
			$digits = '0' . $digits;
		}
		if (strlen($digits) !== 4) {
			return '';
		}

		$hours   = (int)substr($digits, 0, 2);
		$minutes = (int)substr($digits, 2, 2);

		return ($hours <= 23 && $minutes <= 59) ? $digits : '';
	}

	/**
	 * Fulfilment days in Google's own notation: "M-F", "M-S", "M;W;R;F".
	 *
	 * Understands ranges ("Mon-Fri", "Пн-Сб"), lists ("M;W;F", "пн, ср"), the
	 * packed form ("MWRFS") and the full English names. An unknown word makes
	 * the whole value invalid: half a week is a worse promise than the Mon–Fri
	 * Google assumes on its own.
	 */
	public static function normalizeBusinessDays(string $text): string {
		$text = trim((string)preg_replace('/[–—]/u', '-', $text));
		if ($text === '') {
			return '';
		}
		// A leading "UA:" is dropped: the setting is shop-wide, and a bare value
		// already applies to every country.
		$text = (string)preg_replace('/^[A-Za-z]{2}\s*:\s*/', '', $text);

		$days = [];

		foreach (preg_split('/[;,\s]+/u', $text) ?: [] as $chunk) {
			$chunk = trim((string)$chunk);
			if ($chunk === '') {
				continue;
			}

			if (strpos($chunk, '-') !== false) {
				$edges = explode('-', $chunk);
				if (count($edges) !== 2) {
					return '';
				}
				$from = self::dayLetter($edges[0]);
				$to   = self::dayLetter($edges[1]);
				if ($from === '' || $to === '') {
					return '';
				}
				$start = (int)array_search($from, self::DAY_LETTERS, true);
				$end   = (int)array_search($to, self::DAY_LETTERS, true);
				if ($end < $start) {
					return '';
				}
				for ($i = $start; $i <= $end; $i++) {
					$days[] = self::DAY_LETTERS[$i];
				}
				continue;
			}

			$letter = self::dayLetter($chunk);
			if ($letter !== '') {
				$days[] = $letter;
				continue;
			}

			$packed = strtoupper($chunk);
			if (!preg_match('/^[MTWRFSU]+$/', $packed)) {
				return '';
			}
			foreach (str_split($packed) as $one) {
				$days[] = $one;
			}
		}

		$days = array_values(array_intersect(self::DAY_LETTERS, $days));
		if (!$days) {
			return '';
		}

		// Google asks for a range whenever the days run without a gap.
		$first = (int)array_search($days[0], self::DAY_LETTERS, true);
		$last  = (int)array_search($days[count($days) - 1], self::DAY_LETTERS, true);
		if (count($days) > 2 && count($days) === ($last - $first + 1)) {
			return $days[0] . '-' . $days[count($days) - 1];
		}

		return implode(';', $days);
	}

	/** One day name/letter as Google's letter, or '' when it is not a day. */
	private static function dayLetter(string $chunk): string {
		$key = rtrim(trim($chunk), '.');
		$key = function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);

		return self::DAY_ALIASES[$key] ?? '';
	}

	/** True for a real zone name ("Europe/Kyiv", "UTC"), false for an offset. */
	public static function isIanaZone(string $zone): bool {
		return $zone !== '' && in_array($zone, timezone_identifiers_list(), true);
	}

	/**
	 * IANA timezone for the cutoff.
	 *
	 * ⚠ The PHP server zone is deliberately NOT used as a fallback. When
	 * `date.timezone` is missing from php.ini — the usual state on shared
	 * hosting — `date_default_timezone_get()` answers "UTC" although nobody
	 * ever chose it, and publishing that moves a Kyiv shop's cutoff three hours
	 * earlier. Sending nothing is safe: Google then reads the cutoff in the
	 * buyer's own timezone.
	 */
	public function cutoffTimezone(): string {
		$explicit = trim((string)$this->get('handling_cutoff_timezone', ''));
		if ($explicit !== '') {
			return self::isIanaZone($explicit) ? $explicit : '';
		}

		$shop = trim((string)$this->getConfig('config_timezone'));

		return self::isIanaZone($shop) ? $shop : '';
	}

	/** Minimum order value in feed currency; 0 = the shop has none. */
	public function minOrderValue(): float {
		$raw = str_replace(',', '.', trim((string)$this->get('min_order_value', '')));
		$sum = is_numeric($raw) ? (float)$raw : 0.0;

		return $sum > 0 ? $sum : 0.0;
	}

	/** Keys stored as serialized arrays. */
	public const ARRAY_KEYS = ['category_ids', 'manufacturer_ids', 'size_option_ids', 'color_option_ids'];

	/**
	 * Parse a dispatch time written by a person: "3", "2-6", "2–6 днів", "1 - 3".
	 *
	 * @return array{0:int,1:int}|null [min, max] working days, or null when the
	 *                                 text holds no number at all.
	 */
	public static function parseHandling(string $text): ?array {
		if (!preg_match('/(\d{1,3})(?:\s*(?:-|–|—|\.\.)\s*(\d{1,3}))?/u', $text, $m)) {
			return null;
		}

		$min = (int)$m[1];
		$max = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $min;

		if ($max < $min) {
			[$min, $max] = [$max, $min];
		}

		return [$min, $max];
	}

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

		return self::cacheDir() . 'feed_' . $dialect . '_' . $digest . ($dialect === 'openai' ? '.jsonl.gz' : '.xml');
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
		$files = array_merge((array)glob(self::cacheDir() . 'feed_*.xml'), (array)glob(self::cacheDir() . 'feed_*.jsonl.gz'));

		foreach ($files as $file) {
			if (is_string($file) && !in_array(basename($file), $keep, true)) {
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
