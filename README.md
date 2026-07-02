# Market Feeds (OpenCart 4)

Product export feeds for Ukrainian marketplaces — **Rozetka XML** and **Prom.ua YML** — for OpenCart 4.x.

## Features
- Token-protected storefront feed URLs:
  - `index.php?route=extension/cc_feeds/feed.rozetka&token=…`
  - `index.php?route=extension/cc_feeds/feed.prom&token=…`
- Streaming `XMLWriter` output — memory-safe on large catalogs (products paged, images/attributes/specials batch-loaded).
- Category and manufacturer filters, price multiplier, currency.
- Special price → `<price>` + original `<price_old>` (OpenCart 4 `product_discount`, types F/P/S).
- Availability + stock threshold mapping; per-platform quantity tag (Rozetka `stock_quantity`, Prom `quantity_in_stock`).
- Product attributes exported as `<param>`; configurable image limit and description mode.
- Optional feed file cache rebuilt by cron (`extension/cc_feeds/cron.rebuild`).

## Install
Admin → Extensions → Installer → upload `cc_market_feeds.ocmod.zip`, then Extensions → Modules → install **Market Feeds** and open its settings.

## Structure
Repository root is the extension payload (`admin/`, `catalog/`, `system/`, `install.json`). Zip these into `cc_market_feeds.ocmod.zip` for the OpenCart installer.

---
© CatCode — https://catcode.com.ua
