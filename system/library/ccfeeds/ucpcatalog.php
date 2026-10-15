<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * UCP Catalog over REST: POST {endpoint}/catalog/search, /catalog/lookup and
 * /catalog/product (ucp.dev 2026-08-25, Catalog REST binding).
 *
 * The storefront controller hands over a generator (with the agent trait) and
 * a context array:
 *   store_url, store_id, cache_dir, allow_private, currency, seller,
 *   google_category, home_url, search_url (callable: query => URL)
 *
 * Request flow, as the spec orders it: method and body checks → per-client
 * rate limit → UCP-Agent → platform profile fetched and validated → protocol
 * version → capability intersection → the operation. Business outcomes
 * (not-found, incompatible capabilities) are HTTP 200 with the UCP envelope;
 * transport problems are 4xx/5xx with the same error envelope.
 *
 * PHP 5.6+ syntax.
 */
class UcpCatalog {

	/** Variants returned per product in search and batch lookup listings. */
	const LIST_VARIANTS = 10;

	/** Variants returned by get_product. */
	const DETAIL_VARIANTS = 50;

	public static function handle($op, $generator, $ctx) {
		$ops = array('search' => Ucp::CAP_SEARCH, 'lookup' => Ucp::CAP_LOOKUP, 'product' => Ucp::CAP_LOOKUP);

		if (!isset($ops[$op])) {
			Ucp::send(404, Ucp::errorBody('not_found', 'Unknown catalog operation.'));
		}

		$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET');
		if ($method !== 'POST') {
			Ucp::send(405, Ucp::errorBody('method_not_allowed', 'Use POST with a JSON body.'), array('Allow: POST'));
		}

		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
		if (!Ucp::hit($ctx['cache_dir'], 'ip_' . substr(sha1($ip), 0, 16), Ucp::RATE_PER_MINUTE, 60)) {
			Ucp::send(429, Ucp::errorBody('rate_limited', 'Too many requests, retry in a minute.', '', 'recoverable'), array('Retry-After: 60'));
		}

		$body = Ucp::readBody();
		if (!$body['ok']) {
			Ucp::send($body['status'], Ucp::errorBody($body['code'], $body['message']));
		}
		$data = $body['data'];

		$agent    = isset($_SERVER['HTTP_UCP_AGENT']) ? (string)$_SERVER['HTTP_UCP_AGENT'] : '';
		$platform = Ucp::resolvePlatform(Ucp::agentProfileUrl($agent), $ctx['cache_dir'], !empty($ctx['allow_private']));
		if (!$platform['ok']) {
			$headers = $platform['retry'] > 0 ? array('Retry-After: ' . (int)$platform['retry']) : array();
			Ucp::send($platform['status'], Ucp::errorBody($platform['code'], $platform['message'], (string)$ctx['home_url'], $platform['status'] === 503 ? 'recoverable' : 'unrecoverable'), $headers);
		}

		$capability = $ops[$op];
		$deal = Ucp::negotiate($platform['profile'], $capability, (string)$ctx['home_url']);
		if (!$deal['ok']) {
			Ucp::send($deal['status'], Ucp::errorBody($deal['code'], $deal['message'], (string)$ctx['home_url']));
		}

		if ($op === 'search') {
			$result = self::search($generator, $ctx, $data);
		} elseif ($op === 'lookup') {
			$result = self::lookup($generator, $ctx, $data);
		} else {
			$result = self::product($generator, $ctx, $data);
		}

		Ucp::send($result[0], $result[1]);
	}

	private static function invalid($code, $message, $status = 400) {
		return array($status, Ucp::errorBody($code, $message));
	}

	/** Storefront pricing for UCP: the shop's display currency, as on the product page. */
	private static function pricing($generator, $ctx) {
		return $generator->agentPricingFor($ctx['currency'], $generator->agentCurrencyDecimals($ctx['currency']));
	}

