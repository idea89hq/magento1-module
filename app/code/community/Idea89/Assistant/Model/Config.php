<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Typed wrapper around core_config_data for all IDEA89 config paths.
 * Always read config through here — never call Mage::getStoreConfig() directly from other classes.
 *
 * M2→M1 substitutions:
 *   $scopeConfig->isSetFlag(path)   → Mage::getStoreConfigFlag(path)
 *   $scopeConfig->getValue(path)    → Mage::getStoreConfig(path)
 *   $encryptor->decrypt($v)         → Mage::helper('core')->decrypt($v)
 *   Constructor injection removed   → static Mage:: calls
 */
class Idea89_Assistant_Model_Config
{
    const XML_PATH_ENABLED        = 'idea89/general/enabled';
    const XML_PATH_API_KEY        = 'idea89/general/api_key';
    const XML_PATH_ASSISTANT_NAME = 'idea89/general/assistant_name';
    const XML_PATH_STORE_CONTEXT  = 'idea89/general/store_context';
    const XML_PATH_API_URL        = 'idea89/advanced/api_url';
    const XML_PATH_WIDGET_URL     = 'idea89/advanced/widget_url';
    const XML_PATH_POSITION       = 'idea89/widget/position';
    const XML_PATH_COLOR          = 'idea89/widget/brand_color';
    const XML_PATH_SYNC_PRODUCTS  = 'idea89/sync/sync_products';
    const XML_PATH_SYNC_CATS      = 'idea89/sync/sync_categories';
    const XML_PATH_SYNC_CMS       = 'idea89/sync/sync_cms';
    const XML_PATH_SYNC_STORE     = 'idea89/sync/sync_store_info';

    const DEFAULT_API_URL = 'https://api.idea89.com';

    public function isEnabled(): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_ENABLED);
    }

    public function getApiKey(): string
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_API_KEY);
        if ($value === '') {
            return '';
        }
        // M1 obscure/encrypted fields are always stored as ciphertext — always decrypt.
        return (string) Mage::helper('core')->decrypt($value);
    }

    public function getAssistantName(): string
    {
        $name = (string) Mage::getStoreConfig(self::XML_PATH_ASSISTANT_NAME);
        return $name ?: 'Shopping Assistant';
    }

    public function getStoreContext(): string
    {
        return (string) Mage::getStoreConfig(self::XML_PATH_STORE_CONTEXT);
    }

    public function getApiUrl(): string
    {
        $override = (string) Mage::getStoreConfig(self::XML_PATH_API_URL);
        return rtrim($override ?: self::DEFAULT_API_URL, '/');
    }

    /**
     * URL used for the widget script tag (loaded in the shopper's browser).
     * Falls back to getApiUrl() if not set.
     */
    public function getWidgetUrl(): string
    {
        $override = (string) Mage::getStoreConfig(self::XML_PATH_WIDGET_URL);
        return rtrim($override ?: $this->getApiUrl(), '/');
    }

    public function getWidgetPosition(): string
    {
        return (string) Mage::getStoreConfig(self::XML_PATH_POSITION) ?: 'bottom-right';
    }

    /**
     * Returns the merchant-configured brand colour, or empty string when not set.
     * Empty string signals the widget to fall back to the dashboard setting.
     */
    public function getBrandColor(): string
    {
        return (string) Mage::getStoreConfig(self::XML_PATH_COLOR);
    }

    public function isSyncProducts(): bool
    {
        $v = Mage::getStoreConfig(self::XML_PATH_SYNC_PRODUCTS);
        // Default to true when not yet configured
        return $v === null ? true : (bool) Mage::getStoreConfigFlag(self::XML_PATH_SYNC_PRODUCTS);
    }

    public function isSyncCategories(): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_SYNC_CATS);
    }

    public function isSyncCms(): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_SYNC_CMS);
    }

    public function isSyncStoreInfo(): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_SYNC_STORE);
    }
}
