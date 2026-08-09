# Market Feeds (OpenCart 4)

Product export feeds for Ukrainian marketplaces — **Rozetka XML** and **Prom.ua YML** — for OpenCart 4.x.

## Features
- Token-protected storefront feed URLs:
  - `index.php?route=extension/cc_feeds/feed.rozetka&token=…`
  - `index.php?route=extension/cc_feeds/feed.prom&token=…`
- Streaming `XMLWriter` output — memory-safe on large catalogs (products paged, images/attributes/specials batch-loaded).
- Category and manufacturer filters, price multiplier, currency.
- **Feed price equals the price on the product page**: tax is applied per the shop's own tax rules (`config_tax_default`, store geo zone), then the currency rate, then your multiplier. Leaving the currency on "shop currency" publishes the catalogue exactly as priced.
- Special price → `<price>` + original `<price_old>` (OpenCart 4 `product_discount`, types F/P/S).
- Availability + stock threshold mapping; per-platform quantity tag (Rozetka `stock_quantity`, Prom `quantity_in_stock`).
- Product attributes exported as `<param>`; configurable image limit and description mode.
- Optional feed file cache rebuilt by cron (`extension/cc_feeds/cron.rebuild`). Cached files are named with a digest of the feed token, so a direct GET of the cache path is as secret as the feed URL itself — `DIR_STORAGE` is not always outside the web root, and nginx serves static files without consulting `.htaccess`. Rotating the token or uninstalling the module deletes them.

## 1.1.0
- 🔴 Feed price now matches the storefront. Tax was not applied at all (a product shown at 110 went out at 90), and the currency setting was a label only — a shop based in USD published dollar amounts tagged `<currencyId>UAH</currencyId>`. Rates are read with plain SQL because `Cart\Tax` is registered by the storefront only: otherwise "Rebuild cache" in admin produced tax-free prices while cron produced taxed ones.
- 🔴 Cached feed files could be downloaded straight from disk, bypassing the token. Fixed with token-derived filenames plus `.htaccess`/`index.html` in the cache directory; stale files are removed on token change, feed disable and uninstall.
- The "Check" counter ignored the stock filter (promised 19 offers when the feed held 17); it now runs the generator's own `WHERE`.

## Install
Admin → Extensions → Installer → upload `cc_market_feeds.ocmod.zip`, then Extensions → Modules → install **Market Feeds** and open its settings.

## Structure
Repository root is the extension payload (`admin/`, `catalog/`, `system/`, `install.json`). Zip these into `cc_market_feeds.ocmod.zip` for the OpenCart installer.

---
© CatCode — https://catcode.com.ua


## 🔗 CatCode

Модуль розробляє і підтримує студія **[CatCode](https://catcode.com.ua)**.

- 📄 Сторінка модуля з документацією та ліцензією: **https://catcode.com.ua/modules/opencart-market-feeds/**
- 🧩 Усі наші модулі для OpenCart та WooCommerce: https://catcode.com.ua/modules/
- ✉️ Підтримка: catcode.info@gmail.com