	/**
	 * Price filter in minor units, or null. A filter in another currency than
	 * the presentment one is ignored with a warning (spec: MAY ignore, SHOULD
	 * say so) — converting at today's rate would quietly move the bounds.
	 */
	private static function priceFilter($data, $ctx, &$messages) {
		if (!isset($data['filters']['price']) || !is_array($data['filters']['price'])) {
			return null;
		}

		$asked = isset($data['context']['currency']) ? strtoupper((string)$data['context']['currency']) : '';
		if ($asked !== '' && $asked !== strtoupper($ctx['currency'])) {
			$messages[] = array('type' => 'warning', 'code' => 'price_filter_ignored', 'content' => 'Price filter ignored: prices are presented in ' . $ctx['currency'] . ', not ' . $asked . '.');

			return null;
		}

		$price = $data['filters']['price'];
		$min   = isset($price['min']) && is_numeric($price['min']) ? (int)$price['min'] : null;
		$max   = isset($price['max']) && is_numeric($price['max']) ? (int)$price['max'] : null;

		return ($min === null && $max === null) ? null : array('min' => $min, 'max' => $max);
	}

	/**
	 * Variant IDs of a record whose current price lies inside the filter,
	 * purchasable ones first (the first variant is the featured one).
	 */
	private static function variantsInRange($record, $filter, $currency) {
		$out  = array();
		$tail = array();

		foreach (AgentFormat::ucpItems($record) as $item) {
			if ($item['current'] <= 0) {
				continue;
			}
			$amount = Ucp::minor($item['current'], $currency);
			if ($filter !== null && (($filter['min'] !== null && $amount < $filter['min']) || ($filter['max'] !== null && $amount > $filter['max']))) {
				continue;
			}
			if ($item['in_stock']) {
				$out[] = (string)$item['id'];
			} else {
				$tail[] = (string)$item['id'];
			}
		}

		return array_merge($out, $tail);
	}

	private static function productOptions($ctx, $variantIds = null, $inputs = array(), $limit = self::LIST_VARIANTS) {
		return array(
			'currency'        => $ctx['currency'],
			'seller'          => $ctx['seller'],
			'google_category' => $ctx['google_category'],
			'variant_ids'     => $variantIds,
			'inputs'          => $inputs,
			'limit'           => $limit,
		);
	}

	// ---------------------------------------------------------------- search

