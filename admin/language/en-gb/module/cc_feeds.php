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

// Google product data specification, 14 April 2026.
$_['entry_business_days']      = 'Fulfilment days';
$_['entry_cutoff_time']        = 'Order cutoff time';
$_['entry_cutoff_timezone']    = 'Cutoff timezone';
$_['entry_min_order_value']    = 'Minimum order value';
$_['entry_merchant_country']   = 'Shipping country';
$_['help_business_days']       = 'Which days of the week the warehouse packs orders: M-F, M-S, M;W;F (Mon-Sat and MWRFS work too). Sent as g:shipping_handling_business_days. Empty — Google assumes Mon-Fri, so every Saturday dispatch looks late to it.';
$_['help_cutoff_time']         = 'Until what time an order still ships today: 15:00, 1500, 18:30. Sent as g:handling_cutoff_time. Empty — Google counts the handling days from midnight, so an order placed at 18:00 is promised the same date as a morning one.';
$_['help_cutoff_timezone']     = 'IANA name, e.g. Europe/Kyiv. Empty — the shop timezone from the OpenCart settings is used; if that is not an IANA name, no timezone is sent and Google reads the cutoff in the buyer timezone. The server timezone is deliberately not used: on most hosts it is a UTC nobody chose.';
$_['help_min_order_value']     = 'How much a customer must spend before the shop ships an order. Sent as g:minimum_order_value and shown in the ad — but only for products cheaper than that amount (Google requirement). Empty — not sent.';
$_['help_merchant_country']    = 'ISO 3166-1 code, e.g. UA. Empty — the country from the shop address. Required for the minimum order value: without a country Google rejects the attribute.';

// AI agents (1.4.0): OpenAI Product Feed, /llms.txt, UCP catalog
$_['text_ai']                  = 'AI agents';
$_['help_ai']                  = 'Make the catalogue readable for AI assistants: a product feed for ChatGPT (OpenAI Product Feed), a short shop description at /llms.txt and the Universal Commerce Protocol catalog (search and lookup) at /.well-known/ucp. Everything is off until you switch it on; Rozetka, Prom, Google and Meta feeds do not change.';
$_['text_ai_openai']           = 'OpenAI Product Feed (ChatGPT)';
$_['entry_openai']             = 'OpenAI feed';
$_['help_openai']              = 'Gzipped JSON Lines (openai.jsonl.gz) by the OpenAI file-upload spec: one row per product or size/colour variant, price and availability as on the site, without the price multiplier. OpenAI accepts feeds from approved partners only (apply at chatgpt.com/merchants) and takes them over SFTP: download the file from the URL above, or let a script upload it at least once a day. A standard upload is shown to US shoppers.';
$_['text_openai_stats']        = 'Last build %s: %d rows; skipped — %s.';
$_['text_skip_no_brand']       = 'no brand (set a manufacturer or the default brand on the Google & Meta tab)';
$_['text_skip_no_image']       = 'no image';
$_['text_skip_no_price']       = 'no price';
$_['text_skip_encoding']       = 'text encoding';
$_['entry_ai_seller_name']     = 'Seller name';
$_['help_ai_seller_name']      = 'seller_name in the OpenAI feed and the seller in UCP. Empty — the store name.';
$_['entry_ai_accepts_returns'] = 'Accepts returns';
$_['text_ai_returns_unset']    = 'Not specified';
$_['help_ai_accepts_returns']  = 'Sent only when you choose: an empty value means "unspecified" for OpenAI, a wrong "yes" would be a promise.';
$_['entry_ai_return_days']     = 'Return window, days';
$_['entry_ai_return_policy_url'] = 'Return policy URL';
$_['help_ai_return_policy_url'] = 'Empty — the Return Terms page from the store settings, if one is set.';
$_['entry_ai_terms_page']      = 'Terms page';
$_['text_ai_terms_auto']       = 'Checkout Terms from the store settings';
$_['entry_ai_privacy_page']    = 'Privacy policy page';
$_['text_ai_privacy_none']     = 'Not published';
$_['entry_llms']               = '/llms.txt';
$_['help_llms']                = 'A Markdown page for language models (llmstxt.org): store name and summary, top categories, newest products with prices, policy links and the UCP profile.';
$_['entry_llms_products']      = 'Products in /llms.txt';
$_['entry_llms_summary']       = 'Summary';
$_['help_llms_summary']        = 'One or two sentences about the store. Empty — the store meta description.';
$_['text_ai_ucp']              = 'Universal Commerce Protocol';
$_['entry_ucp']                = 'UCP catalog';
$_['help_ucp']                 = 'Business profile at /.well-known/ucp (UCP 2026-08-25) and the REST endpoints catalog/search, catalog/lookup and catalog/product, so an agent can find products, prices and stock. Agent checkout is not part of this module: the profile advertises the catalog only. Every request is checked against the agent profile named in its UCP-Agent header.';
$_['warning_ucp_https']        = 'UCP needs HTTPS: the store URL is not https://, so the profile is not published.';
$_['warning_ucp_root']         = 'The store is in a sub-folder. /.well-known/ucp must be answered at the root of the domain — add the rewrite below to the root site configuration.';
$_['text_ai_server']           = 'Web server';
$_['help_ai_server']           = '/llms.txt, /.well-known/ucp and /cc-ucp/... are not files, so the web server has to pass them to OpenCart. When SEO URLs already send unknown paths to OpenCart, the module catches them by itself; otherwise add the lines below. Note that the stock OpenCart .htaccess denies every *.txt except robots.txt. Save the settings first, then press Check.';
$_['button_agent_check']       = 'Check';
$_['error_agent_nothing']      = 'Nothing to check: switch on /llms.txt or UCP and save the settings.';
$_['text_ai_feed_off']          = 'Off — enable it on the "AI agents" tab.';
$_['text_ai_privacy_none']       = 'GDPR page from the store settings, if set';
