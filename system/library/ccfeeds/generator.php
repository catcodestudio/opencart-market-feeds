<?php
namespace Opencart\System\Library\Ccfeeds;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/merchant.php';

/**
 * Streaming feed generator: YML for Rozetka and Prom.ua, RSS 2.0 (g: namespace)
 * for Google Merchant Center and the Meta catalogue.
 *
 * Output is written incrementally through XMLWriter straight to php://output
 * (or a file), so a catalog of tens of thousands of offers never has to sit in
 * memory at once. Products are pulled in pages and their images / attributes /
 * specials are batch-loaded per page to keep the query count bounded.
 */
class Generator {
	use MerchantFeed;

	private $db;
	private Settings $settings;
	private int $languageId;
	private string $imageBase;
	private int $customerGroupId;

	/** Cached answer of "is this the 4.1 specials schema?" — see batchSpecials(). */
	private ?bool $discountSpecialColumn = null;

	/** Lazily loaded tax rules, keyed by tax_class_id. See taxRates(). */
	private ?array $taxRates = null;

	/** Lazily loaded currency code => exchange value. */
	private ?array $currencyValues = null;

	/** @var callable|null fn(int $productId): string — storefront product URL. */
	private $productLink = null;

	/**
	 * Per-dialect differences. `format` rss = Google/Meta item feed; those carry
	 * product links, which only the storefront can build with SEO URLs.
	 */
	public const DIALECTS = [
		'rozetka' => ['format' => 'yml', 'qty_tag' => 'stock_quantity'],
		'prom'    => ['format' => 'yml', 'qty_tag' => 'quantity_in_stock'],
		'google'  => ['format' => 'rss', 'qty_tag' => ''],
		'meta'    => ['format' => 'rss', 'qty_tag' => ''],
	];

	/**
	 * Prom.ua «Готово до відправки» (offer attribute in_stock="true") is only
	 * claimed when the product really leaves within this many working days.
	 */
	private const PROM_READY_MAX_DAYS = 1;

	public function __construct($db, Settings $settings, int $languageId, string $imageBase, int $customerGroupId = 0) {
		$this->db              = $db;
		$this->settings        = $settings;
		$this->languageId      = $languageId;
		$this->imageBase       = rtrim($imageBase, '/') . '/';
		$this->customerGroupId = $customerGroupId;
	}

	/** Storefront link builder; without it product links fall back to index.php?route=… */
	public function setProductLink(callable $builder): void {
		$this->productLink = $builder;
	}

	/**
	 * Product URL exactly as the storefront prints it — SEO keyword included
	 * when SEO URLs are on. Google checks that the landing page matches.
	 * Used by the feed and cron controllers.
	 */
	public static function productLinkBuilder($url, string $languageCode): callable {
		return static function (int $productId) use ($url, $languageCode): string {
			$args = ($languageCode !== '' ? 'language=' . $languageCode . '&' : '') . 'product_id=' . $productId;

			return str_replace('&amp;', '&', (string)$url->link('product/product', $args));
		};
	}

	public static function isLinkDialect(string $dialect): bool {
		return (self::DIALECTS[$dialect]['format'] ?? '') === 'rss';
	}

	/**
	 * Stream a feed for the given dialect to $target ('php://output' by
	 * default, or a filesystem path for cache generation).
	 * Returns the number of offers written.
	 */
	public function stream(string $dialect, string $target = 'php://output'): int {
		$dialect = isset(self::DIALECTS[$dialect]) ? $dialect : 'prom';

		if (self::isLinkDialect($dialect)) {
			return $this->streamMerchant($dialect, $target);
		}

		$qtyTag  = self::DIALECTS[$dialect]['qty_tag'];

		$w = new \XMLWriter();
		$w->openUri($target);
		$w->setIndent(true);
		$w->setIndentString('  ');
		$w->startDocument('1.0', 'UTF-8');

		$w->startElement('yml_catalog');
		$w->writeAttribute('date', date('Y-m-d H:i'));
		$w->startElement('shop');

		$w->writeElement('name', (string)($this->cfg('config_name') ?: 'Shop'));
		$w->writeElement('company', (string)($this->cfg('config_owner') ?: $this->cfg('config_name')));
		$w->writeElement('url', rtrim((string)$this->cfg('config_url'), '/') . '/');

		// Currencies.
		$currency = $this->settings->currencyCode();
		$w->startElement('currencies');
		$w->startElement('currency');
		$w->writeAttribute('id', $currency);
		$w->writeAttribute('rate', '1');
		$w->endElement();
		$w->endElement();

		// Categories.
		$this->writeCategories($w);

		// Offers.
		$w->startElement('offers');
		$count = $this->writeOffers($w, $currency, $qtyTag, $dialect);
		$w->endElement(); // offers

		$w->endElement(); // shop
		$w->endElement(); // yml_catalog
		$w->endDocument();
		$w->flush();

		return $count;
	}

