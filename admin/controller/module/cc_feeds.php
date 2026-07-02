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

		// Category / manufacturer options for filters.
		$this->load->model('catalog/category');
		$this->load->model('catalog/manufacturer');
		$data['categories'] = $this->categoryTree();
		$data['manufacturers'] = $this->model_catalog_manufacturer->getManufacturers([]);

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view($this->route, $data));
	}

	private function categoryTree(): array {
		$this->load->model('catalog/category');
		$out = [];
		$this->walkCategories(0, '', $out);
		return $out;
	}

	private function walkCategories(int $parentId, string $prefix, array &$out): void {
		$children = $this->model_catalog_category->getCategories(['filter_parent_id' => $parentId]);
		foreach ($children as $c) {
			$out[] = ['category_id' => (int)$c['category_id'], 'name' => $prefix . $c['name']];
			$this->walkCategories((int)$c['category_id'], $prefix . '— ', $out);
		}
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

		$catFilter = array_filter(array_map('intval', (array)$settings->get('category_ids', [])));
		$manFilter = array_filter(array_map('intval', (array)$settings->get('manufacturer_ids', [])));
		$where = ["p.status = 1", "p.date_available <= NOW()"];
		if ($manFilter) {
			$where[] = "p.manufacturer_id IN (" . implode(',', $manFilter) . ")";
		}
		if ($catFilter) {
			$where[] = "p.product_id IN (SELECT product_id FROM `" . DB_PREFIX . "product_to_category` WHERE category_id IN (" . implode(',', $catFilter) . "))";
		}
		$total = (int)$this->db->query("SELECT COUNT(*) AS c FROM `" . DB_PREFIX . "product` p WHERE " . implode(' AND ', $where))->row['c'];

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

		$dir = DIR_STORAGE . 'cc_feeds/';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$imageBase = rtrim((string)$this->config->get('config_url'), '/') . '/image';
		$generator = new Generator($this->db, $settings, $settings->languageId(), $imageBase, (int)$this->config->get('config_customer_group_id'));

		$done = [];
		foreach (['rozetka', 'prom'] as $dialect) {
			if ($settings->get($dialect . '_enabled', '1') !== '1') {
				continue;
			}
			$tmp = $dir . 'feed_' . $dialect . '.tmp';
			$final = $dir . 'feed_' . $dialect . '.xml';
			$count = $generator->stream($dialect, $tmp);
			if (is_file($tmp)) {
				@rename($tmp, $final);
			}
			$done[$dialect] = $count;
		}
		$this->jsonResponse(['success' => $this->language->get('text_rebuilt'), 'counts' => $done]);
	}

	public function install(): void {
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

	public function uninstall(): void {
		$this->load->model('setting/cron');
		try { $this->model_setting_cron->deleteCronByCode('cc_feeds_rebuild'); } catch (\Throwable $e) {}
	}
}
