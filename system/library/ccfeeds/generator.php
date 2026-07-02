<?php
namespace Opencart\System\Library\Ccfeeds;

require_once __DIR__ . '/settings.php';

/**
 * Streaming YML/XML feed generator for Rozetka and Prom.ua.
 *
 * Output is written incrementally through XMLWriter straight to php://output
 * (or a file), so a catalog of tens of thousands of offers never has to sit in
 * memory at once. Products are pulled in pages and their images / attributes /
 * specials are batch-loaded per page to keep the query count bounded.
 */
class Generator {
	private $db;
	private Settings $settings;
	private int $languageId;
	private string $imageBase;
	private int $customerGroupId;

	/** Per-dialect tag differences. */
	private const DIALECTS = [
		'rozetka' => ['qty_tag' => 'stock_quantity'],
		'prom'    => ['qty_tag' => 'quantity_in_stock'],
	];

	public function __construct($db, Settings $settings, int $languageId, string $imageBase, int $customerGroupId = 0) {
		$this->db              = $db;
		$this->settings        = $settings;
		$this->languageId      = $languageId;
		$this->imageBase       = rtrim($imageBase, '/') . '/';
		$this->customerGroupId = $customerGroupId;
	}

	/**
	 * Stream a feed for the given dialect ('rozetka'|'prom') to $target
	 * ('php://output' by default, or a filesystem path for cache generation).
	 * Returns the number of offers written.
	 */
	public function stream(string $dialect, string $target = 'php://output'): int {
		$dialect = isset(self::DIALECTS[$dialect]) ? $dialect : 'prom';
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
		$currency = (string)$this->settings->get('currency', 'UAH');
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
		$count = $this->writeOffers($w, $currency, $qtyTag);
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

	private function writeOffers(\XMLWriter $w, string $currency, string $qtyTag): int {
		$multiplier   = (float)$this->settings->get('price_multiplier', 1) ?: 1.0;
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

		$catFilter = array_filter(array_map('intval', (array)$this->settings->get('category_ids', [])));
		$manFilter = array_filter(array_map('intval', (array)$this->settings->get('manufacturer_ids', [])));

		$where = ["p.status = 1", "p.date_available <= NOW()"];
		if ($manFilter) {
			$where[] = "p.manufacturer_id IN (" . implode(',', $manFilter) . ")";
		}
		if ($catFilter) {
			$where[] = "p.product_id IN (SELECT product_id FROM `" . DB_PREFIX . "product_to_category` WHERE category_id IN (" . implode(',', $catFilter) . "))";
		}
		$whereSql = implode(' AND ', $where);

		$pageSize = 500;
		$offset   = 0;
		$written  = 0;

		while (true) {
			$rows = $this->db->query("SELECT p.product_id, p.model, p.sku, p.ean, p.quantity, p.price,
					p.image, p.manufacturer_id, m.name AS vendor,
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

			if (!$rows) {
				break;
			}

			$ids = array_map(static fn($r) => (int)$r['product_id'], $rows);
			$images   = $this->batchImages($ids, $imageLimit);
			$specials = $useSpecial ? $this->batchSpecials($ids) : [];
			$params   = $exportParams ? $this->batchParams($ids) : [];

			foreach ($rows as $r) {
				$pid   = (int)$r['product_id'];
				$qty   = (int)$r['quantity'];
				$inStk = $qty > $stockThresh;
				if (!$inStk && !$inclOut) {
					continue;
				}

				$rawBase = (float)$r['price'];
				$base    = round($rawBase * $multiplier, $decimals);
				$special = null;
				if (isset($specials[$pid])) {
					$eff = $this->effectiveSpecial($rawBase, $specials[$pid]['type'], (float)$specials[$pid]['price']);
					if ($eff !== null) {
						$special = round($eff * $multiplier, $decimals);
					}
				}
				$price   = ($special !== null && $special > 0) ? $special : $base;
				if ($price <= 0) {
					continue;
				}

				$w->startElement('offer');
				$w->writeAttribute('id', (string)$pid);
				$w->writeAttribute('available', $inStk ? $availIn : $availOut);

				$name = trim((string)$r['name']);
				if ($nameSuffix !== '') {
					$name .= ' ' . $nameSuffix;
				}
				$w->writeElement('name', $name);

				$w->writeElement('price', $this->num($price, $decimals));
				if ($special !== null && $special > 0 && $base > $special) {
					$w->writeElement('price_old', $this->num($base, $decimals));
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
				$out[(int)$m['product_id']][] = $this->imageBase . $this->encPath($img);
			}
		}

		$extra = $this->db->query("SELECT product_id, image FROM `" . DB_PREFIX . "product_image` WHERE product_id IN (" . $idList . ") ORDER BY sort_order ASC")->rows;
		foreach ($extra as $e) {
			$pid = (int)$e['product_id'];
			$img = trim((string)$e['image']);
			if ($img === '' || count($out[$pid] ?? []) >= $limit) {
				continue;
			}
			$out[$pid][] = $this->imageBase . $this->encPath($img);
		}
		return $out;
	}

	private function encPath(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	/**
	 * Best active special per product. OpenCart 4 stores specials in
	 * product_discount rows flagged special=1 (with a type: F=fixed price,
	 * P=percent off, S=subtract) — there is no product_special table. The
	 * lowest-priority (then lowest-price) valid row wins.
	 *
	 * @param int[] $ids
	 * @return array<int,array{type:string,price:float}>
	 */
	private function batchSpecials(array $ids): array {
		if (!$ids) {
			return [];
		}
		$rows = $this->db->query("SELECT product_id, type, price
			FROM `" . DB_PREFIX . "product_discount`
			WHERE product_id IN (" . implode(',', $ids) . ")
				AND special = 1
				AND quantity <= 1
				AND customer_group_id = " . (int)$this->customerGroupId . "
				AND (date_start = '0000-00-00' OR date_start IS NULL OR date_start <= NOW())
				AND (date_end = '0000-00-00' OR date_end IS NULL OR date_end >= NOW())
			ORDER BY product_id ASC, priority ASC, price ASC")->rows;
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
