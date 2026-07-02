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
			'currency'             => 'UAH',
			'language_id'          => '',   // '' => config_language_id
			'price_multiplier'     => '1',
			'price_decimals'       => '2',
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
