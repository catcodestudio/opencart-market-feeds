<?php
// Heading
$_['heading_title']          = 'Market Feeds (Rozetka / Prom)';

// Text
$_['text_extension']         = 'Розширення';
$_['text_home']              = 'Головна';
$_['text_success']           = 'Налаштування збережено!';
$_['text_edit']              = 'Налаштування фідів';
$_['text_enabled']           = 'Увімкнено';
$_['text_disabled']          = 'Вимкнено';
$_['text_yes']               = 'Так';
$_['text_no']                = 'Ні';
$_['text_all']               = 'Усі';
$_['text_rebuilt']           = 'Кеш фідів перебудовано.';
$_['text_test_ok']           = 'До вивантаження потрапить товарів: %s';
$_['text_general']           = 'Загальні';
$_['text_filters']           = 'Фільтри та ціни';
$_['text_output']            = 'Вміст фіду';
$_['text_urls']              = 'Посилання на фіди';
$_['text_full']              = 'Повний опис';
$_['text_short']             = 'Короткий (500 симв.)';
$_['text_none']              = 'Без опису';

// Entry
$_['entry_status']           = 'Статус модуля';
$_['entry_rozetka']          = 'Фід Rozetka';
$_['entry_prom']             = 'Фід Prom.ua';
$_['entry_token']            = 'Токен доступу';
$_['entry_currency']         = 'Валюта';
$_['entry_language']         = 'Мова вивантаження';
$_['entry_multiplier']       = 'Множник ціни';
$_['entry_decimals']         = 'Знаків після коми';
$_['entry_special']          = 'Акційна ціна як основна';
$_['entry_out_of_stock']     = 'Вивантажувати відсутні';
$_['entry_stock_threshold']  = 'Поріг залишку "в наявності"';
$_['entry_avail_in']         = 'Значення "в наявності"';
$_['entry_avail_out']        = 'Значення "немає"';
$_['entry_categories']       = 'Категорії (порожньо = усі)';
$_['entry_manufacturers']    = 'Виробники (порожньо = усі)';
$_['entry_description']      = 'Опис товару';
$_['entry_strip_html']       = 'Прибрати HTML з опису';
$_['entry_image_limit']      = 'Макс. фото на товар';
$_['entry_sku_field']        = 'Артикул із поля';
$_['entry_params']           = 'Атрибути як &lt;param&gt;';
$_['entry_name_suffix']      = 'Суфікс назви';
$_['entry_cache']            = 'Кешувати у файл (cron)';

// Buttons / help
$_['button_save']            = 'Зберегти';
$_['button_gen_token']       = 'Згенерувати токен';
$_['button_rebuild']         = 'Перебудувати кеш';
$_['button_test']            = 'Перевірити';
$_['help_token']             = 'Секретна частина URL фіду. Тримайте у таємниці — за ним доступний весь каталог.';
$_['help_special']           = 'Якщо у товару діє акція — вона стає &lt;price&gt;, а звичайна ціна — &lt;price_old&gt;.';
$_['help_cache']             = 'Фід генерується у файл за розкладом (cron extension/cc_feeds/cron.rebuild) і віддається миттєво.';

$_['entry_include_tax']      = 'Ціни з податком';
$_['help_include_tax']       = 'Ціна у фіді має збігатися з ціною на сторінці товару, інакше маркетплейс може зупинити прайс.';
$_['text_shop_tax_on']       = 'Магазин показує ціни з податком — лишіть увімкненим.';
$_['text_shop_tax_off']      = 'Магазин показує ціни без податку, тому податок у фід не додається.';
$_['text_currency_base']     = 'Валюта магазину (%s)';
$_['help_currency']          = 'Ціни перераховуються з валюти магазину за її курсом в OpenCart.';

// Error
$_['error_permission']       = 'У вас немає прав керувати цим модулем!';
