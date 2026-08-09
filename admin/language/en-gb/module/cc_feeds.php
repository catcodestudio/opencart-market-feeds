<?php
// Heading
$_['heading_title']          = 'Market Feeds (Rozetka / Prom)';

// Text
$_['text_extension']         = 'Extensions';
$_['text_home']              = 'Home';
$_['text_success']           = 'Settings saved!';
$_['text_edit']              = 'Feed settings';
$_['text_enabled']           = 'Enabled';
$_['text_disabled']          = 'Disabled';
$_['text_yes']               = 'Yes';
$_['text_no']                = 'No';
$_['text_all']               = 'All';
$_['text_rebuilt']           = 'Feed cache rebuilt.';
$_['text_test_ok']           = 'Products that will be exported: %s';
$_['text_general']           = 'General';
$_['text_filters']           = 'Filters & prices';
$_['text_output']            = 'Feed content';
$_['text_urls']              = 'Feed URLs';
$_['text_full']              = 'Full description';
$_['text_short']             = 'Short (500 chars)';
$_['text_none']              = 'No description';

// Entry
$_['entry_status']           = 'Module status';
$_['entry_rozetka']          = 'Rozetka feed';
$_['entry_prom']             = 'Prom.ua feed';
$_['entry_token']            = 'Access token';
$_['entry_currency']         = 'Currency';
$_['entry_language']         = 'Export language';
$_['entry_multiplier']       = 'Price multiplier';
$_['entry_decimals']         = 'Price decimals';
$_['entry_special']          = 'Special price as main';
$_['entry_out_of_stock']     = 'Export out-of-stock';
$_['entry_stock_threshold']  = '"In stock" quantity threshold';
$_['entry_avail_in']         = '"In stock" value';
$_['entry_avail_out']        = '"Out of stock" value';
$_['entry_categories']       = 'Categories (empty = all)';
$_['entry_manufacturers']    = 'Manufacturers (empty = all)';
$_['entry_description']      = 'Product description';
$_['entry_strip_html']       = 'Strip HTML from description';
$_['entry_image_limit']      = 'Max images per product';
$_['entry_sku_field']        = 'SKU from field';
$_['entry_params']           = 'Attributes as &lt;param&gt;';
$_['entry_name_suffix']      = 'Name suffix';
$_['entry_cache']            = 'Cache to file (cron)';

// Buttons / help
$_['button_save']            = 'Save';
$_['button_gen_token']       = 'Generate token';
$_['button_rebuild']         = 'Rebuild cache';
$_['button_test']            = 'Test';
$_['help_token']             = 'Secret part of the feed URL. Keep it private — the whole catalog is reachable with it.';
$_['help_special']           = 'When a product has an active special it becomes &lt;price&gt;, and the regular price becomes &lt;price_old&gt;.';
$_['help_cache']             = 'The feed is generated to a file on schedule (cron extension/cc_feeds/cron.rebuild) and served instantly.';

$_['entry_include_tax']      = 'Prices with tax';
$_['help_include_tax']       = 'The feed price must match the product page, otherwise a marketplace can suspend the price list.';
$_['text_shop_tax_on']       = 'The shop shows prices with tax — leave this on.';
$_['text_shop_tax_off']      = 'The shop shows prices without tax, so no tax is added to the feed.';
$_['text_currency_base']     = 'Shop currency (%s)';
$_['help_currency']          = 'Prices are converted from the shop currency using its OpenCart exchange rate.';

// Error
$_['error_permission']       = 'You do not have permission to manage this module!';
