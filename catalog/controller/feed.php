<?php
namespace Opencart\Catalog\Controller\Extension\CcFeeds;

require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/settings.php';
require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/generator.php';
require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/shopinfo.php';

use Opencart\System\Library\Ccfeeds\Settings;
use Opencart\System\Library\Ccfeeds\Generator;
use Opencart\System\Library\Ccfeeds\ShopInfo;

/**
 * Storefront feed endpoints:
 *   index.php?route=extension/cc_feeds/feed.rozetka&token=XXXX
 *   index.php?route=extension/cc_feeds/feed.prom&token=XXXX
 *   index.php?route=extension/cc_feeds/feed.google&token=XXXX
 *   index.php?route=extension/cc_feeds/feed.meta&token=XXXX
 *   index.php?route=extension/cc_feeds/feed.openai&token=XXXX   (OpenAI Product Feed, jsonl.gz)
 */
class Feed extends \Opencart\System\Engine\Controller {

	public function rozetka(): void {
		$this->serve('rozetka');
	}

	public function prom(): void {
		$this->serve('prom');
	}

	public function google(): void {
		$this->serve('google');
	}

	public function meta(): void {
		$this->serve('meta');
	}

	public function openai(): void {
		$this->serve('openai');
	}

	private function serve(string $dialect): void {
		$settings = new Settings($this->config);

		if ($settings->get('status', '0') !== '1') {
			$this->deny(404, 'Feeds disabled');
			return;
		}
		$enabledKey = $dialect . '_enabled';
		if ($settings->get($enabledKey, Generator::isLinkDialect($dialect) ? '0' : '1') !== '1') {
			$this->deny(404, 'Feed disabled');
			return;
		}

		$token = (string)($this->request->get['token'] ?? '');
		if (!$settings->tokenValid($token)) {
			$this->deny(403, 'Invalid token');
			return;
		}

		if ($dialect === 'openai' && !function_exists('gzopen')) {
			$this->deny(500, 'zlib extension is required for the OpenAI feed');
			return;
		}

		// Discard any buffered layout output; stream the raw file.
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		// Serve from cache file when enabled and fresh copy exists.
		if ($settings->get('cache_enabled', '0') === '1') {
			$file = $this->cachePath($dialect);
			if (is_file($file) && filesize($file) > 0) {
				$this->feedHeaders($dialect);
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
		$generator->setProductLink(Generator::productLinkBuilder($this->url, $generator->languageCode()));
		$generator->setAgentShop(ShopInfo::build($this->db, $this->url, $this->config, $settings, $generator->languageCode()));

		// Direct output — bypass OpenCart's response buffering for large feeds.
		$this->feedHeaders($dialect);
		$generator->stream($dialect, 'php://output');
		exit;
	}

	/**
	 * XML for the marketplaces; the OpenAI feed is a gzipped JSON Lines file,
	 * downloaded under the name it is uploaded to OpenAI's SFTP with.
	 */
	private function feedHeaders(string $dialect): void {
		if ($dialect === 'openai') {
			header('Content-Type: application/gzip');
			header('Content-Disposition: attachment; filename="openai.jsonl.gz"');
		} else {
			header('Content-Type: application/xml; charset=utf-8');
		}
		header('X-Content-Type-Options: nosniff');
	}

	private function cachePath(string $dialect): string {
		// The filename carries a digest of the feed token: DIR_STORAGE is not
		// always outside the web root, and a plain GET of the cache file would
		// otherwise hand over the whole catalogue with no token at all.
		return Settings::cacheFile($dialect, (string)(new Settings($this->config))->get('feed_token', ''));
	}

	private function deny(int $code, string $msg): void {
		$this->response->addHeader($_SERVER['SERVER_PROTOCOL'] . ' ' . $code . ' ' . $msg);
		$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
		$this->response->setOutput($msg);
	}
}
