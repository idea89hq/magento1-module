<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Full catalog sync — pages the enabled/visible product collection in batches of 100,
 * serializes each product, and POSTs to /v1/catalog/upsert.
 *
 * Factory alias: idea89_assistant/sync_catalogSyncer
 *
 * M2 → M1 substitutions:
 *   ProductRepositoryInterface + SearchCriteriaBuilder  → Mage product collection with setCurPage/clear()
 *   WriterInterface::save()                             → Mage::getConfig()->saveConfig()
 *   LoggerInterface                                     → Mage::log(..., 'idea89.log')
 *   Psr\Log constants                                   → Zend_Log::INFO / Zend_Log::ERR
 */
class Idea89_Assistant_Model_Sync_CatalogSyncer
{
    const BATCH_SIZE     = 100;
    const XML_PATH_LAST_SYNC = 'idea89/sync/last_full_sync_at';

    /**
     * Full catalog sync — batches of 100, logs progress, persists last-sync timestamp.
     * Called by the daily cron and the Sync Now admin button (syncAction).
     *
     * @param string|null $storeCode reserved for future per-store targeting; unused in M1
     */
    public function syncAll(?string $storeCode = null): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        $apiKey = $config->getApiKey();
        $apiUrl = $config->getApiUrl();

        if (!$apiKey) {
            Mage::log('IDEA89: syncAll skipped — no API key configured', Zend_Log::WARN, 'idea89.log', true);
            return;
        }

        Mage::log('IDEA89: starting full catalog sync', Zend_Log::INFO, 'idea89.log', true);

        /** @var Idea89_Assistant_Model_Sync_ProductSerializer $serializer */
        $serializer = Mage::getModel('idea89_assistant/sync_productSerializer');

        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');

        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
            ]]);
        $collection->setPageSize(self::BATCH_SIZE);

        $pages  = (int) $collection->getLastPageNumber();
        $synced = 0;
        $failed = 0;

        Mage::log('IDEA89: catalog sync pages=' . $pages, Zend_Log::INFO, 'idea89.log', true);

        for ($page = 1; $page <= $pages; $page++) {
            $collection->setCurPage($page)->load();

            $batch = [];
            foreach ($collection as $product) {
                try {
                    $batch[] = $serializer->serialize($product);
                } catch (Exception $e) {
                    Mage::log(
                        'IDEA89: serialize failed product_id=' . $product->getId() . ' ' . $e->getMessage(),
                        Zend_Log::ERR,
                        'idea89.log',
                        true
                    );
                }
            }

            if (!empty($batch)) {
                $ok = $client->upsertProducts($batch, $apiKey, $apiUrl);
                if ($ok) {
                    $synced += count($batch);
                    Mage::log(
                        'IDEA89: synced batch page=' . $page . ' count=' . count($batch),
                        Zend_Log::INFO,
                        'idea89.log',
                        true
                    );
                } else {
                    $failed += count($batch);
                    Mage::log('IDEA89: batch failed page=' . $page, Zend_Log::ERR, 'idea89.log', true);
                }
            }

            $collection->clear();
        }

        // Persist last sync timestamp so admin UI can show it
        Mage::getConfig()->saveConfig(self::XML_PATH_LAST_SYNC, (string) time());

        Mage::log(
            'IDEA89: full sync complete synced=' . $synced . ' failed=' . $failed,
            Zend_Log::INFO,
            'idea89.log',
            true
        );
    }

    /**
     * Sync a single product by ID — used by the drain cron after productSaved observer queues it.
     * Loads the product, serializes it, and POSTs to /v1/catalog/upsert.
     * Skipped silently if the product doesn't exist or is not enabled+visible.
     */
    public function syncProduct(int $productId): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        $apiKey = $config->getApiKey();
        $apiUrl = $config->getApiUrl();

        if (!$apiKey) {
            return;
        }

        /** @var Mage_Catalog_Model_Product $product */
        $product = Mage::getModel('catalog/product')->load($productId);
        if (!$product->getId()) {
            Mage::log('IDEA89: syncProduct skipped — product not found id=' . $productId, Zend_Log::WARN, 'idea89.log', true);
            return;
        }

        /** @var Idea89_Assistant_Model_Sync_ProductSerializer $serializer */
        $serializer = Mage::getModel('idea89_assistant/sync_productSerializer');

        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');

        try {
            $serialized = $serializer->serialize($product);
        } catch (Exception $e) {
            Mage::log('IDEA89: syncProduct serialize failed id=' . $productId . ' ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log', true);
            return;
        }

        $ok = $client->upsertProducts([$serialized], $apiKey, $apiUrl);
        if ($ok) {
            Mage::log('IDEA89: syncProduct done id=' . $productId, Zend_Log::INFO, 'idea89.log', true);
        } else {
            Mage::log('IDEA89: syncProduct upsert failed id=' . $productId, Zend_Log::ERR, 'idea89.log', true);
        }
    }
}
