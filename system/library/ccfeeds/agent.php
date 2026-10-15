<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * AI-agent catalogue for the OpenCart 4 build of Market Feeds (generated
 * from the 3.x source by sync.py — edit that one):
 * normalized product records for the OpenAI Product Feed, the UCP catalog
 * REST endpoints and /llms.txt.
 *
 * Mixed into Generator next to MerchantFeed, so the records use
 * the same product WHERE, tax → currency price, specials, images, category
 * paths and storefront links as the Google feed. Two things differ on purpose:
 *  - no price multiplier and no name suffix — an agent sends the buyer to the
 *    product page, and a price that differs from the page is what gets an
 *    offer dropped;
 *  - variant dimensions carry the option's own name ("Розмір": "M") instead of
 *    Google's fixed size/color keys, because that is what OpenAI's
 *    variant_dict and UCP options describe.
 *
 * PHP 5.6+ syntax (no `??`, no scalar types, array() literals).
 */
trait AgentFeed {

	/** @var array reason => count of OpenAI rows left out on the last run */
	private $agentSkipped = array();

	/** @var array|null seller_name, seller_url, policy — set by the storefront controller */
	private $agentShopInfo = null;

	/** Shop-level values of the OpenAI feed (seller, policy links), built by the storefront. */
	public function setAgentShop($shop) {
		$this->agentShopInfo = $shop;
	}

	/** The shop info, or the bare minimum the config gives without the storefront. */
	public function agentShop() {
		if ($this->agentShopInfo !== null) {
			return $this->agentShopInfo;
		}

		return array(
			'seller_name' => AgentFormat::text((string)$this->cfg('config_name'), 200),
			'seller_url'  => rtrim((string)$this->cfg('config_url'), '/') . '/',
			'policy'      => array(),
		);
	}

	/**
	 * Pricing for one output: target currency, no multiplier.
	 *
	 * @param string $currency ISO code the amounts are wanted in
	 * @param int    $decimals rounding
	 */
	private function agentPricing($currency, $decimals) {
		$applyTax     = $this->settings->get('include_tax', '1') === '1' && (string)$this->cfg('config_tax') === '1';
		$rate         = 1.0;
		$baseCurrency = $this->baseCurrencyCode();

		if ($baseCurrency !== '' && strtoupper($currency) !== $baseCurrency) {
			$to   = $this->currencyValue($currency);
			$from = $this->currencyValue($baseCurrency);
			if ($to > 0 && $from > 0) {
				$rate = $to / $from;
			}
		}

		return array('applyTax' => $applyTax, 'rate' => $rate, 'decimals' => (int)$decimals, 'currency' => strtoupper($currency));
	}

	/** Public form of agentPricing() for the UCP catalog. */
	public function agentPricingFor($currency, $decimals) {
		return $this->agentPricing($currency, $decimals);
	}

