<?php
namespace Opencart\Catalog\Controller\Extension\CcFeeds;

use Opencart\System\Library\Ccfeeds\Settings;
use Opencart\System\Library\Ccfeeds\Generator;
use Opencart\System\Library\Ccfeeds\Ucp;
use Opencart\System\Library\Ccfeeds\UcpCatalog;
use Opencart\System\Library\Ccfeeds\AgentFormat;
use Opencart\System\Library\Ccfeeds\ShopInfo;

/**
 * Public AI-agent endpoints (no token — they describe the public storefront):
 *   /llms.txt             → index.php?route=extension/cc_feeds/agent.llms
 *   /.well-known/ucp      → index.php?route=extension/cc_feeds/agent.ucp
 *   /cc-ucp/catalog/<op>  → index.php?route=extension/cc_feeds/agent.rest&op=<op>  (POST)
 *
 * The pretty paths reach these routes either through the web-server rewrite
 * shown in the module settings, or — when the server already hands unknown
 * paths to OpenCart as `_route_` — through route(), registered as an event on
 * error/not_found (4.1 with SEO URLs) and common/home (4.0.2, or SEO URLs off).
 *
 * The library is loaded inside the methods, not at the top of the file: the
 * common/home event instantiates this class on every visit to the home page.
 */
class Agent extends \Opencart\System\Engine\Controller {

	/**
	 * Event `catalog/controller/{error/not_found,common/home}/before`.
	 * Disabled endpoints fall through to whatever the shop would show.
	 */
	public function route(string &$route = '', array &$args = []): void {
		$path = isset($this->request->get['_route_']) ? trim((string)$this->request->get['_route_'], '/') : '';

		if ($path === '' || ($path !== 'llms.txt' && $path !== '.well-known/ucp' && strpos($path, 'cc-ucp/') !== 0)) {
			return;
		}

		self::load();

		if ($path === '.well-known/ucp') {
			$this->serveUcp(false);
		} elseif ($path === 'llms.txt') {
			$this->serveLlms(false);
		} elseif (preg_match('#^' . preg_quote(Ucp::REST_PREFIX, '#') . '/catalog/(search|lookup|product)$#', $path, $m)) {
			$this->serveRest($m[1], false);
		}
	}

	public function ucp(): void {
		self::load();
		$this->serveUcp(true);
	}

	public function rest(): void {
		self::load();
		$op = (string)preg_replace('#^catalog/#', '', (string)($this->request->get['op'] ?? ''));
		$this->serveRest($op, true);
	}

	public function llms(): void {
		self::load();
		$this->serveLlms(true);
	}

	private static function load(): void {
		require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/settings.php';
		require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/generator.php';
		require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/ucpcatalog.php';
		require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/shopinfo.php';
	}

	private function storeUrl(): string {
		return rtrim((string)$this->config->get('config_url'), '/') . '/';
	}

	private function on(Settings $settings, string $key): bool {
		return $settings->get('status', '0') === '1' && $settings->get($key, '0') === '1';
	}

	private function generator(Settings $settings): Generator {
		$imageBase = rtrim((string)$this->config->get('config_url'), '/') . '/image';
		$generator = new Generator($this->db, $settings, $settings->languageId(), $imageBase, (int)$this->config->get('config_customer_group_id'));
		$generator->setProductLink(Generator::productLinkBuilder($this->url, $generator->languageCode()));
		$generator->setAgentShop(ShopInfo::build($this->db, $this->url, $this->config, $settings, $generator->languageCode()));

		return $generator;
	}

	private function notFound(): void {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		Ucp::status(404);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Not found';
		exit;
	}

	/** The profile; a plain 404 (when $answer404) while UCP is off or the shop has no HTTPS. */
	private function serveUcp(bool $answer404): void {
		$settings = new Settings($this->config);
		$store    = $this->storeUrl();

		if (!$this->on($settings, 'ucp_enabled') || Ucp::origin($store) === '') {
			if ($answer404) {
				$this->notFound();
			}

			return;
		}

		Ucp::serveProfile($store);
	}

