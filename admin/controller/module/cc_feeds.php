<?php
namespace Opencart\Admin\Controller\Extension\CcFeeds\Module;

require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/settings.php';
require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/generator.php';

use Opencart\System\Library\Ccfeeds\Settings;
use Opencart\System\Library\Ccfeeds\Generator;

class CcFeeds extends \Opencart\System\Engine\Controller {
	private string $route = 'extension/cc_feeds/module/cc_feeds';

	private function jsonResponse(array $data): void {
		if (ob_get_level() > 0) {
			ob_clean();
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	public function index(): void {
		$this->load->language($this->route);
		$this->document->setTitle($this->language->get('heading_title'));

		$settings = new Settings($this->config);
		$all = $settings->all();

		$data = [];
		foreach (Settings::defaults() as $key => $default) {
			$data[$key] = $all[$key];
		}

		// Ensure a token exists so the URLs are usable immediately.
		if (empty($data['feed_token'])) {
			$data['feed_token'] = Settings::generateToken();
		}

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'])],
		];

		$data['save']        = $this->url->link($this->route . '.save', 'user_token=' . $this->session->data['user_token']);
		$data['gen_token']   = $this->url->link($this->route . '.token', 'user_token=' . $this->session->data['user_token']);
		$data['rebuild']     = $this->url->link($this->route . '.rebuild', 'user_token=' . $this->session->data['user_token']);
		$data['test']        = $this->url->link($this->route . '.test', 'user_token=' . $this->session->data['user_token']);
		$data['back']        = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');
		$data['user_token']  = $this->session->data['user_token'];

		// Public feed URLs.
		$store = rtrim((string)$this->config->get('config_url'), '/');
		if ($store === '') {
			$store = HTTP_CATALOG ?? '';
			$store = rtrim((string)$store, '/');
		}
		$data['url_rozetka'] = $store . '/index.php?route=extension/cc_feeds/feed.rozetka&token=' . $data['feed_token'];
		$data['url_prom']    = $store . '/index.php?route=extension/cc_feeds/feed.prom&token=' . $data['feed_token'];
		$data['url_google']  = $store . '/index.php?route=extension/cc_feeds/feed.google&token=' . $data['feed_token'];
		$data['url_meta']    = $store . '/index.php?route=extension/cc_feeds/feed.meta&token=' . $data['feed_token'];

		// Options that can act as variant dimensions (a list to pick from) and
		// attributes that may hold a per-product dispatch time.
		$this->load->model('catalog/option');
		$this->load->model('catalog/attribute');
		$data['options'] = [];
		foreach ($this->model_catalog_option->getOptions([]) as $option) {
			if (in_array((string)$option['type'], ['select', 'radio', 'checkbox', 'image'], true)) {
				$data['options'][] = ['option_id' => (int)$option['option_id'], 'name' => html_entity_decode((string)$option['name'], ENT_QUOTES, 'UTF-8')];
			}
		}
		$data['attributes'] = [];
		foreach ($this->model_catalog_attribute->getAttributes([]) as $attribute) {
			$data['attributes'][] = [
				'attribute_id' => (int)$attribute['attribute_id'],
				'name'         => html_entity_decode((string)$attribute['name'] . (isset($attribute['attribute_group']) ? ' (' . $attribute['attribute_group'] . ')' : ''), ENT_QUOTES, 'UTF-8'),
			];
		}

		// Category / manufacturer options for filters.
		$this->load->model('catalog/category');
		$this->load->model('catalog/manufacturer');
		$data['categories'] = $this->categoryTree();
		$data['manufacturers'] = $this->model_catalog_manufacturer->getManufacturers([]);

		$this->load->model('localisation/currency');
		$data['currencies']   = $this->model_localisation_currency->getCurrencies();
		$data['base_currency'] = (string)$this->config->get('config_currency');

		// Shown next to the tax switch: the module can only mirror the shop, and
		// the merchant should see which way the shop is set.
		$data['shop_shows_tax'] = (string)$this->config->get('config_tax') === '1';

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view($this->route, $data));
	}

	/**
	 * Flat list of categories with their full path as the label.
	 *
	 * ⚠ Do NOT walk the tree with getCategories(['filter_parent_id' => …]):
	 * OpenCart 4.0 ignores that filter, so every level returns the WHOLE
	 * catalogue and the recursion never narrows — the settings page died with
	 * "Allowed memory size exhausted" before it rendered a single field. The
	 * core model already returns names as "Parent > Child", so one flat call
	 * is both correct and cheap.
	 */
	private function categoryTree(): array {
		$this->load->model('catalog/category');

		$out = [];

		foreach ($this->model_catalog_category->getCategories([]) as $c) {
			$out[] = ['category_id' => (int)$c['category_id'], 'name' => (string)$c['name']];
		}

		return $out;
	}

	public function save(): void {
		$this->load->language($this->route);
		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->jsonResponse(['error' => $this->language->get('error_permission')]);
			return;
		}

