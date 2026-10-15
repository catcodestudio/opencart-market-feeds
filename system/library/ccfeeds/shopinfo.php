<?php
namespace Opencart\System\Library\Ccfeeds;

require_once __DIR__ . '/agentformat.php';

/**
 * Seller name, storefront URL and policy links shared by the OpenAI feed, the
 * UCP seller object and /llms.txt (OpenCart 4 storefront only — the links are
 * built with the storefront URL class so SEO URLs apply).
 *
 * Policy pages: the return URL from the settings, else the store's Return
 * Terms page; terms = the chosen information page, else the Checkout Terms
 * page; privacy = the chosen page, else the store's GDPR page when one is set.
 * A disabled or missing information page is never linked.
 */
class ShopInfo {

	/**
	 * @param object   $db
	 * @param object   $url      storefront Url
	 * @param object   $config
	 * @param Settings $settings
	 * @param string   $languageCode code for links ("uk-ua"), '' for none
	 * @return array seller_name, seller_url, return_url, terms_url, privacy_url, policy
	 */
	public static function build($db, $url, $config, Settings $settings, string $languageCode): array {
		$name = trim((string)$settings->get('ai_seller_name', ''));
		if ($name === '') {
			$name = (string)$config->get('config_name');
		}

		$return = trim((string)$settings->get('ai_return_policy_url', ''));
		if (!AgentFormat::isUrl($return)) {
			$return = self::infoUrl($db, $url, (int)$config->get('config_return_id'), $languageCode);
		}

		$terms = (int)$settings->get('ai_terms_info_id', 0);
		$terms = self::infoUrl($db, $url, $terms > 0 ? $terms : (int)$config->get('config_checkout_id'), $languageCode);

		$privacy = (int)$settings->get('ai_privacy_info_id', 0);
		$privacy = self::infoUrl($db, $url, $privacy > 0 ? $privacy : (int)$config->get('config_gdpr_id'), $languageCode);

		return [
			'seller_name' => AgentFormat::text($name, 200),
			'seller_url'  => rtrim((string)$config->get('config_url'), '/') . '/',
			'return_url'  => $return,
			'terms_url'   => $terms,
			'privacy_url' => $privacy,
			'policy'      => AgentFormat::openAiPolicy(
				(string)$settings->get('ai_accepts_returns', ''),
				(int)$settings->get('ai_return_days', 0),
				$return,
				$privacy,
				$terms
			),
		];
	}

	/** Storefront URL of an enabled information page, '' otherwise. */
	public static function infoUrl($db, $url, int $informationId, string $languageCode): string {
		if ($informationId <= 0) {
			return '';
		}

		$row = $db->query("SELECT `information_id` FROM `" . DB_PREFIX . "information` WHERE `information_id` = '" . $informationId . "' AND `status` = '1'")->row;
		if (!$row) {
			return '';
		}

		$args = ($languageCode !== '' ? 'language=' . $languageCode . '&' : '') . 'information_id=' . $informationId;

		return str_replace('&amp;', '&', (string)$url->link('information/information', $args));
	}
}
