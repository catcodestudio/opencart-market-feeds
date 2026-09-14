<?php
namespace Opencart\Catalog\Controller\Extension\CcFeeds;

require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/settings.php';
require_once DIR_EXTENSION . 'cc_feeds/system/library/ccfeeds/generator.php';

use Opencart\System\Library\Ccfeeds\Settings;
use Opencart\System\Library\Ccfeeds\Generator;

/**
 * Cron entrypoint that rebuilds cached feed files under
 * DIR_STORAGE/cc_feeds/. Registered as extension/cc_feeds/cron.rebuild and
 * runnable from the OpenCart cron manager or a system crontab.
 */
class Cron extends \Opencart\System\Engine\Controller {

	public function rebuild(): void {
		$settings = new Settings($this->config);
		if ($settings->get('status', '0') !== '1' || $settings->get('cache_enabled', '0') !== '1') {
			return;
		}

		$imageBase = rtrim((string)$this->config->get('config_url'), '/') . '/image';
		$language  = (new Generator($this->db, $settings, $settings->languageId(), $imageBase))->languageCode();

		Generator::rebuildCache(
			$this->db,
			$settings,
			$imageBase,
			(int)$this->config->get('config_customer_group_id'),
			Generator::productLinkBuilder($this->url, $language)
		);
	}
}
