<?php
namespace Opencart\System\Library\Ccfeeds;

/**
 * Epicentr marketplace feed in Epicentr's own XML (1.4.1).
 *
 * Epicentr does not read the Rozetka/Prom YML. Both of its file scenarios —
 * "Товари → Імпорт" (supportm.epicentrk.ua/xmlfayl) and "Автооновлення
 * залишків" by URL (supportm.epicentrk.ua/avtoonovlennya) — describe the same
 * root: `yml_catalog[date] > offers > offer[id,available]`, with no `<shop>`
 * level, no currencies and no category list. A YML with `<shop>` is rejected
 * by the import as «Невідомий тег shop у рядку 3».
 *
 * Modes (setting epicentr_mode):
 *  - short — the auto-update file: price, price_old, availability;
 *  - full  — plus name (ua, and ru when the shop has Russian), description,
 *            photos and barcode, for "Оновити все" of products already in the
 *            cabinet.
 * New products are not created from this file: Epicentr wants its own
 * category, attribute-set and characteristic codes (PIM).
 *
 * Out-of-stock products are always in the file (available="false",
 * out_of_stock): a product that just disappears from the file keeps its old
 * status in Epicentr unless the seller ticks a separate cabinet option.
 * Prices are in hryvnias — the instruction accepts nothing else.
 *
 * Mixed into Generator; uses its pricing, specials and image helpers.
 */
trait EpicentrFeed {

