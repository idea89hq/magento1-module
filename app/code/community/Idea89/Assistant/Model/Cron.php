<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Scheduled cron jobs for the IDEA89 Assistant module.
 *
 * Factory alias: idea89_assistant/cron
 *
 * Cron schedules (configured in etc/config.xml <crontab>):
 *   dailySync       — 0 2 * * *   (daily at 02:00)
 *   drainSyncQueue  — * * * * *   (every minute)
 *   syncStock       — 0 3 * * *   (daily at 03:00)
 *   syncPromos      — every-15-mins cron (cron_expr: *\/15 * * * *)
 *
 * M2 → M1 substitutions:
 *   Constructor DI                  → Mage::getModel() calls inline (no DI container)
 *   WriterInterface::save()         → Mage::getConfig()->saveConfig()
 *   ScopeConfigInterface::getValue() → Mage::getStoreConfig()
 *   ResourceConnection              → Mage::getSingleton('core/resource')->getConnection() + getTableName()
 *   SalesRule CollectionFactory     → Mage::getModel('salesrule/rule')->getCollection()
 *   Psr\Log constants               → Zend_Log::INFO / Zend_Log::ERR / Zend_Log::WARN
 */
class Idea89_Assistant_Model_Cron
{
    private const XML_PATH_QUEUE = 'idea89/sync/pending_product_ids';
    private const BATCH_SIZE     = 200;

    /**
     * Full catalog + content sync — runs nightly at 02:00.
     * Ports Idea89\Assistant\Cron\DailySync::execute() from M2.
     */
    public function dailySync(): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        if (!$config->isEnabled() || !$config->getApiKey()) {
            return;
        }

        if ($config->isSyncProducts()) {
            /** @var Idea89_Assistant_Model_Sync_CatalogSyncer $catalogSyncer */
            $catalogSyncer = Mage::getModel('idea89_assistant/sync_catalogSyncer');
            $catalogSyncer->syncAll();
        }

        /** @var Idea89_Assistant_Model_Sync_ContentSyncer $contentSyncer */
        $contentSyncer = Mage::getModel('idea89_assistant/sync_contentSyncer');
        $contentSyncer->syncAll();
    }

    /**
     * Drain the pending product sync queue written by the productSaved observer.
     * Reads the CSV from core_config_data, clears it, loads each product, and upserts.
     * Runs every minute. Missed products are reconciled by the daily full sync.
     *
     * Ports Idea89\Assistant\Cron\DrainSyncQueue::execute() from M2.
     */
    public function drainSyncQueue(): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        if (!$config->isEnabled() || !$config->getApiKey()) {
            return;
        }

        // Read directly from DB so we see values written by the observer in a prior
        // PHP process, not the stale Magento file-cache snapshot from app init.
        $conn = Mage::getSingleton('core/resource')->getConnection('core_write');

        // Products deleted in Magento (productDeleted observer): removed from the assistant.
        $deletedRaw = (string) $conn->fetchOne(
            "SELECT value FROM core_config_data WHERE path = ? AND scope = 'default' LIMIT 1",
            ['idea89/sync/pending_deleted_ids']
        );
        $deleted = array_values(array_filter(array_unique(explode(',', $deletedRaw))));
        if (!empty($deleted)) {
            Mage::getConfig()->saveConfig('idea89/sync/pending_deleted_ids', '');
            /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
            $client = Mage::getModel('idea89_assistant/client_idea89Client');
            $client->deleteProducts($deleted, $config->getApiKey(), $config->getApiUrl());
        }

        $raw  = (string) $conn->fetchOne(
            "SELECT value FROM core_config_data WHERE path = ? AND scope = 'default' LIMIT 1",
            [self::XML_PATH_QUEUE]
        );
        $ids = array_filter(array_unique(explode(',', $raw)));

        if (empty($ids)) {
            return;
        }

        // Clear the queue BEFORE processing — if sync fails, we log it;
        // the nightly full sync reconciles any missed products.
        Mage::getConfig()->saveConfig(self::XML_PATH_QUEUE, '');

        Mage::log('IDEA89: draining sync queue count=' . count($ids), Zend_Log::INFO, 'idea89.log', true);

        /** @var Idea89_Assistant_Model_Sync_CatalogSyncer $syncer */
        $syncer = Mage::getModel('idea89_assistant/sync_catalogSyncer');
        foreach ($ids as $productId) {
            $syncer->syncProduct((int) $productId);
        }
    }

    /**
     * Push stock qty + in_stock for every product in the default stock (stock_id=1).
     * Uses the lightweight /v1/catalog/stock endpoint — does not re-embed.
     * Runs nightly at 03:00.
     *
     * Ports Idea89\Assistant\Cron\SyncStock::execute() from M2.
     */
    public function syncStock(): void
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

        /** @var Mage_Core_Model_Resource $resource */
        $resource   = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');
        $table      = $resource->getTableName('cataloginventory/stock_item');

        // stock_id = 1 is the default stock, present on all M1/OpenMage installs.
        $rows  = $connection->fetchAll(
            $connection->select()
                ->from($table, ['product_id', 'qty', 'is_in_stock'])
                ->where('stock_id = ?', 1)
        );

        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');
        $batch  = [];
        $synced = 0;

        foreach ($rows as $row) {
            $batch[] = [
                'external_id' => (string) $row['product_id'],
                'in_stock'    => (bool) $row['is_in_stock'],
                'stock_qty'   => (int) $row['qty'],
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                if ($client->upsertStock($batch, $apiKey, $apiUrl)) {
                    $synced += count($batch);
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            if ($client->upsertStock($batch, $apiKey, $apiUrl)) {
                $synced += count($batch);
            }
        }

        Mage::log('IDEA89: stock sync complete count=' . $synced, Zend_Log::INFO, 'idea89.log', true);
    }

    /**
     * Full push of all cart price rules with a specific coupon code.
     * Idempotent upsert on the API side handles duplicates.
     * Runs every 15 minutes.
     *
     * M1 note: coupon code lives directly on Mage_SalesRule_Model_Rule via getCouponCode();
     * M2's getPrimaryCoupon()->getCode() is not available in M1/OpenMage.
     *
     * Ports Idea89\Assistant\Cron\SyncPromos::execute() from M2.
     */
    public function syncPromos(): void
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

        /** @var Mage_SalesRule_Model_Resource_Rule_Collection $collection */
        $collection = Mage::getModel('salesrule/rule')
            ->getCollection()
            ->addFieldToFilter('coupon_type', Mage_SalesRule_Model_Rule::COUPON_TYPE_SPECIFIC);

        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');
        $batch  = [];
        $synced = 0;

        /** @var Mage_SalesRule_Model_Rule $rule */
        foreach ($collection as $rule) {
            $code = trim((string) $rule->getCouponCode());
            if ($code === '') {
                continue;
            }

            $toDate  = $rule->getToDate();
            $batch[] = [
                'external_id' => (string) $rule->getId(),
                'code'        => $code,
                'description' => $rule->getName() ?: $code,
                'expires_at'  => $toDate ? gmdate('Y-m-d\TH:i:s\Z', strtotime($toDate . ' 23:59:59')) : null,
                'is_active'   => (bool) $rule->getIsActive(),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                if ($client->upsertPromos($batch, $apiKey, $apiUrl)) {
                    $synced += count($batch);
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            if ($client->upsertPromos($batch, $apiKey, $apiUrl)) {
                $synced += count($batch);
            }
        }

        Mage::log('IDEA89: promo sync complete count=' . $synced, Zend_Log::INFO, 'idea89.log', true);
    }
}
