<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * Google Merchant Center and Meta catalogue feeds: RSS 2.0 with the g: namespace.
 *
 * Mixed into Generator so both feed families share one product query, one
 * price calculation (tax → currency → multiplier) and one stock rule — a
 * Google price that differs from the product page gets the item disapproved.
 *
 * Variants. OpenCart options have no SKU of their own, so an item per variant
 * is built from the options the merchant marked as "size" and "colour":
 *  - id            = <product_id>-<product_option_value_id>[-<…>]
 *  - item_group_id = product_id, which glues the variants into one product card
 *  - price         = product (or special) price + option prices, as the cart adds them
 *  - availability  = product in stock AND every chosen value in stock when that
 *                    value subtracts stock — the same check the cart makes.
 * Two dimensions give their combinations, capped per product so one badly
 * configured product cannot blow the feed up.
 */
trait MerchantFeed {

	/** @var array<int,string> category_id => "Parent > Child" */
	private array $categoryPathCache = [];

	/**
	 * Shop-wide values of the attributes Google added on 14.04.2026, resolved
	 * once per run.
	 *
	 * @var array{days:string,cutoff:string,timezone:string,country:string,mov:float}|null
	 */
	private ?array $shipping2026 = null;

	private ?string $languageCodeCache = null;

	private function streamMerchant(string $dialect, string $target): int {
		$w = new \XMLWriter();
		$w->openUri($target);
		$w->setIndent(true);
		$w->setIndentString('  ');
		$w->startDocument('1.0', 'UTF-8');

		$w->startElement('rss');
		$w->writeAttribute('version', '2.0');
		$w->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
		$w->startElement('channel');

		$shop = $this->merchantText((string)($this->cfg('config_name') ?: 'Shop'), 150);
		$w->writeElement('title', $shop);
		$w->writeElement('link', rtrim((string)$this->cfg('config_url'), '/') . '/');
		$w->writeElement('description', $shop);

		$count = $this->writeMerchantItems($w, $dialect);

		$w->endElement(); // channel
		$w->endElement(); // rss
		$w->endDocument();
		$w->flush();

		return $count;
	}

	private function writeMerchantItems(\XMLWriter $w, string $dialect): int {
		$currency    = $this->settings->currencyCode();
		$pc          = $this->pricing($currency);
		$useSpecial  = $this->settings->get('use_special', '1') === '1';
		$inclOut     = $this->settings->get('include_out_of_stock', '0') === '1';
		$stockThresh = (int)$this->settings->get('stock_threshold', 0);
		// Google takes the main image plus 10 additional ones.
		$imageLimit  = min(11, max(1, (int)$this->settings->get('image_limit', 10)));
		$sizeIds     = array_values(array_filter(array_map('intval', (array)$this->settings->get('size_option_ids', []))));
		$colorIds    = array_values(array_filter(array_map('intval', (array)$this->settings->get('color_option_ids', []))));
		$isGoogle    = $dialect === 'google';

		$whereSql = $this->productWhere();
		$pageSize = 500;
		$offset   = 0;
		$written  = 0;

		while (true) {
			$rows = $this->fetchPage($whereSql, $offset, $pageSize);

			if (!$rows) {
				break;
			}

			$ids      = array_map(static fn($r) => (int)$r['product_id'], $rows);
			$images   = $this->batchImages($ids, $imageLimit);
			$specials = $useSpecial ? $this->batchSpecials($ids) : [];
			$variants = $this->batchVariants($ids, $sizeIds, $colorIds);
			$handling = $isGoogle ? $this->batchHandling($ids) : [];
			$this->loadCategoryPaths(array_map(static fn($r) => (int)$r['category_id'], $rows));

			foreach ($rows as $r) {
				$pid   = (int)$r['product_id'];
				$inStk = (int)$r['quantity'] > $stockThresh;

				if (!$inStk && !$inclOut) {
					continue;
				}

				$name = trim((string)$r['name']);
				$base = [
					'id'          => (string)$pid,
					'group'       => '',
					'title'       => $name,
					'description' => (string)$r['description'],
					'link'        => $this->merchantLink($pid),
					'images'      => $images[$pid] ?? [],
					'brand'       => trim((string)$r['vendor']) !== '' ? trim((string)$r['vendor']) : trim((string)$this->settings->get('merchant_brand', '')),
					'gtin'        => $this->gtin((string)$r['ean']),
					'mpn'         => $this->mpn($r),
					'type'        => $this->categoryPathCache[(int)$r['category_id']] ?? '',
					'size'        => '',
					'color'       => '',
					'handling'    => $handling[$pid] ?? null,
				];

				$combos = $this->variantCombos($variants[$pid] ?? []);

				if (!$combos) {
					[$regular, $special] = $this->productPrices($r, $specials, $pc);
					if (($special ?? $regular) <= 0) {
						continue;
					}
					$this->writeMerchantItem($w, $dialect, $base + ['in_stock' => $inStk, 'price' => $regular, 'sale' => $special], $currency, $pc['decimals']);
					$written++;
					continue;
				}

				foreach ($combos as $combo) {
					$available = $inStk;
					$delta     = 0.0;
					$idParts   = [(string)$pid];
					$labels    = [];
					$item      = $base;

					foreach ($combo as $dimension => $value) {
						$delta    += $value['delta'];
						$idParts[] = (string)$value['id'];
						$labels[]  = $value['name'];
						$item[$dimension] = $value['name'];

						if ($value['subtract'] && $value['qty'] <= 0) {
							$available = false;
						}
					}

					if (!$available && !$inclOut) {
						continue;
					}

					[$regular, $special] = $this->productPrices($r, $specials, $pc, $delta);
					if (($special ?? $regular) <= 0) {
						continue;
					}

					$item['id']    = implode('-', $idParts);
					$item['group'] = (string)$pid;
					$item['title'] = $name . ' (' . implode(', ', $labels) . ')';
					// EAN and SKU belong to the product, not to one of its sizes: the
					// same GTIN on every variant is an error in Merchant Center.
					$item['gtin']  = '';
					$item['mpn']   = '';

					$this->writeMerchantItem($w, $dialect, $item + ['in_stock' => $available, 'price' => $regular, 'sale' => $special], $currency, $pc['decimals']);
					$written++;
				}
			}

			$w->flush();

			if (count($rows) < $pageSize) {
				break;
			}
			$offset += $pageSize;
		}

		return $written;
	}

