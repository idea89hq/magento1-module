# OpenMage Distribution Brief

Internal reference for distributing `idea89/magento1-assistant` via Packagist,
modman, and GitHub release archives.

Kept inside the module so anyone preparing a release knows exactly what the
package should look like. Not merchant-facing documentation.

---

## Package identity

| Field | Value |
|-------|-------|
| Composer name | `idea89/magento1-assistant` |
| Composer type | `magento-module` |
| Version | from `composer.json` `version` and `app/etc/modules/Idea89_Assistant.xml` (must match) |
| Platform | OpenMage LTS 20.x / 21.x, Magento CE 1.9.x |
| PHP | 7.4, 8.0, 8.1, 8.2, 8.3 |
| License | OSL-3.0 (see `LICENSE`) |
| Vendor | 4K Technologies Ltd |
| Vendor contact | support@idea89.com |
| Homepage | https://idea89.com |
| Support docs | https://idea89.com |
| Issue tracker | https://github.com/idea89hq/magento1-module/issues |

---

## Features

- AI chat widget (floating, mobile-responsive, loaded from IDEA89 CDN)
- Full catalog sync: products, variants, attributes, stock, reviews, categories, CMS pages, price rules
- Real-time observers: product save, stock update, cart price rule save
- Cron-based queue drain (every minute) and nightly full re-sync
- In-chat order tracking with carrier link (logged-in and guest flows)
- Personalization identity token and live-products endpoint
- Store Locator page and CMS widget (Pro plan)
- Admin configuration under System > Configuration > IDEA89
- Test Connection and Sync Now actions in admin
- Encrypted API key storage using Magento's built-in encryption
- Configurable widget position, assistant name, store context, and API URL override

---

## Distribution channels

### Packagist

The package is published at https://packagist.org/packages/idea89/magento1-assistant.

Install command:

```bash
composer require idea89/magento1-assistant
```

Requires `magento-hackathon/magento-composer-installer` in the project's `composer.json`
(standard for Magento 1 Composer-managed installs).

### modman

```bash
modman clone https://github.com/idea89hq/magento1-module
modman deploy idea89hq/magento1-module
```

### GitHub release archive

Each release publishes a `.zip` of the module root. Merchants extract and copy `app/`
into their Magento root manually.

---

## Pre-release checklist

Before tagging a release and pushing to Packagist:

### Packaging checks

- [ ] `composer.json` `version` matches `app/etc/modules/Idea89_Assistant.xml` `version`
- [ ] `modman` file maps only `app    app` (no `skin` entry: the widget loads from CDN)
- [ ] `LICENSE` file present (OSL-3.0)
- [ ] `README.md` present with all three install methods documented
- [ ] `CHANGELOG.md` updated with the new version entry

### Code quality (run inside a Warden or clean Magento 1 sandbox)

```bash
# MEQP1: Magento Extension Quality Program for Magento 1
composer require --dev magento/magento1-coding-standard
vendor/bin/phpcs --standard=MEQP1 \
  app/code/community/Idea89/Assistant

# Basic PHP syntax check across all files
find app/code/community/Idea89/Assistant -name "*.php" \
  -exec php -l {} \; | grep -v "No syntax errors"
```

### Security checks

| Check | Expected |
|-------|----------|
| No `eval()`, `exec()`, `system()` calls | Zero hits |
| All SQL via Magento model / resource model layer (no raw `mysql_*`) | Verified |
| No hard-coded credentials | API key entered by merchant, stored via `Mage::getConfig()->encrypt()` |
| Output in `.phtml` templates uses `$this->htmlEscape(...)` | Verified |
| No PII leaving the merchant origin via server-side calls | Order tracking is browser-side; API calls carry only order IDs and store key |

### Smoke test (against a live OpenMage sandbox)

```bash
# Deploy via Composer
composer require idea89/magento1-assistant
# or: modman deploy / manual copy

# Then in the Magento root:
php -f shell/indexer.php -- --reindex
bin/n98-magerun.phar cache:flush
```

Verify in admin:

1. System > Configuration > IDEA89 > AI Shopping Assistant loads without PHP errors.
2. Save a valid API key. Test Connection returns success.
3. Click Sync Now. Check the IDEA89 dashboard: products appear under the store.
4. Storefront: the widget launcher is present on the home page and a PDP.
5. Store Locator: navigate to `/store-finder`. The locator renders or returns a 404
   (depending on whether the Pro plan and at least one location are configured).

---

## Coding standards note

OpenMage LTS uses Magento 1 coding conventions: class-per-file under
`app/code/community/Idea89/Assistant/`, XML-based DI and layout, factory
method access via `Mage::getModel(...)`. This module follows those conventions
throughout. There is no PSR-4 autoloading (that is a Magento 2 concept).

---

## CSP gap (documented for release notes and README)

Magento 1 / OpenMage has no CSP framework. The module cannot inject
`connect-src https://api.idea89.com` automatically. The README documents this
and asks merchants with enforced CSP headers to add the whitelist manually.
This is an expected platform limitation, not a bug.

---

## Contact

All distribution and support correspondence goes to support@idea89.com.
Keep this file current with any changes to the distribution setup so future
maintainers have the full picture.
