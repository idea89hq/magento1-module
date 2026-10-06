# IDEA89 AI Shopping Assistant for OpenMage / Magento 1

Turn your OpenMage or Magento 1 storefront into a conversion machine. IDEA89 adds an AI-powered shopping assistant that answers product questions, recommends what to buy, and surfaces promotions in your brand voice.

**5-minute install. No theme changes. No dev work.**

[![Packagist Version](https://img.shields.io/packagist/v/idea89/magento1-assistant)](https://packagist.org/packages/idea89/magento1-assistant)
[![OpenMage](https://img.shields.io/badge/OpenMage-LTS%2020.x%2F21.x-orange)](https://github.com/OpenMage/magento-lts)
[![PHP](https://img.shields.io/badge/PHP-7.4%E2%80%938.3-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-OSL--3.0-green)](LICENSE)

---

## What it does

| Feature | Description |
|---------|-------------|
| **Smart product recommendations** | AI understands natural-language queries like "something waterproof under 100 pounds" and finds the right products from your catalogue |
| **Real-time catalogue sync** | Products, variants, prices, stock levels, and reviews are synced automatically. Out-of-stock items are never recommended |
| **Brand voice** | Configure your assistant's name, tone, and store context. It answers like a member of your team |
| **Promotion awareness** | Active cart price rules are synced so the assistant can surface relevant discounts |
| **In-chat order tracking** | When a shopper asks "where is my order?" the assistant surfaces a compact order card with status, items, and a carrier tracking link. Logged-in customers see their last 3 orders; guests verify with order number and email |
| **Personalization** | The assistant recognises returning shoppers and tailors recommendations based on browse and purchase history |
| **Store Locator** _(Pro plan)_ | Physical showroom finder with map, postcode search, hours, photos, and directions in chat and on a dedicated page |
| **Built-in analytics** | Track conversations, conversion rates, and top queries from the merchant dashboard |
| **GDPR-ready** | EU-hosted, no customer data used for AI training, PII redaction before model calls |

Everything listed above works the same way as it does in the Magento 2 module. The Magento 2 module additionally offers in-chat checkout and agentic commerce, which are not available for Magento 1 or OpenMage.

---

## Requirements

- OpenMage LTS 20.x or 21.x (or Magento CE 1.9.x on PHP 7.4)
- PHP 7.4, 8.0, 8.1, 8.2, or 8.3
- An IDEA89 account: [start your free trial](https://app.idea89.com/sign-up)

---

## Installation

Three install methods are supported. After any of them, flush the Magento cache: **System > Cache Management > Flush Magento Cache**.

### Method A: Composer

Add the Magento Composer installer and the module, then deploy:

```bash
composer require magento-hackathon/magento-composer-installer idea89/magento1-assistant
```

If your project already uses `magento-hackathon/magento-composer-installer`, just run:

```bash
composer require idea89/magento1-assistant
```

Composer places module files under `app/code/community/Idea89/Assistant/` automatically.

### Method B: modman

```bash
# First install: clone and deploy
modman clone https://github.com/idea89hq/magento1-module
modman deploy idea89hq/magento1-module

# Subsequent updates
modman update idea89hq/magento1-module
modman deploy idea89hq/magento1-module
```

### Method C: Manual file copy

1. Download the latest release archive from [GitHub](https://github.com/idea89hq/magento1-module).
2. Extract and copy the `app/` directory into your Magento root so that it merges with the existing `app/` tree:
   ```
   cp -r magento1-module/app/* /path/to/your/magento/app/
   ```
3. Flush the cache: **System > Cache Management > Flush Magento Cache**.

No database tables are created in your Magento instance. All data is stored on the IDEA89 platform.

---

## Configuration

Navigate to **System > Configuration > IDEA89 > AI Shopping Assistant** in Magento Admin.

### General

| Setting | Description |
|---------|-------------|
| **Enable Widget** | Turn the chat widget on/off |
| **API Key** | Your API key from the IDEA89 dashboard (stored encrypted) |
| **Assistant Name** | Name shown in the widget header (e.g. "Aria", "Shop Helper") |
| **Store Context** | Describe what your store sells so the AI can answer general questions |
| **Widget Position** | Bottom-right or bottom-left |
| **Test Connection** | Verify your API key works |
| **Sync Now** | Manually trigger a full catalogue sync |

### Content Sync

Choose what gets synced to IDEA89:

- **Products**: names, descriptions, prices, images, attributes, variants, stock, reviews
- **Categories**: so the assistant knows your catalogue structure
- **CMS Pages**: About Us, FAQs, policies. The assistant can answer "what is your return policy?"
- **Store Info**: store name and context description

### Order Tracking

| Setting | Default | Notes |
|---------|---------|-------|
| **Enable Order Tracking** | Yes | Master toggle. When No, the chat assistant will not surface an order card |
| **Contact Support URL** | `/contact` | Where the "Contact support" button on the order card sends shoppers |
| **Contact Support Button Label** | "Contact support" | Match your tone: "Talk to us", "Email the team" |
| **Max Recent Orders Shown** | 3 | How many recent orders to show a logged-in customer (1 to 10) |
| **Show Carrier Tracking Button** | Yes | Surfaces a "Track parcel" button when a carrier tracking link is available |

### Store Locator _(Pro plan)_

| Setting | Default | Notes |
|---------|---------|-------|
| **Enable Store Finder Page** | Yes | Master toggle for the locator page and CMS widget |
| **URL Path** | `store-finder` | Any slug works: `showrooms`, `branches`, `find-a-shop` |
| **Page Title / Meta Description** | (sensible defaults) | Standard SEO control over the page head |
| **Hero Copy** | (sensible defaults) | Override in-page headings without theme edits |
| **Help Section** | "Contact us" link to `/contact` | The help section below the map |

Locations are managed in the [IDEA89 dashboard](https://app.idea89.com) under Locator. The chat assistant uses them automatically when a shopper asks "where is your nearest store?"

The locator also ships as a CMS widget: drop **IDEA89 Store Locator** into any CMS page or static block from the widget picker (**CMS > Widgets**).

### Personalization

| Setting | Description |
|---------|-------------|
| **Enable Personalization** | When enabled, the assistant tailors recommendations using the shopper's browse and purchase history |
| **Token Lifetime (hours)** | How long the personalization identity token is valid (default: 720 hours) |

### Advanced

| Setting | Description |
|---------|-------------|
| **API URL** | Override for self-hosted or enterprise deployments. Leave blank for the default `https://api.idea89.com` |

---

## How syncing works

| Trigger | What happens |
|---------|--------------|
| **Product saved** | Changed product is queued and synced within 1 minute |
| **Stock update** | Stock changes are synced within 1 minute |
| **Price rule saved** | Active promotions are synced immediately |
| **Nightly cron** | Full catalogue re-sync as a safety net |
| **Manual sync** | Click "Sync Now" in admin to push everything immediately |

All syncs are idempotent: receiving the same product twice is safe and expected.

---

## The widget

The assistant appears as a floating chat widget on your storefront. It includes:

- Conversational AI that understands your products
- Product cards with images, prices, and add-to-cart buttons
- Promotional banners for active cart price rules
- Quick-reply chips for common questions
- Mobile-responsive design
- Dark/light theme support
- No impact on your Magento theme or page speed (loaded asynchronously)

The widget is served from the IDEA89 CDN. No static content is added to your Magento deployment.

---

## Important notes

### Content-Security-Policy (CSP)

OpenMage / Magento 1 does not have a built-in CSP framework. If your store or hosting environment enforces a `Content-Security-Policy` header, you must manually whitelist the IDEA89 endpoints. Add the following directives to your policy:

```
connect-src https://api.idea89.com https://widget.idea89.com;
script-src  https://widget.idea89.com;
```

The module cannot inject these directives automatically. Without this whitelist, browsers enforcing a strict CSP will block the widget and API calls.

### Layout handle

The widget block is injected via the `before_body_end` layout handle, which is present in the standard OpenMage `rwd` and `base` themes. If your custom theme lacks this handle, open `app/design/frontend/base/default/layout/idea89.xml` and re-point the block reference to `footer` or `after_body_start` to match your theme structure.

---

## Pricing

| Plan | Price | Conversations/mo |
|------|-------|-------------------|
| **Free trial** | Free for 14 days (all Pro features) | 100 conversations |
| **Starter** | £49/mo | 1,000 |
| **Growth** | £149/mo | 10,000 |
| **Pro** | £349/mo | 50,000 |

Save 10% with annual billing. All plans include the full feature set.

[Start your free trial](https://app.idea89.com/sign-up): no credit card required.

---

## Uninstalling

1. Disable the module by setting **Enable Widget = No** in admin config.
2. Remove the module files (reverse whichever install method you used):
   - Composer: `composer remove idea89/magento1-assistant`
   - modman: `modman remove idea89hq/magento1-module`
   - Manual: delete `app/code/community/Idea89/`, `app/etc/modules/Idea89_Assistant.xml`, and the `app/design/frontend/base/default/layout/idea89.xml` and `template/idea89/` files.
3. Flush the cache: **System > Cache Management > Flush Magento Cache**.

---

## Support

- **Documentation:** [idea89.com](https://idea89.com)
- **Email:** support@idea89.com
- **Dashboard:** [app.idea89.com](https://app.idea89.com)

---

## Running unit tests

The unit tests need PHPUnit 10 or 11 and no OpenMage install. From the
repository root:

```bash
woocommerce-plugin/vendor/bin/phpunit -c magento1-module/tests/phpunit.xml.dist
```

## License

This module is licensed under the [Open Software License 3.0 (OSL-3.0)](https://opensource.org/licenses/OSL-3.0).

Copyright 2026 4K Technologies Ltd.

---

Built by [4K Technologies](https://idea89.com) in the UK.
