<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Typed accessor for `idea89/order_tracking/*` config paths.
 *
 * Mirrors LocatorConfig — every getter falls back to a hardcoded default
 * matching etc/config.xml so fresh installs (before any save) get the
 * same behaviour as a save-with-defaults.
 *
 * M2→M1 substitutions:
 *   $scopeConfig->isSetFlag(path)  → Mage::getStoreConfigFlag(path)
 *   $scopeConfig->getValue(path)   → Mage::getStoreConfig(path)
 *   Constructor injection removed  → static Mage:: calls
 *   isTrackingButtonShown()        → showTrackingButton() (brief interface name)
 */
class Idea89_Assistant_Model_OrderTrackingConfig
{
    const XML_PATH_ENABLED              = 'idea89/order_tracking/enabled';
    const XML_PATH_SUPPORT_URL          = 'idea89/order_tracking/support_url';
    const XML_PATH_SUPPORT_LABEL        = 'idea89/order_tracking/support_label';
    const XML_PATH_MAX_RECENT_ORDERS    = 'idea89/order_tracking/max_recent_orders';
    const XML_PATH_SHOW_TRACKING_BUTTON = 'idea89/order_tracking/show_tracking_button';

    const DEFAULT_SUPPORT_URL   = '/contact';
    const DEFAULT_SUPPORT_LABEL = 'Contact support';
    const DEFAULT_MAX_RECENT    = 3;
    const HARD_MAX_RECENT       = 10;
    const HARD_MIN_RECENT       = 1;

    public function isEnabled(): bool
    {
        // Default Yes when un-configured — fresh installs get order tracking live.
        $value = Mage::getStoreConfig(self::XML_PATH_ENABLED);
        return $value === null
            ? true
            : (bool) Mage::getStoreConfigFlag(self::XML_PATH_ENABLED);
    }

    public function getSupportUrl(): string
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_SUPPORT_URL);
        return $value !== '' ? $value : self::DEFAULT_SUPPORT_URL;
    }

    public function getSupportLabel(): string
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_SUPPORT_LABEL);
        return $value !== '' ? $value : self::DEFAULT_SUPPORT_LABEL;
    }

    /**
     * Clamped to [HARD_MIN_RECENT, HARD_MAX_RECENT]. Anything outside this
     * range is bounded silently so the widget can rely on a safe count.
     */
    public function getMaxRecentOrders(): int
    {
        $raw   = Mage::getStoreConfig(self::XML_PATH_MAX_RECENT_ORDERS);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_MAX_RECENT;
        return max(self::HARD_MIN_RECENT, min(self::HARD_MAX_RECENT, $value));
    }

    public function showTrackingButton(): bool
    {
        $value = Mage::getStoreConfig(self::XML_PATH_SHOW_TRACKING_BUTTON);
        return $value === null
            ? true
            : (bool) Mage::getStoreConfigFlag(self::XML_PATH_SHOW_TRACKING_BUTTON);
    }
}