	private function writeMerchantItem(\XMLWriter $w, string $dialect, array $it, string $currency, int $decimals): void {
		$isMeta = $dialect === 'meta';

		$w->startElement('item');

		$w->writeElement('g:id', mb_substr($it['id'], 0, 50));
		if ($it['group'] !== '') {
			$w->writeElement('g:item_group_id', $it['group']);
		}

		$title = $this->merchantText($it['title'], 150);
		$w->writeElement('g:title', $title);

		// Plain text only; Meta rejects a row with no description at all.
		$description = $this->merchantText($it['description'], $isMeta ? 9999 : 5000);
		$w->writeElement('g:description', $description !== '' ? $description : $title);

		$w->writeElement('g:link', $it['link']);

		$images = array_values($it['images']);
		if ($images) {
			$w->writeElement('g:image_link', $images[0]);
			foreach (array_slice($images, 1, 10) as $extra) {
				$w->writeElement('g:additional_image_link', $extra);
			}
		}

		if ($isMeta) {
			$w->writeElement('g:availability', $it['in_stock'] ? 'in stock' : 'out of stock');
		} else {
			$w->writeElement('g:availability', $it['in_stock'] ? 'in_stock' : 'out_of_stock');
		}

		$money = static fn(float $v): string => number_format($v, $decimals, '.', '') . ' ' . $currency;

		if ($it['sale'] !== null && $it['price'] > $it['sale']) {
			$advertised = (float)$it['price'];
			$w->writeElement('g:price', $money($it['price']));
			$w->writeElement('g:sale_price', $money($it['sale']));
		} else {
			$advertised = (float)($it['sale'] ?? $it['price']);
			$w->writeElement('g:price', $money($advertised));
		}

		$w->writeElement('g:condition', 'new');

		if ($it['brand'] !== '') {
			$w->writeElement('g:brand', $this->merchantText($it['brand'], 70));
		}
		if ($it['gtin'] !== '') {
			$w->writeElement('g:gtin', $it['gtin']);
		}
		if ($it['mpn'] !== '') {
			$w->writeElement('g:mpn', $this->merchantText($it['mpn'], 70));
		}

		$googleCategory = trim((string)$this->settings->get('google_category', ''));
		if ($googleCategory !== '') {
			$w->writeElement('g:google_product_category', $googleCategory);
		}
		if ($it['type'] !== '') {
			$w->writeElement('g:product_type', mb_substr($it['type'], 0, 750));
		}

		if ($it['size'] !== '') {
			$w->writeElement('g:size', $this->merchantText($it['size'], 100));
		}
		if ($it['color'] !== '') {
			$w->writeElement('g:color', $this->merchantText($it['color'], 100));
		}

		// Shipping/handling attributes are Google's; Meta has no such fields.
		if (!$isMeta) {
			if ($it['handling'] !== null) {
				$w->writeElement('g:min_handling_time', (string)$it['handling'][0]);
				$w->writeElement('g:max_handling_time', (string)$it['handling'][1]);
			}
			$this->writeShipping2026($w, $advertised, $currency, $decimals);
		}

		$w->endElement(); // item
	}