	private static function search($generator, $ctx, $data) {
		$query = isset($data['query']) && is_string($data['query']) ? trim($data['query']) : '';
		$cats  = array();
		if (isset($data['filters']['categories']) && is_array($data['filters']['categories'])) {
			foreach ($data['filters']['categories'] as $value) {
				if (is_string($value) && trim($value) !== '') {
					$cats[] = trim($value);
				}
			}
		}

		$messages = array();
		$hasPrice = isset($data['filters']['price']) && is_array($data['filters']['price']);
		$price    = self::priceFilter($data, $ctx, $messages);

		// A price filter is a recognised input even when it has to be ignored
		// (other currency): the request is then a plain browse with a warning.
		if ($query === '' && !$cats && !$hasPrice) {
			return self::invalid('invalid_request', 'A search needs a non-empty "query" or at least one filter.');
		}
		if (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') > 200 : strlen($query) > 400) {
			return self::invalid('invalid_request', 'Search query is too long.');
		}

		$limit = Ucp::DEFAULT_PAGE;
		if (isset($data['pagination']['limit']) && is_numeric($data['pagination']['limit'])) {
			$limit = max(1, min(Ucp::MAX_PAGE, (int)$data['pagination']['limit']));
		}

		$offset = 0;
		if (isset($data['pagination']['cursor']) && $data['pagination']['cursor'] !== '') {
			$offset = self::readCursor((string)$data['pagination']['cursor'], $query, $cats);
			if ($offset < 0) {
				return self::invalid('invalid_cursor', 'Pagination cursor is not valid for this search.');
			}
		}

		$where = $generator->agentWhere($ctx['store_id']);

		// Category filter: values are the category paths this endpoint returns
		// (OR logic). The Google category applies to the whole catalogue.
		if ($cats) {
			$google = trim((string)$ctx['google_category']);
			$allGoogle = $google !== '' && in_array(strtolower($google), array_map('strtolower', $cats), true);
			if (!$allGoogle) {
				$ids = $generator->agentCategoryIdsByPath($cats);
				if (!$ids) {
					return array(200, array('ucp' => Ucp::responseMeta(Ucp::CAP_SEARCH), 'products' => array(), 'pagination' => array('has_next_page' => false, 'total_count' => 0)) + ($messages ? array('messages' => $messages) : array()));
				}
				$where .= $generator->agentInCategoriesSql($ids);
			}
		}

		// The text-search part is rebuilt for every query: on OpenCart 4 + pdo
		// an escaped value is a placeholder bound to one query only.
		$whereOf = function () use ($generator, $where, $query) {
			return $query === '' ? $where : $where . ' AND ' . $generator->agentSearchSql($query);
		};
		$orderOf = function () use ($generator, $query) {
			return $query === '' ? 'p.product_id ASC' : $generator->agentSearchOrder($query);
		};

		$total    = $generator->agentCount($whereOf());
		$pc       = self::pricing($generator, $ctx);
		$products = array();
		$cursor   = $offset;
		$batch    = max($limit, 20);

		while (count($products) < $limit && $cursor < $total) {
			$records = $generator->agentRecords($whereOf(), $orderOf(), $cursor, $batch, $pc);
			if (!$records) {
				break;
			}

			foreach ($records as $record) {
				$cursor++;
				$variantIds = $price !== null ? self::variantsInRange($record, $price, $ctx['currency']) : null;
				if ($variantIds !== null && !$variantIds) {
					continue;
				}
				$product = AgentFormat::ucpProduct($record, self::productOptions($ctx, $variantIds !== null ? array_slice($variantIds, 0, self::LIST_VARIANTS) : null));
				if ($product !== null) {
					$products[] = $product;
				}
				if (count($products) >= $limit) {
					break;
				}
			}

			if (count($records) < $batch) {
				break;
			}
		}

		$hasNext = $cursor < $total && count($products) >= $limit;
		$pagination = array('has_next_page' => $hasNext);
		if ($hasNext) {
			$pagination['cursor'] = self::makeCursor($cursor, $query, $cats);
		}
		if ($price === null) {
			$pagination['total_count'] = $total;
		}

		$out = array('ucp' => Ucp::responseMeta(Ucp::CAP_SEARCH), 'products' => $products, 'pagination' => $pagination);
		if ($messages) {
			$out['messages'] = $messages;
		}

		return array(200, $out);
	}

