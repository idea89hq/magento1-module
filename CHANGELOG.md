# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-08-18

### Fixed
- Coupon expiry dates are now sent to IDEA89 as UTC timestamps with a `Z`
  suffix (`gmdate('Y-m-d\TH:i:s\Z', ...)`) instead of the server's local
  offset (`date('c')`, which yields e.g. `+01:00`). The API accepts only the
  `Z` form, so on any store whose PHP timezone was not UTC, every cart price
  rule that had an expiry date set was rejected on sync and the assistant
  never mentioned that promotion. Rules with no expiry date were unaffected.
  Affects both the save observer and the daily promo cron.

## [1.0.0] - 2026-07-07

### Added

- **AI chat widget** — floating chat interface (bottom-left or bottom-right), mobile-responsive,
  dark/light theme support, loaded asynchronously from the IDEA89 CDN. Injected via the
  `before_body_end` layout handle in `app/design/frontend/base/default/layout/idea89.xml`.
- **Full catalog sync** — products (names, descriptions, prices, images, attributes, variants,
  stock levels, reviews), categories, CMS pages, and active cart price rules synced to IDEA89.
  Sync payload includes `sale_price` / `is_on_sale` computed from Magento special-price dates,
  and `category_names` (human-readable) alongside raw `category_path` for accurate per-category
  filtering.
- **Real-time observers** — product save, stock update, and cart price rule save events queue
  products for near-real-time sync via `cataloginventory_stock_item_save_after`,
  `catalog_product_save_after`, and `salesrule_rule_save_after`.
- **Cron queue drain** — minute-by-minute cron job drains the sync queue.
- **Nightly full re-sync** — safety-net full catalogue re-sync cron (configurable time).
- **Admin configuration panel** — System > Configuration > IDEA89 > AI Shopping Assistant.
  Covers: API key (encrypted storage), assistant name, store context, widget position,
  Test Connection button, Sync Now button, API URL override.
- **Content sync controls** — per-store-view toggles for products, categories, CMS pages,
  and store info.
- **Order tracking** — in-chat order card surfaces status, items, and carrier tracking links.
  Logged-in customers see their last N recent orders; guests verify with order number and email.
  Guest lookup is rate-limited per IP. Configurable: enable toggle, max orders shown, support
  URL and label, carrier tracking button toggle.
- **Personalization** — identity token generated per shopper session and passed with every
  widget call so the IDEA89 API can tailor recommendations. Live-products endpoint returns
  contextual suggestions. Configurable token lifetime.
- **Store Locator** _(Pro plan)_ — dedicated storefront page at a configurable URL slug,
  plus a drop-in CMS widget (IDEA89 Store Locator). Powered by a custom front controller
  router. Configurable: enable toggle, URL path, page title, meta description, hero copy,
  help section.
- **modman support** — `modman` file maps `app` to the Magento root for modman-based deploys.
- **Composer support** — `composer.json` with type `magento-module` for
  `magento-hackathon/magento-composer-installer`-based installs.
- Full feature parity with the Magento 2 module v1.1.5.