	private function cfg(string $key) {
		// Config is reachable via settings' config; expose a thin accessor.
		return $this->settings->getConfig($key);
	}

	private function writeCategories(\XMLWriter $w): void {
		$rows = $this->db->query("SELECT c.category_id, c.parent_id, cd.name
			FROM `" . DB_PREFIX . "category` c
			LEFT JOIN `" . DB_PREFIX . "category_description` cd
				ON cd.category_id = c.category_id AND cd.language_id = " . (int)$this->languageId . "
			WHERE c.status = 1
			ORDER BY c.parent_id ASC, c.sort_order ASC")->rows;

		$w->startElement('categories');
		foreach ($rows as $r) {
			$name = trim((string)$r['name']);
			if ($name === '') {
				continue;
			}
			$w->startElement('category');
			$w->writeAttribute('id', (string)(int)$r['category_id']);
			if ((int)$r['parent_id'] > 0) {
				$w->writeAttribute('parentId', (string)(int)$r['parent_id']);
			}
			$w->text($name);
			$w->endElement();
		}
		$w->endElement();
	}

	/**
	 * Everything that turns a raw `product.price` into the storefront price,
	 * resolved once per feed run.
	 *
	 * @return array{applyTax:bool,rate:float,multiplier:float,decimals:int}
	 */
	private function pricing(string $currency): array {
		$multiplier = (float)$this->settings->get('price_multiplier', 1) ?: 1.0;
		$decimals   = (int)$this->settings->get('price_decimals', 2);

		// ⚠ Tax is applied only when the shop itself shows prices with tax: a
		// feed price that disagrees with the product page is grounds for a
		// marketplace to suspend the whole price list.
		$applyTax = $this->settings->get('include_tax', '1') === '1' && (string)$this->cfg('config_tax') === '1';

		// The catalogue is priced in the shop's base currency; the feed may be
		// published in another one.
		$rate         = 1.0;
		$baseCurrency = $this->baseCurrencyCode();

		if ($baseCurrency !== '' && strtoupper($currency) !== $baseCurrency) {
			$target = $this->currencyValue($currency);
			$origin = $this->currencyValue($baseCurrency);

			if ($target > 0 && $origin > 0) {
				$rate = $target / $origin;
			}
		}

		return ['applyTax' => $applyTax, 'rate' => $rate, 'multiplier' => $multiplier, 'decimals' => $decimals];
	}

	/**
	 * The currency prices are STORED in — the one whose rate is 1.
	 *
	 * ⚠ Not simply config_currency: that is the currency the shop DISPLAYS by
	 * default. A shop showing UAH while keeping prices in USD (UAH = 48) got
	 * dollar amounts labelled UAH, 48 times too cheap.
	 */
	private function baseCurrencyCode(): string {
		$display = strtoupper((string)$this->cfg('config_currency'));

		if ($display !== '' && abs($this->currencyValue($display) - 1.0) < 1e-7) {
			return $display;
		}

		foreach ($this->currencyValues ?? [] as $code => $value) {
			if (abs($value - 1.0) < 1e-7) {
				return (string)$code;
			}
		}

		return $display;
	}

	/** One page of products that pass the shared WHERE. */
	private function fetchPage(string $whereSql, int $offset, int $pageSize): array {
		return $this->db->query("SELECT p.product_id, p.model, p.sku, p.ean, p.quantity, p.price,
				p.image, p.manufacturer_id, p.tax_class_id, m.name AS vendor,
				pd.name, pd.description,
				(SELECT p2c.category_id FROM `" . DB_PREFIX . "product_to_category` p2c
					INNER JOIN `" . DB_PREFIX . "category` cc ON cc.category_id = p2c.category_id AND cc.status = 1
					WHERE p2c.product_id = p.product_id
					ORDER BY (SELECT COUNT(*) FROM `" . DB_PREFIX . "category_path` cp WHERE cp.category_id = p2c.category_id) DESC
					LIMIT 1) AS category_id
			FROM `" . DB_PREFIX . "product` p
			LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON m.manufacturer_id = p.manufacturer_id
			LEFT JOIN `" . DB_PREFIX . "product_description` pd
				ON pd.product_id = p.product_id AND pd.language_id = " . (int)$this->languageId . "
			WHERE " . $whereSql . "
			ORDER BY p.product_id ASC
			LIMIT " . (int)$offset . ", " . (int)$pageSize)->rows;
	}

	/** Regular and special storefront price of a product (special = null when none). */
	private function productPrices(array $row, array $specials, array $pc, float $optionDelta = 0.0): array {
		$pid        = (int)$row['product_id'];
		$rawBase    = (float)$row['price'];
		$taxClassId = (int)$row['tax_class_id'];

		// Options are added on top of the product (or special) price, exactly as
		// the cart does, and taxed together with it.
		$regular = $this->retail($rawBase + $optionDelta, $taxClassId, $pc['applyTax'], $pc['rate'], $pc['multiplier'], $pc['decimals']);
		$special = null;

		if (isset($specials[$pid])) {
			$eff = $this->effectiveSpecial($rawBase, $specials[$pid]['type'], (float)$specials[$pid]['price']);
			if ($eff !== null) {
				$special = $this->retail($eff + $optionDelta, $taxClassId, $pc['applyTax'], $pc['rate'], $pc['multiplier'], $pc['decimals']);
			}
		}

		if ($special !== null && $special <= 0) {
			$special = null;
		}

		return [$regular, $special];
	}

	private function writeOffers(\XMLWriter $w, string $currency, string $qtyTag, string $dialect): int {
		$decimals     = (int)$this->settings->get('price_decimals', 2);
		$useSpecial   = $this->settings->get('use_special', '1') === '1';
		$inclOut      = $this->settings->get('include_out_of_stock', '0') === '1';
		$stockThresh  = (int)$this->settings->get('stock_threshold', 0);
		$availIn      = (string)$this->settings->get('availability_in', 'true');
		$availOut     = (string)$this->settings->get('availability_out', 'false');
		$descMode     = (string)$this->settings->get('description_mode', 'full');
		$stripHtml    = $this->settings->get('strip_html', '0') === '1';
		$imageLimit   = max(1, (int)$this->settings->get('image_limit', 10));
		$skuField     = (string)$this->settings->get('sku_field', 'sku');
		$exportParams = $this->settings->get('export_params', '1') === '1';
		$nameSuffix   = (string)$this->settings->get('name_suffix', '');
		$pc           = $this->pricing($currency);

		$whereSql = $this->productWhere();

		$pageSize = 500;
		$offset   = 0;
		$written  = 0;

		while (true) {
			$rows = $this->fetchPage($whereSql, $offset, $pageSize);

			if (!$rows) {
				break;
			}

			$ids = array_map(static fn($r) => (int)$r['product_id'], $rows);
			$images   = $this->batchImages($ids, $imageLimit);
			$specials = $useSpecial ? $this->batchSpecials($ids) : [];
			$params   = $exportParams ? $this->batchParams($ids) : [];
			$handling = $dialect === 'prom' ? $this->batchHandling($ids) : [];

			foreach ($rows as $r) {
				$pid   = (int)$r['product_id'];
				$qty   = (int)$r['quantity'];
				$inStk = $qty > $stockThresh;
				if (!$inStk && !$inclOut) {
					continue;
				}

				[$regular, $special] = $this->productPrices($r, $specials, $pc);
				$price   = $special ?? $regular;
				if ($price <= 0) {
					continue;
				}

				$w->startElement('offer');
				$w->writeAttribute('id', (string)$pid);
				$w->writeAttribute('available', $inStk ? $availIn : $availOut);

				// «Готово до відправки» only for what truly ships at once; a product
				// that is "in stock" but leaves in 2–6 days keeps the plain status.
				$days = $handling[$pid] ?? null;
				if ($dialect === 'prom' && $inStk && $days !== null && $days[1] <= self::PROM_READY_MAX_DAYS) {
					$w->writeAttribute('in_stock', 'true');
				}

				$name = trim((string)$r['name']);
				if ($nameSuffix !== '') {
					$name .= ' ' . $nameSuffix;
				}
				$w->writeElement('name', $name);

				$w->writeElement('price', $this->num($price, $decimals));
				if ($special !== null && $special > 0 && $regular > $special) {
					$w->writeElement('price_old', $this->num($regular, $decimals));
				}
				$w->writeElement('currencyId', $currency);

				if ((int)$r['category_id'] > 0) {
					$w->writeElement('categoryId', (string)(int)$r['category_id']);
				}

				foreach ($images[$pid] ?? [] as $img) {
					$w->writeElement('picture', $img);
				}

				$vendor = trim((string)$r['vendor']);
				if ($vendor !== '') {
					$w->writeElement('vendor', $vendor);
				}

				$code = trim((string)($skuField === 'model' ? $r['model'] : ($r['sku'] !== '' ? $r['sku'] : $r['model'])));
				if ($code !== '') {
					$w->writeElement('vendorCode', $code);
				}
				if (trim((string)$r['ean']) !== '') {
					$w->writeElement('barcode', trim((string)$r['ean']));
				}

				$w->writeElement($qtyTag, (string)max(0, $qty));

				if ($descMode !== 'none') {
					$desc = html_entity_decode((string)$r['description'], ENT_QUOTES, 'UTF-8');
					if ($stripHtml) {
						$desc = trim(preg_replace('/\s+/u', ' ', strip_tags($desc)));
					}
					if ($descMode === 'short') {
						$desc = mb_substr($desc, 0, 500);
					}
					if ($desc !== '') {
						$w->startElement('description');
						$w->writeCdata($desc);
						$w->endElement();
					}
				}

				foreach ($params[$pid] ?? [] as $p) {
					$w->startElement('param');
					$w->writeAttribute('name', $p['name']);
					$w->text($p['value']);
					$w->endElement();
				}

				$w->endElement(); // offer
				$written++;
			}

			$w->flush();

			if (count($rows) < $pageSize) {
				break;
			}
			$offset += $pageSize;
		}

		return $written;
	}

	private function num(float $v, int $decimals): string {
		return number_format($v, $decimals, '.', '');
	}

	/**
	 * WHERE fragment shared by the feed and by the admin "test" counter.
	 *
	 * ⚠ The stock rule belongs HERE, not only in the write loop. The counter
	 * used to run a copy of the query without it, so the settings page promised
	 * 19 offers while the feed contained 17 — and the merchant had no way to
	 * tell which number the marketplace would see.
	 */
	public function productWhere(): string {
		$where = ["p.status = 1", "p.date_available <= NOW()"];

		if ($this->settings->get('include_out_of_stock', '0') !== '1') {
			$where[] = "p.quantity > " . (int)$this->settings->get('stock_threshold', 0);
		}

		$manFilter = array_filter(array_map('intval', (array)$this->settings->get('manufacturer_ids', [])));
		$catFilter = array_filter(array_map('intval', (array)$this->settings->get('category_ids', [])));

		if ($manFilter) {
			$where[] = "p.manufacturer_id IN (" . implode(',', $manFilter) . ")";
		}
		if ($catFilter) {
			$where[] = "p.product_id IN (SELECT product_id FROM `" . DB_PREFIX . "product_to_category` WHERE category_id IN (" . implode(',', $catFilter) . "))";
		}

		return implode(' AND ', $where);
	}

	/**
	 * Tax rules for the store's own address, exactly as
	 * catalog/controller/startup/startup.php sets them up for a visitor with no
	 * address of their own: `based` follows `config_tax_default`, the geo zone
	 * is matched against `config_country_id` / `config_zone_id`, and the rates
	 * are filtered by the default customer group.
	 *
	 * ⚠ Loaded with plain SQL rather than the Cart\Tax library on purpose: that
	 * library is registered by the STOREFRONT startup only, so an admin
	 * "Rebuild cache" click would silently produce a tax-free feed while the
	 * cron job produced a taxed one — two different prices for the same shop.
	 */
	private function taxRates(): array {
		if ($this->taxRates !== null) {
			return $this->taxRates;
		}

		$this->taxRates = [];

		$based = (string)$this->cfg('config_tax_default');
		if (!in_array($based, ['shipping', 'payment', 'store'], true)) {
			$based = 'shipping';
		}

		$rows = $this->db->query("SELECT tr1.tax_class_id, tr2.tax_rate_id, tr2.rate, tr2.type
			FROM `" . DB_PREFIX . "tax_rule` tr1
			LEFT JOIN `" . DB_PREFIX . "tax_rate` tr2 ON (tr1.tax_rate_id = tr2.tax_rate_id)
			INNER JOIN `" . DB_PREFIX . "tax_rate_to_customer_group` tr2cg ON (tr2.tax_rate_id = tr2cg.tax_rate_id)
			LEFT JOIN `" . DB_PREFIX . "zone_to_geo_zone` z2gz ON (tr2.geo_zone_id = z2gz.geo_zone_id)
			WHERE tr1.based = '" . $based . "'
				AND tr2cg.customer_group_id = " . (int)$this->customerGroupId . "
				AND z2gz.country_id = " . (int)$this->cfg('config_country_id') . "
				AND (z2gz.zone_id = 0 OR z2gz.zone_id = " . (int)$this->cfg('config_zone_id') . ")
			ORDER BY tr1.priority ASC")->rows;

		foreach ($rows as $row) {
			$this->taxRates[(int)$row['tax_class_id']][(int)$row['tax_rate_id']] = [
				'rate' => (float)$row['rate'],
				'type' => (string)$row['type'],
			];
		}

		return $this->taxRates;
	}

	/** Tax on $value for a tax class, matching Cart\Tax::getRates(). */
	private function taxAmount(float $value, int $taxClassId): float {
		$rates = $this->taxRates();

		if ($taxClassId <= 0 || !isset($rates[$taxClassId])) {
			return 0.0;
		}

		$amount = 0.0;

		foreach ($rates[$taxClassId] as $rate) {
			if ($rate['type'] === 'F') {
				$amount += $rate['rate'];
			} elseif ($rate['type'] === 'P') {
				$amount += ($value / 100 * $rate['rate']);
			}
		}

		return $amount;
	}

	/** Exchange value of a currency code, as Cart\Currency uses it. */
	private function currencyValue(string $code): float {
		if ($this->currencyValues === null) {
			$this->currencyValues = [];

			foreach ($this->db->query("SELECT code, value FROM `" . DB_PREFIX . "currency`")->rows as $row) {
				$this->currencyValues[strtoupper((string)$row['code'])] = (float)$row['value'];
			}
		}

		return $this->currencyValues[strtoupper($code)] ?? 0.0;
	}

	/**
	 * Turn a raw `product.price` into the number a buyer sees on the site.
	 *
	 * Same order as the storefront: tax on the base value first, then the
	 * currency multiplier — and only then our own markup coefficient.
	 */
	private function retail(float $base, int $taxClassId, bool $applyTax, float $rate, float $multiplier, int $decimals): float {
		$value = $base;

		if ($applyTax) {
			$value += $this->taxAmount($value, $taxClassId);
		}

		if ($rate > 0) {
			$value *= $rate;
		}

		return round($value * $multiplier, $decimals);
	}

	/**
	 * Rebuild the cached feed files. Shared by the admin button and the cron
	 * controller so the two can never drift apart.
	 *
	 * @return array<string,int> dialect => offer count
	 */
	public static function rebuildCache($db, Settings $settings, string $imageBase, int $customerGroupId, ?callable $productLink = null): array {
		$dir    = Settings::prepareCacheDir();
		$secret = (string)$settings->get('feed_token', '');

		$generator = new self($db, $settings, $settings->languageId(), $imageBase, $customerGroupId);
		if ($productLink !== null) {
			$generator->setProductLink($productLink);
		}
		$done      = [];
		$keep      = [];

		foreach (array_keys(self::DIALECTS) as $dialect) {
			if ($settings->get($dialect . '_enabled', self::isLinkDialect($dialect) ? '0' : '1') !== '1') {
				continue;
			}

			// ⚠ Google/Meta items carry product links, and SEO URLs exist only in
			// the storefront. Admin's "Rebuild" has no link builder, so instead
			// of caching index.php?route=… links it drops the old file: the feed
			// is then generated live until the next cron run writes a proper one.
			if ($productLink === null && self::isLinkDialect($dialect)) {
				continue;
			}

			$tmp   = $dir . 'feed_' . $dialect . '.tmp';
			$final = Settings::cacheFile($dialect, $secret);

			$done[$dialect] = $generator->stream($dialect, $tmp);

			if (is_file($tmp)) {
				@rename($tmp, $final);
				$keep[] = basename($final);
			}
		}

		// A new token means new filenames; files written under the old one would
		// otherwise sit there forever, still readable by anyone who saved the
		// old path. A disabled feed loses its copy for the same reason.
		Settings::purgeCache($keep);

		return $done;
	}

	/** @param int[] $ids */
	private function batchImages(array $ids, int $limit): array {
		if (!$ids) {
			return [];
		}
		$idList = implode(',', $ids);
		$out    = [];

		// Main image first.
		$mains = $this->db->query("SELECT product_id, image FROM `" . DB_PREFIX . "product` WHERE product_id IN (" . $idList . ")")->rows;
		foreach ($mains as $m) {
			$img = trim((string)$m['image']);
			if ($img !== '') {
				$out[(int)$m['product_id']][] = $this->imageUrl($img);
			}
		}

		$extra = $this->db->query("SELECT product_id, image FROM `" . DB_PREFIX . "product_image` WHERE product_id IN (" . $idList . ") ORDER BY sort_order ASC")->rows;
		foreach ($extra as $e) {
			$pid = (int)$e['product_id'];
			$img = trim((string)$e['image']);
			if ($img === '' || count($out[$pid] ?? []) >= $limit) {
				continue;
			}
			$out[$pid][] = $this->imageUrl($img);
		}
		return $out;
	}

	/**
	 * ⚠ Shops that keep photos on a CDN store absolute URLs in product.image;
	 * gluing the image base onto those produced https://shop/image/https%3A//cdn/…
	 */
	private function imageUrl(string $img): string {
		if (preg_match('~^https?://~i', $img)) {
			return $img;
		}

		return $this->imageBase . $this->encPath($img);
	}

	private function encPath(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	/**
	 * Real dispatch time per product: the value of the configured attribute
	 * wins, the shop-wide default fills the rest.
	 *
	 * @param int[] $ids
	 * @return array<int,array{0:int,1:int}>
	 */
	private function batchHandling(array $ids): array {
		if (!$ids) {
			return [];
		}

		$default = Settings::parseHandling((string)$this->settings->get('handling_time', ''));
		$attrId  = (int)$this->settings->get('handling_attribute_id', 0);
		$out     = [];

		if ($default !== null) {
			foreach ($ids as $id) {
				$out[$id] = $default;
			}
		}

		if ($attrId > 0) {
			$rows = $this->db->query("SELECT product_id, text FROM `" . DB_PREFIX . "product_attribute`
				WHERE product_id IN (" . implode(',', $ids) . ")
					AND attribute_id = " . $attrId . "
					AND language_id = " . (int)$this->languageId)->rows;

			foreach ($rows as $row) {
				$days = Settings::parseHandling(html_entity_decode((string)$row['text'], ENT_QUOTES, 'UTF-8'));
				if ($days !== null) {
					$out[(int)$row['product_id']] = $days;
				}
			}
		}

		return $out;
	}

	/**
	 * Best active special per product. The lowest-priority (then lowest-price)
	 * valid row wins.
	 *
	 * ⚠ The two OpenCart 4 branches keep specials in DIFFERENT places:
	 *  - 4.1.x  — `product_discount` rows flagged `special = 1`, with a type
	 *             (F = fixed price, P = percent off, S = subtract);
	 *  - 4.0.x  — a separate `product_special` table holding a fixed price and
	 *             no type column at all.
	 * Querying the 4.1 shape on a 4.0 shop hits "Unknown column 'special'" and
	 * kills the whole feed, so the schema is detected once per run.
	 *
	 * @param int[] $ids
	 * @return array<int,array{type:string,price:float}>
	 */
	private function batchSpecials(array $ids): array {
		if (!$ids) {
			return [];
		}

		$window = " AND (date_start = '0000-00-00' OR date_start IS NULL OR date_start <= NOW())
				AND (date_end = '0000-00-00' OR date_end IS NULL OR date_end >= NOW())";

		if ($this->discountHasSpecialColumn()) {
			// quantity = 1, точно як у ядровій product-моделі: з quantity <= 1 у фід
			// потрапила б акційна ціна, якої на вітрині немає, а розбіжність ціни
			// між фідом і сайтом — привід для блокування прайса маркетплейсом.
			$rows = $this->db->query("SELECT product_id, type, price
				FROM `" . DB_PREFIX . "product_discount`
				WHERE product_id IN (" . implode(',', $ids) . ")
					AND special = 1
					AND quantity = 1
					AND customer_group_id = " . (int)$this->customerGroupId . $window . "
				ORDER BY product_id ASC, priority ASC, price ASC")->rows;
		} else {
			// 4.0.x: fixed price only, so the type is always 'F'.
			$rows = $this->db->query("SELECT product_id, 'F' AS type, price
				FROM `" . DB_PREFIX . "product_special`
				WHERE product_id IN (" . implode(',', $ids) . ")
					AND customer_group_id = " . (int)$this->customerGroupId . $window . "
				ORDER BY product_id ASC, priority ASC, price ASC")->rows;
		}
		$out = [];
		foreach ($rows as $r) {
			$pid = (int)$r['product_id'];
			if (isset($out[$pid])) {
				continue; // first (best) row per product only
			}
			$out[$pid] = ['type' => (string)$r['type'], 'price' => (float)$r['price']];
		}
		return $out;
	}

	/** True on OpenCart 4.1+, where specials live in product_discount. */
	private function discountHasSpecialColumn(): bool {
		if ($this->discountSpecialColumn === null) {
			$this->discountSpecialColumn = (bool)$this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product_discount` LIKE 'special'")->num_rows;
		}

		return $this->discountSpecialColumn;
	}

	/** Resolve an OC4 discount row to an effective special price given the base. */
	private function effectiveSpecial(float $base, string $type, float $value): ?float {
		switch ($type) {
			case 'P': $eff = $base * (1 - $value / 100); break; // percent off
			case 'S': $eff = $base - $value; break;             // subtract amount
			default:  $eff = $value;                            // 'F' / fixed price
		}
		$eff = round($eff, 4);
		return $eff > 0 ? $eff : null;
	}

	/** @param int[] $ids */
	private function batchParams(array $ids): array {
		if (!$ids) {
			return [];
		}
		$rows = $this->db->query("SELECT pa.product_id, ad.name, pa.text
			FROM `" . DB_PREFIX . "product_attribute` pa
			INNER JOIN `" . DB_PREFIX . "attribute_description` ad
				ON ad.attribute_id = pa.attribute_id AND ad.language_id = " . (int)$this->languageId . "
			WHERE pa.product_id IN (" . implode(',', $ids) . ")
				AND pa.language_id = " . (int)$this->languageId . "
			ORDER BY pa.product_id ASC")->rows;
		$out = [];
		foreach ($rows as $r) {
			$name  = trim((string)$r['name']);
			$value = trim(html_entity_decode((string)$r['text'], ENT_QUOTES, 'UTF-8'));
			if ($name === '' || $value === '') {
				continue;
			}
			$out[(int)$r['product_id']][] = ['name' => $name, 'value' => $value];
		}
		return $out;
	}
}
