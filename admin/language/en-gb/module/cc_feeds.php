<?php
// Heading
$_['heading_title']          = 'Market Feeds (Rozetka / Prom / Google / Meta)';

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

// Google Merchant / Meta (1.2.0)
$_['text_merchant']            = 'Google & Meta';
$_['text_feed_off']            = 'Off — enable it on the "Google & Meta" tab.';
$_['text_none_selected']       = '— none —';
$_['text_rebuilt_merchant']    = 'Google and Meta feeds are served live and will be written to file on the next cron run: SEO product links can only be built by the storefront.';
$_['entry_google']             = 'Google Merchant Center feed';
$_['entry_meta']               = 'Meta (Facebook / Instagram) feed';
$_['entry_size_options']       = 'Size options';
$_['entry_color_options']      = 'Colour options';
$_['entry_handling_time']      = 'Dispatch time, working days';
$_['entry_handling_attribute'] = 'Attribute holding the dispatch time';
$_['entry_merchant_brand']     = 'Default brand';
$_['entry_google_category']    = 'Google category (google_product_category)';
$_['entry_merchant_mpn']       = 'MPN from field';
$_['help_merchant']            = 'An RSS feed in Google Merchant Center format, which the Meta catalogue accepts as well. Prices, tax, specials, stock and filters are the same as in the Rozetka and Prom feeds.';
$_['help_size_options']        = 'Values of these options become separate variants with g:size.';
$_['help_variants']            = 'Values become variants with g:color. All variants of a product share g:item_group_id = product ID, so Google and Meta group them into one product. Variant price = product price + option price; availability follows the value stock when stock subtraction is on for it.';
$_['help_handling_time']       = 'How many working days a product takes to ship: "0", "2" or "2-6". Sent to Google as g:min_handling_time / g:max_handling_time. On Prom.ua the "Ready to ship" status is set only for products that ship within 1 day. Empty — not sent.';
$_['help_handling_attribute']  = 'When a product has this attribute filled in (e.g. "Dispatch time: 2-6"), it wins over the value above.';
$_['help_merchant_brand']      = 'For products without a manufacturer. Google and Meta require a brand.';
$_['help_google_category']     = 'Optional. ID or path from the Google taxonomy, e.g. 187 (Shoes).';

// Error
$_['error_permission']       = 'You do not have permission to manage this module!';
