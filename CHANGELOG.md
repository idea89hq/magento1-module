# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-06

### Fixed
- **Configurable products with swatches sync again.** Swatch types were
  sent as Magento's numbers (0, 1, 2); IDEA89 accepts only `text`, `color`
  and `image`, so every batch with a swatch product was refused. They are
  now sent as names. (IDEA89 also accepts the old numbers from 1.0.3 and
  earlier.)

### Added
- **Deleted, disabled and hidden products leave the assistant.** A deleted
  product is removed within a minute (new `catalog_product_delete_after`
  observer); a product saved as disabled or not visible in the catalogue or
  search is removed instead of re-sent.
- **Attribute list with labels.** Searchable, filterable and
  storefront-visible attributes are also sent with their store label, input
  type, option labels and those settings (catalogue schema 2). The flat
  attribute map is unchanged; an IDEA89 API that predates schema 2 ignores
  the list.

### Upgrade notes
- Refresh the configuration cache after updating (System → Cache
  Management) so the new observer is registered.

## [1.0.3] - 2026-10-01

### Changed
- **The catalogue sync key is now part of setup.** Stores created in IDEA89
  from 1 October 2026 need it before their catalogue will sync, so the field
  is no longer marked optional. Create it in your IDEA89 dashboard under
  API & Domains and paste it into System → Configuration → IDEA89 → General
  → Catalogue Sync Key.

### Fixed
- **Sync Now says why a sync was refused instead of reporting success.** When
  IDEA89 turns a sync away because the sync key is missing or out of date,
  Sync Now shows IDEA89's explanation and the full sync stops after the first
  refused batch, without updating "last synced".
- **Test Connection checks the sync key too.** It now asks IDEA89 whether a
  catalogue sync from this store would be accepted, with nothing written, and
  reports a missing or replaced sync key straight away.

## [1.0.2] - 2026-10-01

### Added
- **Catalogue sync key.** New optional field under System → Configuration →
  IDEA89 → General → Catalogue sync key. Create the key in your IDEA89
  dashboard under API & Domains and paste it here. Once a sync arrives with
  the key, IDEA89 accepts catalogue updates for your store only when they
  carry it, so a copied API key can no longer change your products or
  offers. Leaving it empty keeps syncing exactly as before.

### Security
- **Guest order lookup can no longer be used to guess orders.** A
  successful lookup no longer resets the per-visitor attempt limit, and
  emails are compared in constant time.

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
