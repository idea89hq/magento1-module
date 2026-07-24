<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Typed accessor for all `idea89/locator/*` config paths.
 *
 * Each getter returns the merchant-configured value, falling back to a
 * hardcoded default when the field is empty. The defaults here MUST
 * mirror what's set in etc/config.xml so a fresh install (before any
 * save) behaves the same as a save-with-default.
 *
 * M2→M1 substitutions:
 *   $scopeConfig->isSetFlag(path)  → Mage::getStoreConfigFlag(path)
 *   $scopeConfig->getValue(path)   → Mage::getStoreConfig(path)
 *   Constructor injection removed  → static Mage:: calls
 *   private const DEFAULTS []      → private static $DEFAULTS [] (PHP 7.4 static property)
 */
class Idea89_Assistant_Model_LocatorConfig
{
    const XML_PATH_ENABLED          = 'idea89/locator/enabled';
    const XML_PATH_URL_PATH         = 'idea89/locator/url_path';
    const XML_PATH_LAYOUT           = 'idea89/locator/layout';
    const XML_PATH_PAGE_TITLE       = 'idea89/locator/page_title';
    const XML_PATH_META_DESCRIPTION = 'idea89/locator/meta_description';
    const XML_PATH_HERO_EYEBROW     = 'idea89/locator/hero_eyebrow';
    const XML_PATH_HERO_H1          = 'idea89/locator/hero_h1';
    const XML_PATH_HERO_SUBHEAD     = 'idea89/locator/hero_subhead';
    const XML_PATH_HELP_HEADING     = 'idea89/locator/help_heading';
    const XML_PATH_HELP_BODY        = 'idea89/locator/help_body';
    const XML_PATH_HELP_CTA_LABEL   = 'idea89/locator/help_cta_label';
    const XML_PATH_HELP_CTA_URL     = 'idea89/locator/help_cta_url';

    /** Default URL slug — also the default frontName registered in the router. */
    const DEFAULT_URL_PATH = 'store-finder';

    /**
     * Slugs we refuse to claim because they collide with Magento core,
     * common store pages, or admin/API surfaces. Without this guard a
     * merchant could accidentally steal /cart, /checkout etc.
     * Lowercase only — matches the same normalisation as getUrlPath().
     *
     * @var string[]
     */
    private static $RESERVED_SLUGS = [
        'admin', 'api', 'graphql', 'rest', 'soap',
        'cart', 'checkout', 'customer', 'account', 'sales',
        'catalog', 'catalogsearch', 'category', 'product',
        'cms', 'media', 'pub', 'static', 'errors',
        'newsletter', 'wishlist', 'review',
        'idea89',
    ];

    /**
     * Hardcoded copy fallbacks. Keep in sync with etc/config.xml.
     * Keyed by the literal config path string.
     *
     * @var array<string, string>
     */
    private static $DEFAULTS = [
        'idea89/locator/page_title'       => 'Find a store',
        'idea89/locator/meta_description' => 'Find your nearest store or showroom. Search by postcode or browse the map to plan your visit.',
        'idea89/locator/hero_eyebrow'     => 'Showrooms',
        'idea89/locator/hero_h1'          => 'Find a store near you',
        'idea89/locator/hero_subhead'     => 'Walk in, talk to a specialist, and try things before you buy. Use your postcode, share your location, or browse the map.',
        'idea89/locator/help_heading'     => "Can't find a store near you?",
        'idea89/locator/help_body'        => 'Our team can point you to the nearest stockist, arrange a click & collect, or guide you through ordering online.',
        'idea89/locator/help_cta_label'   => 'Get in touch',
        'idea89/locator/help_cta_url'     => '/contact',
    ];

    /**
     * Exposed for the save-time validator so the admin form and the runtime
     * guard share one list.
     *
     * @return string[]
     */
    public static function reservedSlugs(): array
    {
        return self::$RESERVED_SLUGS;
    }

    public function isEnabled(): bool
    {
        // Default Yes — un-configured fresh install gets the locator page live.
        $value = Mage::getStoreConfig(self::XML_PATH_ENABLED);
        return $value === null
            ? true
            : (bool) Mage::getStoreConfigFlag(self::XML_PATH_ENABLED);
    }

    /**
     * The merchant's custom URL slug for the store finder page.
     * Returns the sanitised slug (no leading/trailing slash). Falls
     * back to the default 'store-finder' when empty.
     */
    public function getUrlPath(): string
    {
        $raw  = (string) Mage::getStoreConfig(self::XML_PATH_URL_PATH);
        $slug = strtolower(trim($raw, "/ \t\n\r\0\x0B"));
        // Allow letters, digits, hyphens AND underscores (same as validate-code).
        $slug = preg_replace('/[^a-z0-9_\-]/', '', $slug ?? '') ?? '';
        if ($slug === '') {
            return self::DEFAULT_URL_PATH;
        }
        if (in_array($slug, self::$RESERVED_SLUGS, true)) {
            return self::DEFAULT_URL_PATH;
        }
        return $slug;
    }

    /**
     * 'fullwidth' | 'boxed' | '' (empty = defer to dashboard).
     */
    public function getLayout(): string
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_LAYOUT);
        return in_array($value, ['fullwidth', 'boxed'], true) ? $value : '';
    }

    public function getPageTitle(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_PAGE_TITLE);
    }

    public function getMetaDescription(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_META_DESCRIPTION);
    }

    public function getHeroEyebrow(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HERO_EYEBROW);
    }

    public function getHeroH1(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HERO_H1);
    }

    public function getHeroSubhead(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HERO_SUBHEAD);
    }

    public function getHelpHeading(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HELP_HEADING);
    }

    public function getHelpBody(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HELP_BODY);
    }

    public function getHelpCtaLabel(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HELP_CTA_LABEL);
    }

    public function getHelpCtaUrl(): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HELP_CTA_URL);
    }

    /**
     * Stable hash of all merchant-configurable content fields. Used by
     * Block/Locator::getCacheKeyInfo so FPC entries auto-invalidate
     * when any locator content changes — no observer needed.
     */
    public function getContentVersion(): string
    {
        return substr(hash('sha256', implode('|', [
            $this->getUrlPath(),
            $this->getLayout(),
            $this->getPageTitle(),
            $this->getMetaDescription(),
            $this->getHeroEyebrow(),
            $this->getHeroH1(),
            $this->getHeroSubhead(),
            $this->getHelpHeading(),
            $this->getHelpBody(),
            $this->getHelpCtaLabel(),
            $this->getHelpCtaUrl(),
        ])), 0, 8);
    }

    private function getStringOrDefault(string $path): string
    {
        $value = (string) Mage::getStoreConfig($path);
        if ($value !== '') {
            return $value;
        }
        return self::$DEFAULTS[$path] ?? '';
    }
}