	/**
	 * Option values of the size/colour options per product.
	 *
	 * @param int[] $ids
	 * @param int[] $sizeIds
	 * @param int[] $colorIds
	 * @return array<int,array{size?:array,color?:array}>
	 */
	private function batchVariants(array $ids, array $sizeIds, array $colorIds): array {
		if (!$ids || (!$sizeIds && !$colorIds)) {
			return [];
		}

		$optionIds = array_unique(array_merge($sizeIds, $colorIds));

		$rows = $this->db->query("SELECT pov.product_option_value_id, pov.product_id, pov.option_id,
				pov.quantity, pov.subtract, pov.price, pov.price_prefix, ovd.name
			FROM `" . DB_PREFIX . "product_option_value` pov
			INNER JOIN `" . DB_PREFIX . "product_option` po ON po.product_option_id = pov.product_option_id
			LEFT JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = pov.option_value_id
			LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd
				ON ovd.option_value_id = pov.option_value_id AND ovd.language_id = " . (int)$this->languageId . "
			WHERE pov.product_id IN (" . implode(',', $ids) . ")
				AND pov.option_id IN (" . implode(',', $optionIds) . ")
			ORDER BY pov.product_id ASC, ov.sort_order ASC, pov.product_option_value_id ASC")->rows;

		$out = [];

		foreach ($rows as $row) {
			$name = trim(html_entity_decode((string)$row['name'], ENT_QUOTES, 'UTF-8'));
			if ($name === '') {
				continue;
			}

			$dimension = in_array((int)$row['option_id'], $sizeIds, true) ? 'size' : 'color';
			$delta     = (float)$row['price'];

			$out[(int)$row['product_id']][$dimension][] = [
				'id'       => (int)$row['product_option_value_id'],
				'name'     => $name,
				'qty'      => (int)$row['quantity'],
				'subtract' => (int)$row['subtract'] === 1,
				'delta'    => (string)$row['price_prefix'] === '-' ? -$delta : $delta,
			];
		}

		return $out;
	}

	/**
	 * Size × colour combinations for one product (a single dimension gives its
	 * values as they are). Capped at 100 items per product.
	 *
	 * @return array<int,array<string,array>>
	 */
	private function variantCombos(array $dims): array {
		$sizes  = $dims['size'] ?? [];
		$colors = $dims['color'] ?? [];

		if (!$sizes && !$colors) {
			return [];
		}

		$out = [];

		foreach ($sizes ?: [null] as $size) {
			foreach ($colors ?: [null] as $color) {
				$combo = [];
				if ($size !== null) {
					$combo['size'] = $size;
				}
				if ($color !== null) {
					$combo['color'] = $color;
				}
				$out[] = $combo;

				if (count($out) >= 100) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * Attributes Google added to the product data specification on 14.04.2026.
	 *
	 * - g:shipping_handling_business_days — which days the shop actually packs
	 *   orders. Absent, Google assumes Mon–Fri and misdates every Saturday
	 *   dispatch.
	 * - g:handling_cutoff_time — the daily deadline. Absent, the handling days
	 *   are counted from midnight, so an order placed at 18:00 gets the same
	 *   promised date as one placed at 09:00, and the account collects
	 *   "delivery date mismatch" warnings.
	 * - g:minimum_order_value — published only for products that cost no more
	 *   than the minimum, because Google accepts the attribute only when "your
	 *   product's price is equal to or lesser than the minimum order value".
	 *
	 * $price is the value written to g:price — the one Google reads as "the
	 * product's price", so on a discounted item it is the regular price.
	 *
	 * @link https://support.google.com/merchants/answer/16989427
	 */
	private function writeShipping2026(\XMLWriter $w, float $price, string $currency, int $decimals): void {
		if ($this->shipping2026 === null) {
			$this->shipping2026 = [
				'days'     => Settings::normalizeBusinessDays((string)$this->settings->get('handling_business_days', '')),
				'cutoff'   => Settings::normalizeCutoff((string)$this->settings->get('handling_cutoff_time', '')),
				'timezone' => $this->settings->cutoffTimezone(),
				'country'  => $this->merchantCountry(),
				'mov'      => $this->settings->minOrderValue(),
			];
		}

		$cfg = $this->shipping2026;

		if ($cfg['days'] !== '') {
			$w->writeElement('g:shipping_handling_business_days', $cfg['days']);
		}

		if ($cfg['cutoff'] !== '') {
			$w->startElement('g:handling_cutoff_time');
			if ($cfg['country'] !== '') {
				$w->writeElement('g:country', $cfg['country']);
			}
			$w->writeElement('g:cutoff_time', $cfg['cutoff']);
			if ($cfg['timezone'] !== '') {
				$w->writeElement('g:cutoff_timezone', $cfg['timezone']);
			}
			$w->endElement();
		}

		// country is a required sub-attribute — without it Google drops the row.
		if ($cfg['mov'] > 0 && $cfg['country'] !== '' && $price <= $cfg['mov']) {
			$w->startElement('g:minimum_order_value');
			$w->writeElement('g:country', $cfg['country']);
			$w->writeElement('g:price', number_format($cfg['mov'], $decimals, '.', '') . ' ' . $currency);
			$w->endElement();
		}
	}

	/**
	 * ISO 3166-1 alpha-2 country the shipping attributes apply to: the one named
	 * in the settings, otherwise the shop's own address country.
	 */
	private function merchantCountry(): string {
		$explicit = strtoupper(trim((string)$this->settings->get('merchant_country', '')));
		if (preg_match('/^[A-Z]{2}$/', $explicit)) {
			return $explicit;
		}

		$countryId = (int)$this->cfg('config_country_id');
		if ($countryId <= 0) {
			return '';
		}

		$row  = $this->db->query("SELECT iso_code_2 FROM `" . DB_PREFIX . "country` WHERE country_id = " . $countryId)->row;
		$code = strtoupper(trim((string)($row['iso_code_2'] ?? '')));

		return preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
	}

	/** @param int[] $categoryIds */
	private function loadCategoryPaths(array $categoryIds): void {
		$missing = array_values(array_diff(array_unique(array_filter($categoryIds)), array_keys($this->categoryPathCache)));

		if (!$missing) {
			return;
		}

		$rows = $this->db->query("SELECT cp.category_id, cd.name
			FROM `" . DB_PREFIX . "category_path` cp
			LEFT JOIN `" . DB_PREFIX . "category_description` cd
				ON cd.category_id = cp.path_id AND cd.language_id = " . (int)$this->languageId . "
			WHERE cp.category_id IN (" . implode(',', array_map('intval', $missing)) . ")
			ORDER BY cp.category_id ASC, cp.level ASC")->rows;

		$paths = [];
		foreach ($rows as $row) {
			$name = trim(html_entity_decode((string)$row['name'], ENT_QUOTES, 'UTF-8'));
			if ($name !== '') {
				$paths[(int)$row['category_id']][] = $name;
			}
		}

		foreach ($missing as $id) {
			$this->categoryPathCache[(int)$id] = implode(' > ', $paths[(int)$id] ?? []);
		}
	}

	private function merchantLink(int $productId): string {
		if ($this->productLink !== null) {
			return str_replace('&amp;', '&', (string)call_user_func($this->productLink, $productId));
		}

		$query = 'route=product/product&product_id=' . $productId;
		$code  = $this->languageCode();
		if ($code !== '') {
			$query = 'route=product/product&language=' . rawurlencode($code) . '&product_id=' . $productId;
		}

		return rtrim((string)$this->cfg('config_url'), '/') . '/index.php?' . $query;
	}

	/** Code of the feed language (uk-ua, en-gb…), used in product links. */
	public function languageCode(): string {
		if ($this->languageCodeCache === null) {
			$row = $this->db->query("SELECT code FROM `" . DB_PREFIX . "language` WHERE language_id = " . (int)$this->languageId)->row;
			$this->languageCodeCache = (string)($row['code'] ?? '');
		}

		return $this->languageCodeCache;
	}

	/** EAN/UPC/GTIN-14 only when it is one: digits of a GTIN length. */
	private function gtin(string $raw): string {
		$raw = preg_replace('/\s+/', '', $raw);

		return preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', (string)$raw) ? (string)$raw : '';
	}

	private function mpn(array $row): string {
		switch ((string)$this->settings->get('merchant_mpn', 'sku')) {
			case 'model': return trim((string)$row['model']);
			case 'none':  return '';
			default:      return trim((string)$row['sku']);
		}
	}

	/**
	 * Plain one-line text: tags and repeated spaces gone, cut to $max.
	 *
	 * ⚠ Decoded twice on purpose. OpenCart stores descriptions HTML-escaped, so
	 * the first pass only turns `&lt;p&gt;…&amp;quot;` into markup; the entities
	 * inside that markup need a second pass, or Google shows a literal "&quot;".
	 */
	private function merchantText(string $text, int $max): string {
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = preg_replace('/<\s*br\s*\/?>|<\/\s*(p|li|div|h\d)\s*>/iu', ' ', $text);
		$text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));

		return mb_substr($text, 0, $max);
	}
}