	private function streamEpicentr(string $target): int {
		$full        = $this->settings->get('epicentr_mode', 'short') === 'full';
		$useSpecial  = $this->settings->get('use_special', '1') === '1';
		$stockThresh = (int)$this->settings->get('stock_threshold', 0);
		$imageLimit  = max(1, (int)$this->settings->get('image_limit', 10));
		$decimals    = (int)$this->settings->get('price_decimals', 2);
		$pc          = $this->pricing($this->epicentrCurrency());
		$langs       = $full ? $this->epicentrLanguages() : [];

		$w = new \XMLWriter();
		$w->openUri($target);
		$w->setIndent(true);
		$w->setIndentString('  ');
		$w->startDocument('1.0', 'UTF-8');

		// «рік-місяць-день години:хвилини», the time the file was generated.
		$w->startElement('yml_catalog');
		$w->writeAttribute('date', date('Y-m-d H:i'));
		$w->startElement('offers');

		$whereSql = $this->productWhere(false);
		$pageSize = 500;
		$offset   = 0;
		$written  = 0;

		while (true) {
			$rows = $this->db->query("SELECT p.product_id, p.ean, p.quantity, p.price, p.tax_class_id
				FROM `" . DB_PREFIX . "product` p
				WHERE " . $whereSql . "
				ORDER BY p.product_id ASC
				LIMIT " . (int)$offset . ", " . (int)$pageSize)->rows;

			if (!$rows) {
				break;
			}

			$ids      = array_map(static fn($r) => (int)$r['product_id'], $rows);
			$specials = $useSpecial ? $this->batchSpecials($ids) : [];
			$images   = $full ? $this->batchImages($ids, $imageLimit) : [];
			$texts    = $full ? $this->epicentrTexts($ids, $langs) : [];

			foreach ($rows as $r) {
				$pid = (int)$r['product_id'];

				[$regular, $special] = $this->productPrices($r, $specials, $pc);
				$price = $special ?? $regular;
				if ($price <= 0) {
					continue;
				}

				$inStk = (int)$r['quantity'] > $stockThresh;

				// The article Epicentr matches against «Артикул» in the cabinet:
				// the product ID — digits only, as the instruction asks.
				$w->startElement('offer');
				$w->writeAttribute('id', (string)$pid);
				$w->writeAttribute('available', $inStk ? 'true' : 'false');

				$w->writeElement('price', number_format($price, $decimals, '.', ''));
				if ($special !== null && $regular > $special) {
					$w->writeElement('price_old', number_format($regular, $decimals, '.', ''));
				}
				$w->writeElement('availability', $inStk ? 'in_stock' : 'out_of_stock');

				if ($full) {
					$this->writeEpicentrCard($w, $images[$pid] ?? [], $texts[$pid] ?? [], trim((string)$r['ean']));
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

		$w->endElement(); // offers
		$w->endElement(); // yml_catalog
		$w->endDocument();
		$w->flush();

		return $written;
	}

	/** Hryvnias when the shop has the currency at all; otherwise the feed currency (the admin warns). */
	private function epicentrCurrency(): string {
		return $this->currencyValue('UAH') > 0 ? 'UAH' : $this->settings->currencyCode();
	}

	private function writeEpicentrCard(\XMLWriter $w, array $images, array $texts, string $ean): void {
		$pictures = [];
		foreach ($images as $url) {
			// Several photos go into ONE <picture>, separated by «, », so a comma
			// or space inside a URL would split it in two.
			$pictures[] = str_replace([' ', ','], ['%20', '%2C'], $url);
		}
		if ($pictures) {
			$w->writeElement('picture', implode(', ', $pictures));
		}

		$suffix = trim((string)$this->settings->get('name_suffix', ''));

		foreach (['ua', 'ru'] as $lang) {
			$name = self::epicentrPlain((string)($texts[$lang]['name'] ?? ''));
			if ($name !== '' && $suffix !== '') {
				$name .= ' ' . $suffix;
			}
			$name = self::epicentrCut($name, 150);
			if ($name === '') {
				continue;
			}
			$w->startElement('name');
			$w->writeAttribute('lang', $lang);
			$w->text($name);
			$w->endElement();
		}

		$mode  = (string)$this->settings->get('description_mode', 'full');
		$strip = $this->settings->get('strip_html', '0') === '1';

		foreach (['ua', 'ru'] as $lang) {
			if ($mode === 'none' || !isset($texts[$lang])) {
				continue;
			}
			$raw = html_entity_decode((string)$texts[$lang]['description'], ENT_QUOTES, 'UTF-8');
			if ($strip || $mode === 'short') {
				$raw = self::epicentrText($raw);
				if ($mode === 'short') {
					$raw = self::epicentrCut($raw, 500);
				}
				$raw = htmlspecialchars($raw, ENT_NOQUOTES, 'UTF-8');
			}
			$html = self::epicentrHtml($raw);
			if ($html === '') {
				continue;
			}
			$w->startElement('description');
			$w->writeAttribute('lang', $lang);
			$w->writeCdata($html);
			$w->endElement();
		}

		if ($ean !== '') {
			$w->startElement('param');
			$w->writeAttribute('paramcode', 'barcodes');
			$w->writeAttribute('name', 'Штрих код');
			$w->writeCdata($ean);
			$w->endElement();
		}
	}

	/**
	 * Epicentr's languages: ua is required, ru optional. The shop's Ukrainian
	 * (code uk*) feeds `ua`, falling back to the feed language; Russian (ru*)
	 * feeds `ru` when the shop has it.
	 *
	 * @return array{ua:int,ru:int}
	 */
	private function epicentrLanguages(): array {
		$ua = 0;
		$ru = 0;

		foreach ($this->db->query("SELECT language_id, code FROM `" . DB_PREFIX . "language` WHERE status = 1 ORDER BY sort_order ASC, language_id ASC")->rows as $row) {
			$code = strtolower((string)$row['code']);
			if ($ua === 0 && strpos($code, 'uk') === 0) {
				$ua = (int)$row['language_id'];
			} elseif ($ru === 0 && strpos($code, 'ru') === 0) {
				$ru = (int)$row['language_id'];
			}
		}

		if ($ua === 0) {
			$ua = (int)$this->languageId;
		}

		return ['ua' => $ua, 'ru' => $ru !== $ua ? $ru : 0];
	}

	/**
	 * @param int[] $ids
	 * @return array<int,array<string,array{name:string,description:string}>>
	 */
	private function epicentrTexts(array $ids, array $langs): array {
		$byId = [];
		foreach ($langs as $lang => $id) {
			if ($id > 0) {
				$byId[(int)$id] = $lang;
			}
		}
		if (!$ids || !$byId) {
			return [];
		}

		$rows = $this->db->query("SELECT product_id, language_id, name, description
			FROM `" . DB_PREFIX . "product_description`
			WHERE product_id IN (" . implode(',', array_map('intval', $ids)) . ")
				AND language_id IN (" . implode(',', array_keys($byId)) . ")")->rows;

		$out = [];
		foreach ($rows as $row) {
			$out[(int)$row['product_id']][$byId[(int)$row['language_id']]] = [
				'name'        => (string)$row['name'],
				'description' => (string)$row['description'],
			];
		}

		return $out;
	}

	/** Description reduced to the tags Epicentr keeps (p br b ul li table tr td), ≤ 12160 characters. */
	public static function epicentrHtml(string $raw): string {
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}

		// Code and styles go away with their content, not just their tags.
		$raw = (string)preg_replace('~<(script|style|iframe|noscript|svg)\b[^>]*>.*?</\1\s*>~is', '', $raw);
		$raw = (string)preg_replace('~<(/?)strong\b[^>]*>~i', '<$1b>', $raw);
		$raw = (string)preg_replace('~<(/?)ol\b[^>]*>~i', '<$1ul>', $raw);
		$raw = (string)preg_replace('~<(/?)th\b[^>]*>~i', '<$1td>', $raw);
		$raw = (string)preg_replace('~<(/?)(h[1-6]|div)\b[^>]*>~i', '<$1p>', $raw);

		if (strpos($raw, '<') === false) {
			$paragraphs = preg_split('/\R\s*\R/u', $raw);
			$paragraphs = $paragraphs ? $paragraphs : [$raw];
			$raw        = '<p>' . implode('</p><p>', array_map('trim', $paragraphs)) . '</p>';
			$raw        = (string)preg_replace('/\R/u', '<br>', $raw);
		}

		$html = strip_tags($raw, '<p><br><b><ul><li><table><tr><td>');
		$html = (string)preg_replace('~<(p|br|b|ul|li|table|tr|td)\s[^>]*>~i', '<$1>', $html);
		do {
			// Nested empty blocks (<div><p></p></div>) unwrap one level per pass.
			$before = $html;
			$html   = (string)preg_replace('~<p>\s*</p>~', '', $html);
		} while ($html !== $before);
		$html = trim($html);

		if (self::epicentrLen($html) > 12160) {
			// Cutting HTML mid-tag would leave it broken; plain text is safe.
			$html = '<p>' . htmlspecialchars(self::epicentrCut(self::epicentrText($html), 12153), ENT_NOQUOTES, 'UTF-8') . '</p>';
		}

		return $html;
	}

	/** Plain text with line breaks where blocks ended. */
	private static function epicentrText(string $html): string {
		$text = (string)preg_replace('~<(br|/p|/li|/tr|/div|/h[1-6])\b[^>]*>~i', "\n", $html);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
		$text = (string)preg_replace("/[ \t]+/u", ' ', $text);

		return trim((string)preg_replace("/\n\s*\n+/", "\n\n", $text));
	}

	private static function epicentrPlain(string $text): string {
		// OpenCart keeps names HTML-encoded once; XMLWriter escapes once more.
		return trim(strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
	}

	private static function epicentrLen(string $text): int {
		return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int)preg_match_all('/./us', $text);
	}

	private static function epicentrCut(string $text, int $max): string {
		if (self::epicentrLen($text) <= $max) {
			return $text;
		}
		if (function_exists('mb_substr')) {
			return rtrim(mb_substr($text, 0, $max, 'UTF-8'));
		}

		return preg_match('/^.{0,' . $max . '}/us', $text, $m) ? rtrim($m[0]) : $text;
	}
}
