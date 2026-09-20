<?php
// Heading
$_['heading_title']          = 'Market Feeds (Rozetka / Prom / Google / Meta)';

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

// Google Merchant / Meta (1.2.0)
$_['text_merchant']            = 'Google і Meta';
$_['text_feed_off']            = 'Вимкнено — увімкніть на вкладці «Google і Meta».';
$_['text_none_selected']       = '— не вибрано —';
$_['text_rebuilt_merchant']    = 'Фіди Google і Meta віддаються наживо й перезапишуться у файл при наступному запуску cron: посилання на товари з ЧПУ будує лише вітрина.';
$_['entry_google']             = 'Фід Google Merchant Center';
$_['entry_meta']               = 'Фід Meta (Facebook / Instagram)';
$_['entry_size_options']       = 'Опції «Розмір»';
$_['entry_color_options']      = 'Опції «Колір»';
$_['entry_handling_time']      = 'Строк відправки, робочих днів';
$_['entry_handling_attribute'] = 'Атрибут зі строком відправки';
$_['entry_merchant_brand']     = 'Бренд за замовчуванням';
$_['entry_google_category']    = 'Категорія Google (google_product_category)';
$_['entry_merchant_mpn']       = 'MPN із поля';
$_['help_merchant']            = 'RSS-фід у форматі Google Merchant Center, який так само приймає каталог Meta. Ціни, податок, акції, наявність і фільтри — ті самі, що й у фідах Rozetka та Prom.';
$_['help_size_options']        = 'Значення цих опцій стають окремими варіантами з g:size.';
$_['help_variants']            = 'Значення стають варіантами з g:color. Усі варіанти товару отримують спільний g:item_group_id = ID товару, тож Google і Meta склеюють їх в одну картку. Ціна варіанта = ціна товару + ціна опції, наявність — за залишком значення, якщо для нього ввімкнено віднімання зі складу.';
$_['help_handling_time']       = 'Скільки робочих днів товар готується до відправки: «0», «2» або «2-6». Іде в Google як g:min_handling_time / g:max_handling_time. У Prom.ua статус «Готово до відправки» ставиться лише товарам, що йдуть за 1 день. Порожньо — не передається.';
$_['help_handling_attribute']  = 'Якщо в товару заповнено цей атрибут (напр. «Строк відправки: 2-6»), він важливіший за значення вище.';
$_['help_merchant_brand']      = 'Для товарів без виробника. Google і Meta вимагають бренд.';
$_['help_google_category']     = 'Необовʼязково. ID або шлях із таксономії Google, напр. 187 (Взуття).';

// Error
$_['error_permission']       = 'У вас немає прав керувати цим модулем!';

// Google product data specification, 14.04.2026.
$_['entry_business_days']      = 'Дні відвантаження';
$_['entry_cutoff_time']        = 'Час відсічки замовлень';
$_['entry_cutoff_timezone']    = 'Часовий пояс відсічки';
$_['entry_min_order_value']    = 'Мінімальна сума замовлення';
$_['entry_merchant_country']   = 'Країна доставки';
$_['help_business_days']       = 'У які дні тижня склад збирає замовлення: Пн-Пт, Пн-Сб, Пн;Ср;Пт (можна і M-F, Mon-Sat, MWRFS). Іде в g:shipping_handling_business_days. Порожньо — Google рахує Пн–Пт, і кожна суботня відправка в нього «спізнюється».';
$_['help_cutoff_time']         = 'До котрої години замовлення ще йде сьогодні: 15:00, 1500, 18:30. Іде в g:handling_cutoff_time. Порожньо — Google відлічує строк відправки від опівночі, тому замовленню о 18:00 обіцяє ту саму дату, що й ранковому.';
$_['help_cutoff_timezone']     = 'Назва з бази IANA, напр. Europe/Kyiv. Порожньо — береться часовий пояс магазину з налаштувань OpenCart; якщо там не назва з IANA, пояс не передається і Google читає час відсічки за поясом покупця. Пояс сервера свідомо не підставляється: на більшості хостингів це UTC, якого ніхто не обирав.';
$_['help_min_order_value']     = 'Скільки треба набрати, щоб магазин відправив замовлення. Іде в g:minimum_order_value і показується в оголошенні — але тільки для товарів, дешевших за цю суму (вимога Google). Порожньо — не передається.';
$_['help_merchant_country']    = 'Код ISO 3166-1, напр. UA. Порожньо — країна з адреси магазину. Потрібен для мінімальної суми замовлення: без країни Google відхиляє цей атрибут.';