	private function serveRest(string $op, bool $answer404): void {
		$settings = new Settings($this->config);
		$store    = $this->storeUrl();

		if (!$this->on($settings, 'ucp_enabled') || Ucp::origin($store) === '') {
			if ($answer404) {
				Ucp::send(404, Ucp::errorBody('not_found', 'UCP is not enabled on this store.'));
			}

			return;
		}

		$generator = $this->generator($settings);
		$shop      = $generator->agentShop();

		UcpCatalog::handle($op, $generator, [
			'store_url'       => $store,
			'store_id'        => (int)$this->config->get('config_store_id'),
			'cache_dir'       => DIR_CACHE . 'cc_feeds_ucp/',
			'allow_private'   => defined('CC_FEEDS_UCP_ALLOW_PRIVATE') && CC_FEEDS_UCP_ALLOW_PRIVATE === true,
			'currency'        => strtoupper((string)$this->config->get('config_currency')),
			'seller'          => AgentFormat::ucpSeller($shop['seller_name'], $shop['privacy_url'], $shop['terms_url'], $shop['return_url']),
			'google_category' => trim((string)$settings->get('google_category', '')),
			'home_url'        => $store,
		]);
	}

	private function serveLlms(bool $answer404): void {
		$settings = new Settings($this->config);

		if (!$this->on($settings, 'llms_enabled')) {
			if ($answer404) {
				$this->notFound();
			}

			return;
		}

		$generator = $this->generator($settings);
		$shop      = $generator->agentShop();
		$language  = $generator->languageCode();
		$storeId   = (int)$this->config->get('config_store_id');
		$store     = $this->storeUrl();
		$currency  = strtoupper((string)$this->config->get('config_currency'));
		$decimals  = $generator->agentCurrencyDecimals($currency);
		$limit     = max(0, min(500, (int)$settings->get('llms_products', 50)));

		$categories = [];
		foreach ($generator->agentTopCategories($storeId, 50) as $category) {
			$args = ($language !== '' ? 'language=' . $language . '&' : '') . 'path=' . (int)$category['category_id'];
			$categories[] = [
				'name'  => $category['name'],
				'url'   => str_replace('&amp;', '&', (string)$this->url->link('product/category', $args)),
				'count' => $category['count'],
			];
		}

		$products = [];
		if ($limit > 0) {
			$pc = $generator->agentPricingFor($currency, $decimals);
			foreach ($generator->agentRecords($generator->agentWhere($storeId), 'p.date_added DESC, p.product_id DESC', 0, $limit, $pc) as $record) {
				$current = $record['special'] ?? $record['regular'];
				if ($current <= 0) {
					continue;
				}
				$products[] = [
					'name'     => AgentFormat::text($record['name'], 200),
					'url'      => $record['url'],
					'price'    => AgentFormat::priceText($record, $decimals, $currency),
					'in_stock' => $record['in_stock'],
					'text'     => AgentFormat::text($record['description'], 400),
				];
			}
		}

		$policies = [];
		foreach (['privacy_url', 'terms_url', 'return_url'] as $key) {
			if ($shop[$key] !== '') {
				$policies[] = ['key' => $key, 'url' => $shop[$key]];
			}
		}

		$summary = trim((string)$settings->get('llms_summary', ''));
		if ($summary === '') {
			// Kept per language on some 4.x builds.
			$meta    = $this->config->get('config_meta_description');
			$summary = is_array($meta) ? (string)($meta[(int)$this->config->get('config_language_id')] ?? reset($meta)) : (string)$meta;
		}

		$ucpUrl = ($this->on($settings, 'ucp_enabled') && Ucp::atOriginRoot($store)) ? Ucp::profileUrl($store) : '';

		$text = AgentFormat::llms(
			[
				'name'     => AgentFormat::text((string)$this->config->get('config_name'), 200),
				'summary'  => AgentFormat::text($summary, 500),
				'currency' => $currency,
				'country'  => $generator->agentCountry(),
				'ucp_url'  => $ucpUrl,
				'home_url' => $store,
			],
			$categories,
			$products,
			$policies,
			AgentFormat::llmsLabels($language)
		);

		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		header('Content-Type: text/plain; charset=utf-8');
		header('Cache-Control: public, max-age=3600');
		header('X-Content-Type-Options: nosniff');
		echo $text;
		exit;
	}
}
