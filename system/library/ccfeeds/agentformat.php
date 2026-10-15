<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * Output formats for AI agents, built from one normalized product record:
 *  - OpenAI Product Feed rows (JSON Lines, file-upload spec at
 *    developers.openai.com/commerce/specs/file-upload/products, read 15.10.2026);
 *  - UCP catalog Product / Variant objects (ucp.dev 2026-08-25);
 *  - the /llms.txt document (llmstxt.org).
 *
 * No database access here — the generator trait builds the records, so the
 * same prices, stock rule, images and texts reach every format. The field set
 * and order of the OpenAI rows follow the WooCommerce build of Market Feeds,
 * so a catalogue looks the same to ChatGPT whichever platform the shop runs.
 *
 * Record shape (see CcFeedsAgentFeed::agentRecords()):
 *   product_id, name, description (raw), url, images[], brand, gtin, sku, tags,
 *   category_path, in_stock, regular, special|null, rating|null,
 *   options[]  = array(name, values[] = array(id, label)),
 *   variants[] = array(id, selected[] = array(name, label, id), dims = array(size, color),
 *                      in_stock, regular, special|null)  — empty for a simple product.
 *
 * PHP 5.6+ syntax: the OpenCart 2.3 build still runs there.
 */
class AgentFormat {

	/** Plain one-line text: tags and repeated spaces gone, entities decoded twice, cut to $max. */
	public static function text($text, $max) {
		$text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
		$text = preg_replace('/<\s*br\s*\/?>|<\/\s*(p|li|div|h\d)\s*>/iu', ' ', $text);
		$text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8');
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));

		return self::cut($text, $max);
	}

	/** UTF-8 safe truncate that survives hosts without mbstring. */
	public static function cut($text, $len) {
		$text = (string)$text;

		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, (int)$len, 'UTF-8');
		}

		return preg_match('/^.{0,' . (int)$len . '}/us', $text, $m) ? $m[0] : substr($text, 0, (int)$len);
	}

	/**
	 * GTIN-8/12/13/14 with a correct GS1 check digit — the OpenAI spec asks
	 * for identifiers validated before upload.
	 */
	public static function gtinValid($gtin) {
		$gtin = (string)$gtin;

		if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $gtin)) {
			return false;
		}

		$digits = array_map('intval', str_split($gtin));
		$check  = array_pop($digits);
		$sum    = 0;
		$weight = 3;

		for ($i = count($digits) - 1; $i >= 0; $i--) {
			$sum   += $digits[$i] * $weight;
			$weight = $weight === 3 ? 1 : 3;
		}

		return (10 - ($sum % 10)) % 10 === $check;
	}

	/** "1234.50 UAH": decimal point, no thousands separator, ISO 4217 code. */
	public static function money($value, $decimals, $currency) {
		return number_format((float)$value, (int)$decimals, '.', '') . ' ' . strtoupper((string)$currency);
	}

	/**
	 * Purchasable items of a record: its variants, or the product itself.
	 *
	 * @return array[] array(id, group, selected, dims, in_stock, regular, special)
	 */
	public static function items($record) {
		if (!empty($record['variants'])) {
			$out = array();
			foreach ($record['variants'] as $variant) {
				$variant['group'] = (string)$record['product_id'];
				$out[] = $variant;
			}

			return $out;
		}

		return array(array(
			'id'       => (string)$record['product_id'],
			'group'    => '',
			'selected' => array(),
			'dims'     => array(),
			'in_stock' => (bool)$record['in_stock'],
			'regular'  => (float)$record['regular'],
			'special'  => $record['special'],
		));
	}

	/**
	 * OpenAI Product Feed rows for one record — one row per purchasable item.
	 *
	 * A row the shop cannot fill honestly (no positive price, no image, no
	 * brand) is skipped and counted, never padded: the spec forbids
	 * placeholder values and rejects such a row anyway.
	 *
	 * @param array $record
	 * @param array $shop  seller_name, seller_url, currency, decimals, include_out, policy (array)
	 * @param array $skipped reason => count, updated in place
	 * @return array[]
	 */
	public static function openAiRows($record, $shop, &$skipped) {
		$rows   = array();
		$images = array_values($record['images']);
		$brand  = self::cut(trim((string)$record['brand']), 70);
		$title  = self::text($record['name'], 1000);
		$text   = self::text($record['description'], 5000);
		$path   = (string)$record['category_path'];

		foreach (self::items($record) as $item) {
			if (!$item['in_stock'] && empty($shop['include_out'])) {
				continue;
			}

			$current = $item['special'] !== null ? (float)$item['special'] : (float)$item['regular'];
			$regular = (float)$item['regular'];

			if ($current <= 0 || $regular <= 0) {
				self::skip($skipped, 'no_price');
				continue;
			}
			if (!$images) {
				self::skip($skipped, 'no_image');
				continue;
			}
			if ($brand === '') {
				self::skip($skipped, 'no_brand');
				continue;
			}

			$labels = array();
			foreach ($item['selected'] as $option) {
				$labels[] = $option['label'];
			}
			$name = $labels ? $title . ' (' . implode(', ', $labels) . ')' : $title;

			$row = array(
				'item_id'      => (string)$item['id'],
				'title'        => self::cut($name, 150),
				'description'  => $text !== '' ? $text : self::cut($name, 5000),
				'url'          => (string)$record['url'],
				'brand'        => $brand,
				'seller_name'  => (string)$shop['seller_name'],
				'image_url'    => (string)$images[0],
				'availability' => $item['in_stock'] ? 'in_stock' : 'out_of_stock',
			);

			$onSale = $item['special'] !== null && $regular > $current;
			$row['price'] = self::money($onSale ? $regular : $current, $shop['decimals'], $shop['currency']);
			if ($onSale) {
				$row['sale_price'] = self::money($current, $shop['decimals'], $shop['currency']);
			}

			if (count($images) > 1) {
				$row['additional_image_urls'] = array_values(array_slice($images, 1, 10));
			}

			if ($item['group'] !== '') {
				$row['group_id']               = $item['group'];
				$row['listing_has_variations'] = true;
				$dict = array();
				foreach ($item['selected'] as $option) {
					$dict[$option['name']] = $option['label'];
				}
				if ($dict) {
					$row['variant_dict'] = $dict;
				}
			}

			// EAN belongs to the product: OpenCart options have no barcode of
			// their own, and one GTIN on every size would be wrong.
			if ($item['group'] === '' && self::gtinValid($record['gtin'])) {
				$row['gtin'] = (string)$record['gtin'];
			}

			$row['condition'] = 'new';

			if ($path !== '') {
				$row['product_category'] = self::cut($path, 750);
			}

			foreach (array('size', 'color') as $dimension) {
				if (isset($item['dims'][$dimension]) && $item['dims'][$dimension] !== '') {
					$row[$dimension] = self::cut($item['dims'][$dimension], 100);
				}
			}

			if (!empty($record['rating'])) {
				$row['review_count'] = (int)$record['rating']['count'];
				$row['star_rating']  = number_format((float)$record['rating']['avg'], 2, '.', '');
			}

			if ((string)$shop['seller_url'] !== '') {
				$row['seller_url'] = (string)$shop['seller_url'];
			}

			$rows[] = array_merge($row, $shop['policy']);
		}

		return $rows;
	}

	private static function skip(&$skipped, $reason) {
		$skipped[$reason] = (isset($skipped[$reason]) ? $skipped[$reason] : 0) + 1;
	}

	/**
	 * Shop-wide policy fields of the OpenAI feed. Returns are written only
	 * when the owner said so: an empty value in the spec means "unspecified",
	 * and guessing "accepts returns" would be a promise.
	 */
	public static function openAiPolicy($accepts, $days, $returnUrl, $privacyUrl, $termsUrl) {
		$out = array();

		if ($accepts === 'yes') {
			$out['accepts_returns'] = true;
			if ((int)$days > 0) {
				$out['return_deadline_in_days'] = (int)$days;
			}
		} elseif ($accepts === 'no') {
			$out['accepts_returns'] = false;
		}

		if (self::isUrl($returnUrl)) {
			$out['return_policy'] = (string)$returnUrl;
		}
		if (self::isUrl($privacyUrl)) {
			$out['seller_privacy_policy'] = (string)$privacyUrl;
		}
		if (self::isUrl($termsUrl)) {
			$out['seller_tos'] = (string)$termsUrl;
		}

		return $out;
	}

	public static function isUrl($url) {
		return is_string($url) && preg_match('~^https?://[^\s]+$~i', $url) === 1;
	}

	/**
	 * UCP Seller object shared by every variant.
	 */
	public static function ucpSeller($name, $privacyUrl, $termsUrl, $returnUrl) {
		$seller = array('name' => (string)$name);
		$links  = array();

		if (self::isUrl($privacyUrl)) {
			$links[] = array('type' => 'privacy_policy', 'url' => (string)$privacyUrl);
		}
		if (self::isUrl($termsUrl)) {
			$links[] = array('type' => 'terms_of_service', 'url' => (string)$termsUrl);
		}
		if (self::isUrl($returnUrl)) {
			$links[] = array('type' => 'refund_policy', 'url' => (string)$returnUrl);
		}
		if ($links) {
			$seller['links'] = $links;
		}

		return $seller;
	}

	/**
	 * Variants of a record in UCP terms. A simple product is its own single
	 * variant with the product's ID.
	 */
	public static function ucpItems($record) {
		$items = self::items($record);

		foreach ($items as $i => $item) {
			$current = $item['special'] !== null ? (float)$item['special'] : (float)$item['regular'];
			$items[$i]['current'] = $current;
		}

		return $items;
	}

	/**
	 * Full UCP Product.
	 *
	 * @param array      $record
	 * @param array      $opts currency, seller, google_category, variant_ids (null = first LIST_VARIANTS),
	 *                         inputs (variant id => inputs[]), limit
	 * @return array|null null when no variant has a price
	 */
	public static function ucpProduct($record, $opts) {
		$currency = (string)$opts['currency'];
		$all      = self::ucpItems($record);

		// Listings put purchasable variants first: platforms treat the first
		// variant as the featured one. usort is not stable before PHP 8, so
		// the original position breaks ties.
		foreach ($all as $i => $item) {
			$all[$i]['_pos'] = $i;
		}
		usort($all, function ($a, $b) {
			if ($a['in_stock'] !== $b['in_stock']) {
				return $a['in_stock'] ? -1 : 1;
			}

			return $a['_pos'] - $b['_pos'];
		});
		$wanted   = isset($opts['variant_ids']) ? $opts['variant_ids'] : null;
		$limit    = isset($opts['limit']) ? (int)$opts['limit'] : 10;
		$inputs   = isset($opts['inputs']) ? $opts['inputs'] : array();
		$name     = self::text($record['name'], 1000);
		$text     = self::text($record['description'], 5000);

		$rows = array();
		$min = null;
		$max = null;
		$lmin = null;
		$lmax = null;

		foreach ($all as $item) {
			if ($item['current'] <= 0) {
				continue;
			}

			$amount = Ucp::minor($item['current'], $currency);
			$list   = Ucp::minor($item['regular'], $currency);
			$min    = $min === null ? $amount : min($min, $amount);
			$max    = $max === null ? $amount : max($max, $amount);
			$lmin   = $lmin === null ? $list : min($lmin, $list);
			$lmax   = $lmax === null ? $list : max($lmax, $list);

			if ($wanted !== null && !in_array((string)$item['id'], $wanted, true)) {
				continue;
			}
			if ($wanted === null && count($rows) >= $limit) {
				continue;
			}

			$labels = array();
			$opts2  = array();
			foreach ($item['selected'] as $option) {
				$labels[] = $option['label'];
				$opts2[]  = array('name' => $option['name'], 'id' => (string)$option['id'], 'label' => $option['label']);
			}

			$row = array(
				'id'          => (string)$item['id'],
				'title'       => $labels ? implode(' / ', $labels) : $name,
				'description' => array('plain' => $text !== '' ? $text : $name),
				'url'         => (string)$record['url'],
				'price'       => array('amount' => max(0, $amount), 'currency' => $currency),
			);

			if (trim((string)$record['sku']) !== '' && $item['group'] === '') {
				$row['sku'] = trim((string)$record['sku']);
			}
			if ($item['group'] === '' && self::gtinValid($record['gtin'])) {
				$row['barcodes'] = array(array('type' => 'GTIN', 'value' => (string)$record['gtin']));
			}
			if ($list > $amount) {
				$row['list_price'] = array('amount' => $list, 'currency' => $currency);
			}

			$row['availability'] = array(
				'available' => (bool)$item['in_stock'],
				'status'    => $item['in_stock'] ? 'in_stock' : 'out_of_stock',
			);

			if ($opts2) {
				$row['options'] = $opts2;
			}
			if (isset($inputs[(string)$item['id']])) {
				$row['inputs'] = $inputs[(string)$item['id']];
			}
			if (!empty($opts['seller'])) {
				$row['seller'] = $opts['seller'];
			}

			$rows[] = $row;
		}

		if (!$rows || $min === null) {
			return null;
		}

		// A requested variant goes first: platforms treat the first one as featured.
		if ($wanted !== null && count($wanted) > 0) {
			usort($rows, function ($a, $b) use ($wanted) {
				return array_search($a['id'], $wanted, true) - array_search($b['id'], $wanted, true);
			});
		}

		$out = array(
			'id'          => (string)$record['product_id'],
			'title'       => $name,
			'description' => array('plain' => $text !== '' ? $text : $name),
			'url'         => (string)$record['url'],
			'price_range' => array(
				'min' => array('amount' => $min, 'currency' => $currency),
				'max' => array('amount' => $max, 'currency' => $currency),
			),
		);

		if ($lmin !== null && ($lmin > $min || $lmax > $max)) {
			$out['list_price_range'] = array(
				'min' => array('amount' => $lmin, 'currency' => $currency),
				'max' => array('amount' => $lmax, 'currency' => $currency),
			);
		}

		$categories = array();
		if ((string)$record['category_path'] !== '') {
			$categories[] = array('value' => (string)$record['category_path'], 'taxonomy' => 'merchant');
		}
		if (!empty($opts['google_category'])) {
			$categories[] = array('value' => (string)$opts['google_category'], 'taxonomy' => 'google_product_category');
		}
		if ($categories) {
			$out['categories'] = $categories;
		}

		$media = array();
		foreach (array_values($record['images']) as $url) {
			$media[] = array('type' => 'image', 'url' => (string)$url, 'alt_text' => $name);
		}
		if ($media) {
			$out['media'] = $media;
		}

		$options = array();
		foreach ($record['options'] as $option) {
			$values = array();
			foreach ($option['values'] as $value) {
				$values[] = array('id' => (string)$value['id'], 'label' => (string)$value['label']);
			}
			if ($values) {
				$options[] = array('name' => (string)$option['name'], 'values' => $values);
			}
		}
		if ($options) {
			$out['options'] = $options;
		}

		$out['variants'] = $rows;

		if (!empty($record['rating'])) {
			$out['rating'] = array(
				'value'     => round((float)$record['rating']['avg'], 2),
				'scale_min' => 1,
				'scale_max' => 5,
				'count'     => (int)$record['rating']['count'],
			);
		}

		$tags = array();
		foreach (explode(',', (string)$record['tags']) as $tag) {
			$tag = self::text($tag, 100);
			if ($tag !== '') {
				$tags[] = $tag;
			}
		}
		if ($tags) {
			$out['tags'] = array_values(array_unique($tags));
		}

		if ((string)$record['brand'] !== '') {
			$out['metadata'] = array('brand' => self::cut((string)$record['brand'], 70));
		}

		return $out;
	}

	/**
	 * /llms.txt (llmstxt.org): H1 with the shop name, a blockquote summary,
	 * a few plain lines, then H2 "file lists" of links.
	 *
	 * @param array $shop  name, summary, url, currency, phone, ucp_url
	 * @param array $categories array(name, url, count)
	 * @param array $products   array(name, url, price, in_stock)
	 * @param array $policies   array(title, url)
	 */
	public static function llms($shop, $categories, $products, $policies, $labels) {
		$md = array();
		$md[] = '# ' . self::line($shop['name']);
		$md[] = '';

		if ((string)$shop['summary'] !== '') {
			$md[] = '> ' . self::line($shop['summary']);
			$md[] = '';
		}

		$md[] = sprintf($labels['intro'], self::line($shop['url']), self::line($shop['currency']));
		if ((string)$shop['phone'] !== '') {
			$md[] = sprintf($labels['phone'], self::line($shop['phone']));
		}
		$md[] = '';

		if ($categories) {
			$md[] = '## ' . $labels['categories'];
			$md[] = '';
			foreach ($categories as $category) {
				$md[] = '- [' . self::link($category['name']) . '](' . $category['url'] . ')' . ($category['count'] > 0 ? ': ' . sprintf($labels['count'], (int)$category['count']) : '');
			}
			$md[] = '';
		}

		if ($products) {
			$md[] = '## ' . $labels['products'];
			$md[] = '';
			foreach ($products as $product) {
				$md[] = '- [' . self::link($product['name']) . '](' . $product['url'] . '): ' . $product['price'] . ', ' . ($product['in_stock'] ? $labels['in_stock'] : $labels['out_of_stock']);
			}
			$md[] = '';
		}

		if ($policies) {
			$md[] = '## ' . $labels['policies'];
			$md[] = '';
			foreach ($policies as $policy) {
				$md[] = '- [' . self::link($policy['title']) . '](' . $policy['url'] . ')';
			}
			$md[] = '';
		}

		if ((string)$shop['ucp_url'] !== '') {
			$md[] = '## ' . $labels['machine'];
			$md[] = '';
			$md[] = '- [UCP business profile](' . $shop['ucp_url'] . '): ' . $labels['ucp'];
			$md[] = '';
		}

		return implode("\n", $md);
	}

	/** Fixed English labels of /llms.txt: agents read them, the catalogue keeps its own language. */
	public static function llmsLabels() {
		return array(
			'intro'        => 'Online store: %s. Prices are in %s and match the product pages.',
			'phone'        => 'Phone: %s.',
			'categories'   => 'Categories',
			'count'        => '%d products',
			'products'     => 'Products',
			'in_stock'     => 'in stock',
			'out_of_stock' => 'out of stock',
			'policies'     => 'Policies',
			'machine'      => 'Machine-readable',
			'ucp'          => 'Universal Commerce Protocol catalog search and lookup',
		);
	}

	/** One line of Markdown text: no newlines. */
	private static function line($text) {
		return trim((string)preg_replace('/\s+/u', ' ', (string)$text));
	}

	/** Link text without the brackets that would break `[text](url)`. */
	private static function link($text) {
		return str_replace(array('[', ']'), array('(', ')'), self::line($text));
	}
}