	/**
	 * Opaque cursor: offset plus a digest of the search, so a cursor replayed
	 * against a different query is refused instead of skipping results.
	 */
	private static function makeCursor($offset, $query, $cats) {
		$raw = json_encode(array('o' => (int)$offset, 'h' => substr(sha1($query . '|' . implode('|', $cats)), 0, 12)));

		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	private static function readCursor($cursor, $query, $cats) {
		$raw  = base64_decode(strtr($cursor, '-_', '+/'), true);
		$data = is_string($raw) ? json_decode($raw, true) : null;

		if (!is_array($data) || !isset($data['o'], $data['h']) || !is_int($data['o']) || $data['o'] < 0) {
			return -1;
		}
		if ($data['h'] !== substr(sha1($query . '|' . implode('|', $cats)), 0, 12)) {
			return -1;
		}

		return $data['o'];
	}

	// ---------------------------------------------------------------- lookup

	/**
	 * Resolve request identifiers. Product ID → featured variant; variant ID
	 * ("<product>-<option value>[-…]") → that variant exactly; anything else
	 * is tried as SKU/model (also a field of the returned variant).
	 *
	 * @return array array(products => pid => array(variant id => inputs[]), missing => ids[])
	 */
	private static function resolveIds($generator, $ctx, $ids) {
		$byProduct = array();
		$missing   = array();
		$wantedPid = array();
		$plan      = array();

		foreach ($ids as $id) {
			if (preg_match('/^\d+$/', $id)) {
				$plan[] = array($id, (int)$id, null);
				$wantedPid[] = (int)$id;
			} elseif (preg_match('/^(\d+)(-\d+)+$/', $id, $m)) {
				$plan[] = array($id, (int)$m[1], $id);
				$wantedPid[] = (int)$m[1];
			} else {
				$pid = $generator->agentProductBySku($id, $ctx['store_id']);
				if ($pid > 0) {
					$plan[] = array($id, $pid, 'sku');
					$wantedPid[] = $pid;
				} else {
					$missing[] = $id;
				}
			}
		}

		$records = $generator->agentRecordsByIds($wantedPid, $ctx['store_id'], self::pricing($generator, $ctx));

		foreach ($plan as $step) {
			list($input, $pid, $variant) = $step;

			if (!isset($records[$pid])) {
				$missing[] = $input;
				continue;
			}

			$items = AgentFormat::ucpItems($records[$pid]);
			$chosen = null;
			$match  = 'exact';

			if ($variant === null || $variant === 'sku') {
				// A simple product is its own variant: the ID names it exactly.
				$chosen = self::featured($items);
				$match  = (!empty($records[$pid]['variants']) && $variant === null) ? 'featured' : 'exact';
				if ($variant === 'sku' && !empty($records[$pid]['variants'])) {
					$match = 'featured';
				}
			} else {
				foreach ($items as $item) {
					if ((string)$item['id'] === $variant) {
						$chosen = $item;
						break;
					}
				}
			}

			if ($chosen === null) {
				$missing[] = $input;
				continue;
			}

			$byProduct[$pid][(string)$chosen['id']][] = array('id' => $input, 'match' => $match);
		}

		return array('records' => $records, 'products' => $byProduct, 'missing' => $missing);
	}

	/** First purchasable variant in stock, else the first one. */
	private static function featured($items) {
		foreach ($items as $item) {
			if ($item['in_stock'] && $item['current'] > 0) {
				return $item;
			}
		}
		foreach ($items as $item) {
			if ($item['current'] > 0) {
				return $item;
			}
		}

		return null;
	}

	private static function lookup($generator, $ctx, $data) {
		if (!isset($data['ids']) || !is_array($data['ids'])) {
			return self::invalid('invalid_request', '"ids" must be an array of identifiers.');
		}

		$ids = array();
		foreach ($data['ids'] as $id) {
			if (is_string($id) || is_int($id)) {
				$id = trim((string)$id);
				if ($id !== '' && strlen($id) <= 128) {
					$ids[$id] = true;
				}
			}
		}
		$ids = array_keys($ids); // duplicates MUST be deduplicated
		$ids = array_map('strval', $ids);

		if (!$ids) {
			return self::invalid('invalid_request', '"ids" must contain at least one identifier.');
		}
		if (count($ids) > Ucp::MAX_LOOKUP_IDS) {
			return self::invalid('request_too_large', 'At most ' . Ucp::MAX_LOOKUP_IDS . ' identifiers per lookup.');
		}

		$messages = array();
		$price    = self::priceFilter($data, $ctx, $messages);
		$resolved = self::resolveIds($generator, $ctx, $ids);
		$products = array();

		foreach ($resolved['products'] as $pid => $inputs) {
			$variantIds = array_keys($inputs);
			$variantIds = array_map('strval', $variantIds);

			if ($price !== null) {
				$inRange    = self::variantsInRange($resolved['records'][$pid], $price, $ctx['currency']);
				$variantIds = array_values(array_intersect($variantIds, $inRange));
				if (!$variantIds) {
					continue;
				}
			}

			$product = AgentFormat::ucpProduct($resolved['records'][$pid], self::productOptions($ctx, $variantIds, $inputs));
			if ($product !== null) {
				$products[] = $product;
			}
		}

		foreach ($resolved['missing'] as $id) {
			$messages[] = array('type' => 'info', 'code' => 'not_found', 'content' => (string)$id);
		}

		$out = array('ucp' => Ucp::responseMeta(Ucp::CAP_LOOKUP), 'products' => $products);
		if ($messages) {
			$out['messages'] = $messages;
		}

		return array(200, $out);
	}

	// --------------------------------------------------------------- product

	private static function product($generator, $ctx, $data) {
		$id = isset($data['id']) && (is_string($data['id']) || is_int($data['id'])) ? trim((string)$data['id']) : '';

		if ($id === '') {
			return self::invalid('invalid_request', '"id" is required.');
		}

		$pid     = 0;
		$variant = null;
		if (preg_match('/^\d+$/', $id)) {
			$pid = (int)$id;
		} elseif (preg_match('/^(\d+)(-\d+)+$/', $id, $m)) {
			$pid     = (int)$m[1];
			$variant = $id;
		}

		$records = $pid > 0 ? $generator->agentRecordsByIds(array($pid), $ctx['store_id'], self::pricing($generator, $ctx)) : array();
		$record  = isset($records[$pid]) ? $records[$pid] : null;
		$items   = $record !== null ? AgentFormat::ucpItems($record) : array();

		if ($variant !== null) {
			$found = false;
			foreach ($items as $item) {
				if ((string)$item['id'] === $variant) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				$record = null;
			}
		}

		if ($record === null) {
			$body = Ucp::errorBody('not_found', 'Product not found: ' . $id, (string)$ctx['home_url']);
			$body['ucp']['capabilities'] = array(Ucp::CAP_LOOKUP => array(array('version' => Ucp::VERSION)));

			return array(200, $body);
		}

		$messages = array();
		$price    = self::priceFilter($data, $ctx, $messages);
		$inRange  = self::variantsInRange($record, $price, $ctx['currency']);

		// Effective selections: a variant ID fixes them; otherwise the request's
		// `selected`, relaxed per `preferences` until some variant matches.
		$effective = array();
		$hasOptions = !empty($record['variants']);

		if ($hasOptions) {
			if ($variant !== null) {
				foreach ($items as $item) {
					if ((string)$item['id'] === $variant) {
						$effective = $item['selected'];
					}
				}
			} else {
				$effective = self::relax($items, self::requestedSelections($data), isset($data['preferences']) && is_array($data['preferences']) ? $data['preferences'] : array());
				if (!$effective) {
					$featured  = self::featured($items);
					$effective = $featured !== null ? $featured['selected'] : array();
				}
			}
		}

		// Matching variants, the requested (or featured) one first.
		$ordered = array();
		if ($variant !== null) {
			$ordered[] = $variant;
		}
		$featured = self::featured(self::matching($items, $effective));
		if ($featured !== null && !in_array((string)$featured['id'], $ordered, true)) {
			$ordered[] = (string)$featured['id'];
		}
		foreach (self::matching($items, $effective) as $item) {
			if (!in_array((string)$item['id'], $ordered, true)) {
				$ordered[] = (string)$item['id'];
			}
		}

		$ordered = array_values(array_intersect($ordered, $inRange));
		if (!$ordered) {
			$body = Ucp::errorBody('not_found', 'No variant of ' . $id . ' matches the filters.', (string)$record['url']);
			$body['ucp']['capabilities'] = array(Ucp::CAP_LOOKUP => array(array('version' => Ucp::VERSION)));

			return array(200, $body);
		}

		$product = AgentFormat::ucpProduct($record, self::productOptions($ctx, array_slice($ordered, 0, self::DETAIL_VARIANTS), array(), self::DETAIL_VARIANTS));

		if ($hasOptions && $product !== null) {
			$product['selected'] = array();
			foreach ($effective as $sel) {
				$product['selected'][] = array('name' => $sel['name'], 'id' => (string)$sel['id'], 'label' => $sel['label']);
			}
			if (isset($product['options'])) {
				$product['options'] = self::signals($product['options'], $items, $effective);
			}
		}

		$out = array('ucp' => Ucp::responseMeta(Ucp::CAP_LOOKUP), 'product' => $product);
		if ($messages) {
			$out['messages'] = $messages;
		}

		return array(200, $out);
	}

	/** `selected` from the request: array(name, label, id?) entries, one per name. */
	private static function requestedSelections($data) {
		$out = array();

		if (!isset($data['selected']) || !is_array($data['selected'])) {
			return $out;
		}

		foreach ($data['selected'] as $sel) {
			if (!is_array($sel) || !isset($sel['name']) || !is_string($sel['name'])) {
				continue;
			}
			$name = trim($sel['name']);
			if ($name === '' || isset($out[$name])) {
				continue;
			}
			$out[$name] = array(
				'name'  => $name,
				'label' => isset($sel['label']) && is_string($sel['label']) ? trim($sel['label']) : '',
				'id'    => isset($sel['id']) && (is_string($sel['id']) || is_int($sel['id'])) ? (string)$sel['id'] : '',
			);
		}

		return array_values($out);
	}

	/** Does an item carry this selection? The option value ID wins over the label. */
	private static function itemHas($item, $sel) {
		foreach ($item['selected'] as $own) {
			if (strcasecmp($own['name'], $sel['name']) !== 0) {
				continue;
			}
			if ($sel['id'] !== '') {
				return (string)$own['id'] === $sel['id'];
			}

			return function_exists('mb_strtolower')
				? mb_strtolower($own['label'], 'UTF-8') === mb_strtolower($sel['label'], 'UTF-8')
				: strcasecmp($own['label'], $sel['label']) === 0;
		}

		return false;
	}

	private static function matching($items, $selections) {
		$out = array();

		foreach ($items as $item) {
			$ok = true;
			foreach ($selections as $sel) {
				if (!self::itemHas($item, $sel)) {
					$ok = false;
					break;
				}
			}
			if ($ok) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Drop selections from the end of `preferences` (then from the end of the
	 * request) until at least one variant matches. Returns the item's own
	 * spelling of each kept selection.
	 */
	private static function relax($items, $selections, $preferences) {
		if (!$selections) {
			return array();
		}

		$order = array();
		foreach ($preferences as $name) {
			if (is_string($name)) {
				$order[] = $name;
			}
		}
		foreach ($selections as $sel) {
			if (!in_array($sel['name'], $order, true)) {
				$order[] = $sel['name'];
			}
		}

		while ($selections && $order) {
			$match = self::matching($items, $selections);
			if ($match) {
				$out = array();
				foreach ($selections as $sel) {
					foreach ($match[0]['selected'] as $own) {
						if (strcasecmp($own['name'], $sel['name']) === 0) {
							$out[] = $own;
						}
					}
				}

				return $out;
			}

			// Relax the lowest-priority selection still present.
			for ($i = count($order) - 1; $i >= 0; $i--) {
				$drop = $order[$i];
				$before = count($selections);
				$selections = array_values(array_filter($selections, function ($sel) use ($drop) {
					return strcasecmp($sel['name'], $drop) !== 0;
				}));
				array_splice($order, $i, 1);
				if (count($selections) < $before) {
					break;
				}
			}
		}

		return array();
	}

	/**
	 * Availability signals on option values, relative to the effective
	 * selections: for a value of option O, keep every selection except O's and
	 * add O = value. exists = some variant matches, available = one of those is
	 * in stock.
	 */
	private static function signals($options, $items, $effective) {
		foreach ($options as $o => $option) {
			$others = array();
			foreach ($effective as $sel) {
				if (strcasecmp($sel['name'], $option['name']) !== 0) {
					$others[] = $sel;
				}
			}

			foreach ($option['values'] as $v => $value) {
				$probe   = $others;
				$probe[] = array('name' => $option['name'], 'label' => $value['label'], 'id' => (string)$value['id']);
				$exists  = false;
				$available = false;

				foreach (self::matching($items, $probe) as $item) {
					$exists = true;
					if ($item['in_stock'] && $item['current'] > 0) {
						$available = true;
						break;
					}
				}

				$options[$o]['values'][$v]['available'] = $available;
				$options[$o]['values'][$v]['exists']    = $exists;
			}
		}

		return $options;
	}
}
