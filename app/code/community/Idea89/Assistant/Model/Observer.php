<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Event observers for the IDEA89 Assistant module.
 *
 * Registered events (confirmed against OpenMage src/app/code/core/Mage/):
 *   catalog_product_save_after           — _eventPrefix='catalog_product'      _eventObject='product'
 *   cataloginventory_stock_item_save_after — _eventPrefix='cataloginventory_stock_item' _eventObject='item'
 *   salesrule_rule_save_after            — _eventPrefix='salesrule_rule'        _eventObject='rule'
 *   salesrule_rule_delete_after          — same model, fired on delete
 *
 * Factory alias: idea89_assistant/observer
 *
 * M2 → M1 substitutions:
 *   WriterInterface::save()      → Mage::getConfig()->saveConfig()
 *   ScopeConfigInterface::getValue() → Mage::getStoreConfig()
 *   LoggerInterface              → Mage::log(..., 'idea89.log')
 *   Rule::COUPON_TYPE_SPECIFIC   → Mage_SalesRule_Model_Rule::COUPON_TYPE_SPECIFIC (same value: 2)
 *   $rule->getPrimaryCoupon()->getCode() → $rule->getCouponCode() (M1 stores code directly on rule)
 */
class Idea89_Assistant_Model_Observer
{
    private const XML_PATH_QUEUE = 'idea89/sync/pending_product_ids';

    /**
     * Queue a product ID for incremental sync after save.
     * Does not make HTTP calls inline — writes to core_config_data as a CSV queue.
     * The drain cron (idea89_drain_sync_queue, every minute) picks it up.
     *
     * Fires on: catalog_product_save_after
     */
    public function productSaved(Varien_Event_Observer $observer): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        if (!$config->isEnabled()) {
            return;
        }

        /** @var Mage_Catalog_Model_Product $product */
        $product   = $observer->getEvent()->getProduct();
        $productId = (int) $product->getId();
        if (!$productId) {
            return;
        }

        // Simple CSV queue in core_config_data — handles low-frequency saves fine.
        // Read directly from DB (bypass Magento file cache) so we see what the cron
        // wrote/cleared in a previous PHP process without a full cache reinit.
        $conn     = Mage::getSingleton('core/resource')->getConnection('core_write');
        $existing = (string) $conn->fetchOne(
            "SELECT value FROM core_config_data WHERE path = ? AND scope = 'default' LIMIT 1",
            [self::XML_PATH_QUEUE]
        );
        $ids      = array_filter(explode(',', $existing));

        if (!in_array((string) $productId, $ids, true)) {
            $ids[] = (string) $productId;
            Mage::getConfig()->saveConfig(self::XML_PATH_QUEUE, implode(',', $ids));
        }

        Mage::log('IDEA89: queued product for sync id=' . $productId, Zend_Log::INFO, 'idea89.log', true);
    }

    /**
     * Push stock qty + in_stock to the API when a stock item is saved.
     * Covers order placement (qty decrement), admin manual edits, and imports.
     *
     * Fires on: cataloginventory_stock_item_save_after
     */
    public function stockSaved(Varien_Event_Observer $observer): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        if (!$config->isEnabled()) {
            return;
        }

        $apiKey = $config->getApiKey();
        $apiUrl = $config->getApiUrl();
        if (!$apiKey || !$apiUrl) {
            return;
        }

        /** @var Mage_CatalogInventory_Model_Stock_Item $item */
        $item = $observer->getEvent()->getItem();
        if (!$item || !$item->getProductId()) {
            return;
        }

        $payload = [[
            'external_id' => (string) $item->getProductId(),
            'in_stock'    => (bool) $item->getIsInStock(),
            'stock_qty'   => (int) $item->getQty(),
        ]];

        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');
        $result = $client->upsertStock($payload, $apiKey, $apiUrl);
        if (!$result) {
            Mage::log('IDEA89: failed to sync stock for product ' . $item->getProductId(), Zend_Log::ERR, 'idea89.log', true);
        }
    }

    /**
     * Push a single cart price rule to the API when it is saved or deleted.
     * Only rules with COUPON_TYPE_SPECIFIC (2) and a non-empty coupon code are synced.
     * In M1 the coupon code lives directly on the rule; M2's getPrimaryCoupon() is not present.
     *
     * Fires on: salesrule_rule_save_after, salesrule_rule_delete_after
     */
    public function salesRuleSaved(Varien_Event_Observer $observer): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        if (!$config->isEnabled()) {
            return;
        }

        $apiKey = $config->getApiKey();
        $apiUrl = $config->getApiUrl();
        if (!$apiKey || !$apiUrl) {
            return;
        }

        /** @var Mage_SalesRule_Model_Rule $rule */
        $rule = $observer->getEvent()->getRule();
        if (!$rule) {
            return;
        }

        // Skip rules without a specific coupon code
        if ((int) $rule->getCouponType() !== Mage_SalesRule_Model_Rule::COUPON_TYPE_SPECIFIC) {
            return;
        }
        $code = trim((string) $rule->getCouponCode());
        if ($code === '') {
            return;
        }

        $toDate   = $rule->getToDate();
        $isDelete = $observer->getEvent()->getName() === 'salesrule_rule_delete_after';
        $payload  = [
            'external_id' => (string) $rule->getId(),
            'code'        => $code,
            'description' => $rule->getName() ?: $code,
            'expires_at'  => $toDate ? gmdate('Y-m-d\TH:i:s\Z', strtotime($toDate . ' 23:59:59')) : null,
            'is_active'   => $isDelete ? false : (bool) $rule->getIsActive(),
        ];

        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');
        $result = $client->upsertPromos([$payload], $apiKey, $apiUrl);
        if (!$result) {
            Mage::log('IDEA89: failed to sync promo rule ' . $rule->getId(), Zend_Log::ERR, 'idea89.log', true);
        }
    }

    /**
     * Register the custom locator router so the merchant's configured slug
     * (e.g. 'showrooms') routes to IndexController without a 404.
     * The default slug ('store-finder') is already handled by the standard router.
     *
     * Fires on: controller_front_init_routers
     */
    public function addLocatorRouter(Varien_Event_Observer $observer): void
    {
        $observer->getEvent()->getFront()->addRouter(
            'idea89_locator',
            Mage::getModel('idea89_assistant/locator_router')
        );
    }
}