	/** Number of products matching a WHERE. */
	public function agentCount($whereSql) {
		$row = $this->db->query("SELECT COUNT(*) AS c FROM `" . DB_PREFIX . "product` p
			LEFT JOIN `" . DB_PREFIX . "product_description` pd
				ON pd.product_id = p.product_id AND pd.language_id = '" . (int)$this->languageId . "'
			WHERE " . $whereSql)->row;

		return isset($row['c']) ? (int)$row['c'] : 0;
	}

	/**
	 * Free-text search the way the storefront searches: every word must occur
	 * in the name, tags, model or SKU. Exact and leading name matches first.
	 *
	 * ⚠ OpenCart 4 on the pdo driver: escape() returns a placeholder (":0")
	 * bound to the NEXT query only. So every value goes through escape() whole,
	 * wildcards included, and the fragment is built afresh for each query —
	 * reusing it for the count and then the page fails with HY093. For the
	 * same reason the ORDER part is a separate call: a COUNT that escaped the
	 * ordering values without using them would bind too many parameters.
	 *
	 * @return string WHERE fragment
	 */
	public function agentSearchSql($query) {
		$query = trim((string)preg_replace('/\s+/u', ' ', (string)$query));
		$words = array_slice(array_filter(explode(' ', $query), 'strlen'), 0, 10);
		$parts = array();

		foreach ($words as $word) {
			$like  = '%' . addcslashes($word, '%_\\') . '%';
			$alts  = array();
			foreach (array('pd.name', 'pd.tag', 'p.model', 'p.sku') as $column) {
				$alts[] = $column . " LIKE '" . $this->db->escape($like) . "'";
			}
			$parts[] = '(' . implode(' OR ', $alts) . ')';
		}

		return $parts ? '(' . implode(' AND ', $parts) . ')' : '1';
	}

	/** ORDER BY for a text search: exact name, then leading, then inner matches. */
	public function agentSearchOrder($query) {
		$query = trim((string)preg_replace('/\s+/u', ' ', (string)$query));
		$start = addcslashes($query, '%_\\');

		return "CASE WHEN LCASE(pd.name) = LCASE('" . $this->db->escape($query) . "') THEN 0"
			. " WHEN pd.name LIKE '" . $this->db->escape($start . '%') . "' THEN 1"
			. " WHEN pd.name LIKE '" . $this->db->escape('%' . $start . '%') . "' THEN 2 ELSE 3 END ASC, p.sort_order ASC, p.product_id ASC";
	}

	/**
	 * Category IDs whose full path ("Parent > Child") equals one of the given
	 * values, case-insensitively — the values a UCP search returns in
	 * `categories[].value`.
	 *
	 * @param string[] $paths
	 * @return int[]
	 */
	public function agentCategoryIdsByPath($paths) {
		$wanted = array();
		foreach ((array)$paths as $path) {
			$key = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$path), 'UTF-8') : strtolower(trim((string)$path));
			$key = (string)preg_replace('/\s*>\s*/u', ' > ', $key);
			if ($key !== '') {
				$wanted[$key] = true;
			}
		}

		if (!$wanted) {
			return array();
		}

		$ids = array();
		foreach ($this->db->query("SELECT category_id FROM `" . DB_PREFIX . "category` WHERE status = '1'")->rows as $row) {
			$ids[] = (int)$row['category_id'];
		}
		$this->loadCategoryPaths($ids);