		$post = $this->request->post;
		$data = [];
		foreach (Settings::defaults() as $key => $default) {
			$field = 'module_cc_feeds_' . $key;
			if (in_array($key, Settings::ARRAY_KEYS, true)) {
				$data[$field] = isset($post[$field]) && is_array($post[$field]) ? array_values(array_map('intval', $post[$field])) : [];
			} else {
				$data[$field] = isset($post[$field]) ? $post[$field] : $default;
			}
		}

		// Google's shipping attributes are normalised here, not at generation
		// time: the merchant reloads the page and sees exactly what will go into
		// the feed, and a field that comes back empty reads as "Google does not
		// understand this" instead of quietly poisoning the XML.
		$data['module_cc_feeds_handling_business_days'] = Settings::normalizeBusinessDays((string)$data['module_cc_feeds_handling_business_days']);
		$data['module_cc_feeds_handling_cutoff_time']   = Settings::normalizeCutoff((string)$data['module_cc_feeds_handling_cutoff_time']);

		$zone = trim((string)$data['module_cc_feeds_handling_cutoff_timezone']);
		$data['module_cc_feeds_handling_cutoff_timezone'] = Settings::isIanaZone($zone) ? $zone : '';

		$country = strtoupper(trim((string)$data['module_cc_feeds_merchant_country']));
		$data['module_cc_feeds_merchant_country'] = preg_match('/^[A-Z]{2}$/', $country) ? $country : '';

		$mov = str_replace(',', '.', trim((string)$data['module_cc_feeds_min_order_value']));
		$data['module_cc_feeds_min_order_value'] = (is_numeric($mov) && (float)$mov > 0) ? (string)(float)$mov : '';

		// Never blank an existing token by accident.
		if (empty($data['module_cc_feeds_feed_token'])) {
			$existing = (string)$this->config->get('module_cc_feeds_feed_token');
			$data['module_cc_feeds_feed_token'] = $existing !== '' ? $existing : Settings::generateToken();
		}

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('module_cc_feeds', $data);

		$this->jsonResponse(['success' => $this->language->get('text_success')]);
	}

	public function token(): void {
		$this->load->language($this->route);
		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->jsonResponse(['error' => $this->language->get('error_permission')]);
			return;
		}
		$this->jsonResponse(['token' => Settings::generateToken()]);
	}

	public function test(): void {
		$this->load->language($this->route);
		if (!$this->user->hasPermission('access', $this->route)) {
			$this->jsonResponse(['error' => $this->language->get('error_permission')]);
			return;
		}
		$settings = new Settings($this->config);

		// ⚠ Same WHERE the generator uses, stock rule included — a counter that
		// disagrees with the feed is worse than no counter.
		$generator = new Generator($this->db, $settings, $settings->languageId(), $this->imageBase(), (int)$this->config->get('config_customer_group_id'));

		$total = (int)$this->db->query("SELECT COUNT(*) AS c FROM `" . DB_PREFIX . "product` p WHERE " . $generator->productWhere())->row['c'];

		$this->jsonResponse([
			'success' => sprintf($this->language->get('text_test_ok'), $total),
			'count'   => $total,
		]);
	}

	public function rebuild(): void {
		$this->load->language($this->route);
		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->jsonResponse(['error' => $this->language->get('error_permission')]);
			return;
		}
		$settings = new Settings($this->config);

		$done = Generator::rebuildCache(
			$this->db,
			$settings,
			$this->imageBase(),
			(int)$this->config->get('config_customer_group_id')
		);

		$message = $this->language->get('text_rebuilt');

		// Google/Meta files need storefront SEO links, so admin only clears them.
		if ($settings->get('google_enabled', '0') === '1' || $settings->get('meta_enabled', '0') === '1') {
			$message .= ' ' . $this->language->get('text_rebuilt_merchant');
		}

		$this->jsonResponse(['success' => $message, 'counts' => $done]);
	}

	private function imageBase(): string {
		$store = rtrim((string)$this->config->get('config_url'), '/');

		if ($store === '') {
			$store = rtrim((string)(defined('HTTP_CATALOG') ? HTTP_CATALOG : ''), '/');
		}

		return $store . '/image';
	}

	public function install(): void {
		Settings::prepareCacheDir();

		$this->load->model('setting/event');
		$this->load->model('setting/cron');

		try { $this->model_setting_cron->deleteCronByCode('cc_feeds_rebuild'); } catch (\Throwable $e) {}
		$this->model_setting_cron->addCron('cc_feeds_rebuild', 'CatCode Market Feeds — rebuild cached feed files', 'day', 'extension/cc_feeds/cron.rebuild', true);

		$this->load->model('user/user_group');
		try {
			$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'access', $this->route);
			$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'modify', $this->route);
		} catch (\Throwable $e) {}
	}

	/**
	 * ⚠ The cached XML has to go with it. Uninstall drops the setting group —
	 * the feed token with it — so nothing is left that could ever serve or
	 * clean those files again, while their path stays readable to anyone who
	 * noted it down. An uninstalled module must not keep handing out the
	 * catalogue.
	 */
	public function uninstall(): void {
		$this->load->model('setting/cron');
		try { $this->model_setting_cron->deleteCronByCode('cc_feeds_rebuild'); } catch (\Throwable $e) {}

		Settings::purgeCache();
	}
}
