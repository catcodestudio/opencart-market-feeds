<?php
namespace Opencart\Catalog\Controller\Extension\CcFeeds;

require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/settings.php';
require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/generator.php';

use Opencart\System\Library\Ccfeeds\Settings;
use Opencart\System\Library\Ccfeeds\Generator;

/**
 * Storefront feed endpoints:
 *   index.php?route=extension/cc_feeds/feed.rozetka&token=XXXX
 *   index.php?route=extension/cc_feeds/feed.prom&token=XXXX
 */
class Feed extends \Opencart\System\Engine\Controller {

	public function rozetka(): void {
		$this->serve('rozetka');
	}

	public function prom(): void {
		$this->serve('prom');
	}

	private function serve(string $dialect): void {
		$settings = new Settings($this->config);

		if ($settings->get('status', '0') !== '1') {
			$this->deny(404, 'Feeds disabled');
			return;
		}
		$enabledKey = $dialect . '_enabled';
		if ($settings->get($enabledKey, '1') !== '1') {
			$this->deny(404, 'Feed disabled');
			return;
		}

		$token = (string)($this->request->get['token'] ?? '');
		if (!$settings->tokenValid($token)) {
			$this->deny(403, 'Invalid token');
			return;
		}

		// Discard any buffered layout output; stream raw XML.
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		$this->response->addHeader('Content-Type: application/xml; charset=utf-8');
		$this->response->addHeader('X-Content-Type-Options: nosniff');

		// Serve from cache file when enabled and fresh copy exists.
		if ($settings->get('cache_enabled', '0') === '1') {
			$file = $this->cachePath($dialect);
			if (is_file($file) && filesize($file) > 0) {
				header('Content-Type: application/xml; charset=utf-8');
				readfile($file);
				exit;
			}
		}

		$imageBase = rtrim((string)$this->config->get('config_url'), '/') . '/image';
		$generator = new Generator(
			$this->db,
			$settings,
			$settings->languageId(),
			$imageBase,
			(int)$this->config->get('config_customer_group_id')
		);

		// Direct output — bypass OpenCart's response buffering for large feeds.
		header('Content-Type: application/xml; charset=utf-8');
		$generator->stream($dialect, 'php://output');
		exit;
	}

	private function cachePath(string $dialect): string {
		$dir = DIR_STORAGE . 'cc_feeds/';
		return $dir . 'feed_' . preg_replace('/[^a-z]/', '', $dialect) . '.xml';
	}

	private function deny(int $code, string $msg): void {
		$this->response->addHeader($_SERVER['SERVER_PROTOCOL'] . ' ' . $code . ' ' . $msg);
		$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
		$this->response->setOutput($msg);
	}
}