		$out = array();
		foreach ($ids as $id) {
			$path = isset($this->categoryPathCache[$id]) ? $this->categoryPathCache[$id] : '';
			$key  = function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);
			if ($key !== '' && isset($wanted[$key])) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/** " AND product in these categories or below them". */
	public function agentInCategoriesSql($categoryIds) {
		$ids = implode(',', array_map('intval', (array)$categoryIds));

		return " AND p.product_id IN (SELECT p2c.product_id FROM `" . DB_PREFIX . "product_to_category` p2c
			INNER JOIN `" . DB_PREFIX . "category_path` cp ON cp.category_id = p2c.category_id
			WHERE cp.path_id IN (" . $ids . "))";
	}

	/** Product ID with this SKU (or model) in the agent scope, 0 when none. */
	public function agentProductBySku($sku, $storeId) {
		// Two escape() calls on purpose: on pdo each one is a single-use placeholder.
		$row = $this->db->query("SELECT p.product_id FROM `" . DB_PREFIX . "product` p
			WHERE " . $this->agentWhere($storeId) . " AND (p.sku = '" . $this->db->escape((string)$sku) . "' OR p.model = '" . $this->db->escape((string)$sku) . "')
			ORDER BY p.product_id ASC LIMIT 1")->row;

		return isset($row['product_id']) ? (int)$row['product_id'] : 0;
	}

	/**
	 * Top-level categories for /llms.txt with the number of products of the
	 * agent scope in each (subcategories included).
	 *
	 * @return array[] array(category_id, name, count)
	 */
	public function agentTopCategories($storeId, $limit) {
		$rows = $this->db->query("SELECT c.category_id, cd.name FROM `" . DB_PREFIX . "category` c
			INNER JOIN `" . DB_PREFIX . "category_to_store` c2s ON c2s.category_id = c.category_id AND c2s.store_id = '" . (int)$storeId . "'
			LEFT JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = c.category_id AND cd.language_id = '" . (int)$this->languageId . "'
			WHERE c.parent_id = '0' AND c.status = '1'
			ORDER BY c.sort_order ASC, cd.name ASC
			LIMIT " . (int)$limit)->rows;

		$out = array();
		foreach ($rows as $row) {
			$name = AgentFormat::text((string)$row['name'], 200);
			if ($name === '') {
				continue;
			}
			$out[] = array(
				'category_id' => (int)$row['category_id'],
				'name'        => $name,
				'count'       => $this->agentCount($this->agentWhere($storeId) . $this->agentInCategoriesSql(array((int)$row['category_id']))),
			);
		}

		return $out;
	}

	/** Decimal places the shop shows for a currency (OpenCart currency table). */
	public function agentCurrencyDecimals($currency) {
		$row = $this->db->query("SELECT decimal_place FROM `" . DB_PREFIX . "currency` WHERE code = '" . $this->db->escape(strtoupper((string)$currency)) . "' LIMIT 1")->row;

		return isset($row['decimal_place']) ? max(0, min(4, (int)$row['decimal_place'])) : 2;
	}

	/** WHERE for agent-facing catalogue reads: the feed scope, limited to this store. */
	public function agentWhere($storeId) {
		return $this->productWhere() . " AND p.product_id IN (SELECT p2s.product_id FROM `" . DB_PREFIX . "product_to_store` p2s WHERE p2s.store_id = '" . (int)$storeId . "')";
	}

	/**
	 * Normalized product records, in the order of $orderSql.
	 *
	 * @param string $whereSql full WHERE (without the keyword)
	 * @param string $orderSql ORDER BY body
	 * @param int    $offset
	 * @param int    $limit
	 * @param array  $pc agentPricing() result
	 * @return array[]
	 */
	public function agentRecords($whereSql, $orderSql, $offset, $limit, $pc) {
		$rows = $this->db->query("SELECT p.product_id, p.model, p.sku, p.ean, p.quantity, p.price,
				p.tax_class_id, m.name AS vendor, pd.name, pd.description, pd.tag,
				(SELECT p2c.category_id FROM `" . DB_PREFIX . "product_to_category` p2c
					INNER JOIN `" . DB_PREFIX . "category` cc ON cc.category_id = p2c.category_id AND cc.status = '1'
					WHERE p2c.product_id = p.product_id
					ORDER BY (SELECT COUNT(*) FROM `" . DB_PREFIX . "category_path` cp WHERE cp.category_id = p2c.category_id) DESC
					LIMIT 1) AS category_id
			FROM `" . DB_PREFIX . "product` p
			LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON m.manufacturer_id = p.manufacturer_id
			LEFT JOIN `" . DB_PREFIX . "product_description` pd
				ON pd.product_id = p.product_id AND pd.language_id = '" . (int)$this->languageId . "'
			WHERE " . $whereSql . "
			ORDER BY " . $orderSql . "
			LIMIT " . (int)$offset . ", " . (int)$limit)->rows;

		return $this->agentBuild($rows, $pc);
	}

	/** Records for an explicit list of product IDs (unknown or hidden IDs are dropped). */
	public function agentRecordsByIds($productIds, $storeId, $pc) {
		$ids = array_values(array_unique(array_filter(array_map('intval', (array)$productIds))));

		if (!$ids) {
			return array();
		}

		$records = $this->agentRecords($this->agentWhere($storeId) . " AND p.product_id IN (" . implode(',', $ids) . ")", 'p.product_id ASC', 0, count($ids), $pc);
		$out     = array();

		foreach ($records as $record) {
			$out[(int)$record['product_id']] = $record;
		}

		return $out;
	}

	private function agentBuild($rows, $pc) {
		if (!$rows) {
			return array();
		}

		$useSpecial  = $this->settings->get('use_special', '1') === '1';
		$stockThresh = (int)$this->settings->get('stock_threshold', 0);
		$imageLimit  = min(11, max(1, (int)$this->settings->get('image_limit', 10)));
		$brandDef    = AgentFormat::text((string)$this->settings->get('merchant_brand', ''), 70);

		$ids  = array();
		$cats = array();
		foreach ($rows as $row) {
			$ids[]  = (int)$row['product_id'];
			$cats[] = (int)$row['category_id'];
		}

		$images   = $this->batchImages($ids, $imageLimit);
		$specials = $useSpecial ? $this->batchSpecials($ids) : array();
		$options  = $this->agentOptions($ids);
		$ratings  = $this->agentRatings($ids);
		$this->loadCategoryPaths($cats);

		$out = array();

		foreach ($rows as $row) {
			$pid      = (int)$row['product_id'];
			$taxClass = (int)$row['tax_class_id'];
			$inStock  = (int)$row['quantity'] > $stockThresh;
			$special  = isset($specials[$pid]) ? $this->effectiveSpecial((float)$row['price'], (string)$specials[$pid]['type'], (float)$specials[$pid]['price']) : null;
			$vendor   = AgentFormat::text((string)$row['vendor'], 70);
			$catId    = (int)$row['category_id'];

			$record = array(
				'product_id'    => $pid,
				'name'          => (string)$row['name'],
				'description'   => (string)$row['description'],
				'tags'          => (string)$row['tag'],
				'url'           => $this->merchantLink($pid),
				'images'        => isset($images[$pid]) ? $images[$pid] : array(),
				'brand'         => $vendor !== '' ? $vendor : $brandDef,
				'gtin'          => $this->gtin((string)$row['ean']),
				'sku'           => trim((string)$row['sku']) !== '' ? trim((string)$row['sku']) : trim((string)$row['model']),
				'category_id'   => $catId,
				'category_path' => isset($this->categoryPathCache[$catId]) ? $this->categoryPathCache[$catId] : '',
				'in_stock'      => $inStock,
				'quantity'      => (int)$row['quantity'],
				'regular'       => $this->agentRetail((float)$row['price'], $taxClass, $pc),
				'special'       => $special !== null ? $this->agentRetail($special, $taxClass, $pc) : null,
				'rating'        => isset($ratings[$pid]) ? $ratings[$pid] : null,
				'options'       => array(),
				'variants'      => array(),
			);

			if ($record['special'] !== null && $record['special'] <= 0) {
				$record['special'] = null;
			}

			if (!empty($options[$pid])) {
				$record['options'] = $this->agentOptionList($options[$pid]);
				foreach ($this->agentCombos($options[$pid]) as $combo) {
					$delta     = 0.0;
					$available = $inStock;
					$idParts   = array((string)$pid);
					$selected  = array();
					$dims      = array();

					foreach ($combo as $value) {
						$delta    += $value['delta'];
						$idParts[] = (string)$value['id'];
						$selected[] = array('name' => $value['option'], 'label' => $value['name'], 'id' => (string)$value['id']);
						$dims[$value['dimension']] = $value['name'];
						if ($value['subtract'] && $value['qty'] <= 0) {
							$available = false;
						}
					}

					$variantSpecial = $special !== null ? $this->agentRetail($special + $delta, $taxClass, $pc) : null;

					$record['variants'][] = array(
						'id'       => implode('-', $idParts),
						'selected' => $selected,
						'dims'     => $dims,
						'in_stock' => $available,
						'regular'  => $this->agentRetail((float)$row['price'] + $delta, $taxClass, $pc),
						'special'  => ($variantSpecial !== null && $variantSpecial > 0) ? $variantSpecial : null,
					);
				}
			}

			$out[] = $record;
		}

		return $out;
	}

	/** Raw price → storefront price in the pricing's currency, no multiplier. */
	private function agentRetail($base, $taxClassId, $pc) {
		return $this->retail($base, $taxClassId, $pc['applyTax'], $pc['rate'], 1.0, $pc['decimals']);
	}

	/**
	 * Size and colour option values per product, with the option's own name.
	 *
	 * @return array pid => array(size => values[], color => values[])
	 */
	private function agentOptions($ids) {
		$sizeIds  = self::agentIntList($this->settings->get('size_option_ids', array()));
		$colorIds = self::agentIntList($this->settings->get('color_option_ids', array()));

		if (!$ids || (!$sizeIds && !$colorIds)) {
			return array();
		}

		$optionIds = array_unique(array_merge($sizeIds, $colorIds));

		$rows = $this->db->query("SELECT pov.product_option_value_id, pov.product_id, pov.option_id,
				pov.quantity, pov.subtract, pov.price, pov.price_prefix, ovd.name, od.name AS option_name
			FROM `" . DB_PREFIX . "product_option_value` pov
			INNER JOIN `" . DB_PREFIX . "product_option` po ON po.product_option_id = pov.product_option_id
			LEFT JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = pov.option_value_id
			LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd
				ON ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . (int)$this->languageId . "'
			LEFT JOIN `" . DB_PREFIX . "option_description` od
				ON od.option_id = pov.option_id AND od.language_id = '" . (int)$this->languageId . "'
			WHERE pov.product_id IN (" . implode(',', array_map('intval', $ids)) . ")
				AND pov.option_id IN (" . implode(',', array_map('intval', $optionIds)) . ")
			ORDER BY pov.product_id ASC, ov.sort_order ASC, pov.product_option_value_id ASC")->rows;

		$out = array();

		foreach ($rows as $row) {
			$name = AgentFormat::text((string)$row['name'], 100);
			if ($name === '') {
				continue;
			}

			$dimension = in_array((int)$row['option_id'], $sizeIds, true) ? 'size' : 'color';
			$option    = AgentFormat::text((string)$row['option_name'], 100);
			$delta     = (float)$row['price'];

			$out[(int)$row['product_id']][$dimension][] = array(
				'id'        => (int)$row['product_option_value_id'],
				'name'      => $name,
				'option'    => $option !== '' ? $option : ($dimension === 'size' ? 'Size' : 'Color'),
				'dimension' => $dimension,
				'qty'       => (int)$row['quantity'],
				'subtract'  => (int)$row['subtract'] === 1,
				'delta'     => (string)$row['price_prefix'] === '-' ? -$delta : $delta,
			);
		}

		return $out;
	}

	/** Setting value (array or "1,2,3") as a list of positive integers. */
	private static function agentIntList($raw) {
		if (!is_array($raw)) {
			$raw = ($raw === '' || $raw === null) ? array() : explode(',', (string)$raw);
		}

		return array_values(array_filter(array_map('intval', $raw)));
	}

	/** Product options in UCP shape: array(name, values[] = array(id, label)). */
	private function agentOptionList($dims) {
		$out = array();

		foreach (array('size', 'color') as $dimension) {
			if (empty($dims[$dimension])) {
				continue;
			}
			$values = array();
			foreach ($dims[$dimension] as $value) {
				$values[] = array('id' => (string)$value['id'], 'label' => $value['name']);
			}
			$out[] = array('name' => $dims[$dimension][0]['option'], 'values' => $values);
		}

		return $out;
	}

	/** Size × colour combinations, capped at 100 per product (as the Google feed). */
	private function agentCombos($dims) {
		$sizes  = isset($dims['size']) ? $dims['size'] : array();
		$colors = isset($dims['color']) ? $dims['color'] : array();
		$out    = array();

		foreach (($sizes ? $sizes : array(null)) as $size) {
			foreach (($colors ? $colors : array(null)) as $color) {
				$combo = array();
				if ($size !== null) {
					$combo[] = $size;
				}
				if ($color !== null) {
					$combo[] = $color;
				}
				if ($combo) {
					$out[] = $combo;
				}
				if (count($out) >= 100) {
					return $out;
				}
			}
		}

		return $out;
	}

	/** Approved product reviews: pid => array(count, avg). Off when the shop has reviews off. */
	private function agentRatings($ids) {
		if (!$ids || (string)$this->cfg('config_review_status') !== '1') {
			return array();
		}

		$rows = $this->db->query("SELECT product_id, COUNT(*) AS c, AVG(rating) AS a FROM `" . DB_PREFIX . "review`
			WHERE status = '1' AND product_id IN (" . implode(',', array_map('intval', $ids)) . ")
			GROUP BY product_id")->rows;

		$out = array();
		foreach ($rows as $row) {
			if ((int)$row['c'] > 0 && (float)$row['a'] > 0) {
				$out[(int)$row['product_id']] = array('count' => (int)$row['c'], 'avg' => (float)$row['a']);
			}
		}

		return $out;
	}

	/**
	 * OpenAI Product Feed: gzipped JSON Lines, the file-upload format OpenAI
	 * ingests (jsonl.gz). Written to a file first — gzip cannot be streamed to
	 * php://output portably on PHP 5.6 — then copied to $target.
	 *
	 * @param string $target filesystem path, or 'php://output'
	 * @param array  $shop   seller_name, seller_url, policy
	 * @return int rows written
	 */
	public function streamOpenAi($target, $shop) {
		if (!function_exists('gzopen')) {
			return -1;
		}

		$toOutput = strpos((string)$target, 'php://') === 0;
		$path     = $toOutput ? Ucp::quiet(function () {
			return tempnam(sys_get_temp_dir(), 'ccai');
		}) : $target;
		$gz       = $path ? Ucp::quiet(function () use ($path) {
			return gzopen($path, 'wb6');
		}) : false;

		if (!$gz) {
			return -1;
		}

		$currency = $this->settings->currencyCode();
		$decimals = max(0, min(2, (int)$this->settings->get('price_decimals', 2)));
		$pc       = $this->agentPricing($currency, $decimals);

		$shop['currency']    = $currency;
		$shop['decimals']    = $decimals;
		$shop['include_out'] = $this->settings->get('include_out_of_stock', '0') === '1';

		$flags = 0;
		if (defined('JSON_UNESCAPED_UNICODE')) {
			$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		}

		$this->agentSkipped = array();
		$whereSql = $this->productWhere();
		$pageSize = 200;
		$offset   = 0;
		$written  = 0;

		while (true) {
			$records = $this->agentRecords($whereSql, 'p.product_id ASC', $offset, $pageSize, $pc);

			foreach ($records as $record) {
				foreach (AgentFormat::openAiRows($record, $shop, $this->agentSkipped) as $row) {
					$line = json_encode($row, $flags);
					if ($line === false) {
						$this->agentSkipped['encoding'] = (isset($this->agentSkipped['encoding']) ? $this->agentSkipped['encoding'] : 0) + 1;
						continue;
					}
					gzwrite($gz, $line . "\n");
					$written++;
				}
			}

			if (count($records) < $pageSize) {
				break;
			}
			$offset += $pageSize;
		}

		gzclose($gz);

		// What the admin shows under the OpenAI switch: rows written and why
		// others were left out (no brand, no image, no price).
		$stats = json_encode(array('time' => time(), 'rows' => $written, 'skipped' => $this->agentSkipped));
		Ucp::quiet(function () use ($stats) {
			$dir = Settings::prepareCacheDir();
			file_put_contents($dir . 'openai_stats.json', $stats);
		});

		if ($toOutput) {
			readfile($path);
			Ucp::quiet(function () use ($path) {
				unlink($path);
			});
		}

		return $written;
	}

	/** Why OpenAI rows were left out on the last run: reason => count. */
	public function agentSkipped() {
		return $this->agentSkipped;
	}
}
