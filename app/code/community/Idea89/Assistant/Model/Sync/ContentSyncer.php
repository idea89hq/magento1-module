<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Syncs store info, categories, and CMS pages to the IDEA89 API.
 * Called during the daily cron and the Sync Now admin button.
 *
 * Factory alias: idea89_assistant/sync_contentSyncer
 *
 * M2 → M1 substitutions:
 *   CategoryCollectionFactory / PageCollectionFactory → Mage::getModel()->getCollection()
 *   StoreManagerInterface                            → Mage::app()->getStore()
 *   LoggerInterface                                  → Mage::log(..., 'idea89.log')
 *   upsertContent()                                  → syncContent() (same endpoint, different name)
 */
class Idea89_Assistant_Model_Sync_ContentSyncer
{
    const BATCH_SIZE = 200;

    /**
     * Sync all enabled content types — store info, categories, CMS pages —
     * respecting the per-type toggle flags from Config.
     */
    public function syncAll(): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        $apiKey = $config->getApiKey();
        $apiUrl = $config->getApiUrl();

        if (!$apiKey) {
            Mage::log('IDEA89 ContentSyncer: skipped — no API key', Zend_Log::WARN, 'idea89.log', true);
            return;
        }

        $items = [];

        if ($config->isSyncStoreInfo()) {
            $items[] = $this->buildStoreInfo($config);
        }

        if ($config->isSyncCategories()) {
            $items = array_merge($items, $this->buildCategories());
        }

        if ($config->isSyncCms()) {
            $items = array_merge($items, $this->buildCmsPages());
        }

        if (empty($items)) {
            Mage::log(
                'IDEA89 ContentSyncer: all content sync toggles off, nothing to send',
                Zend_Log::INFO,
                'idea89.log',
                true
            );
            return;
        }

        Mage::log(
            'IDEA89 ContentSyncer: syncing content items count=' . count($items),
            Zend_Log::INFO,
            'idea89.log',
            true
        );

        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');

        foreach (array_chunk($items, self::BATCH_SIZE) as $batch) {
            $ok = $client->syncContent($batch, $apiKey, $apiUrl);
            if (!$ok) {
                Mage::log('IDEA89 ContentSyncer: batch failed', Zend_Log::ERR, 'idea89.log', true);
            }
        }

        Mage::log('IDEA89 ContentSyncer: done', Zend_Log::INFO, 'idea89.log', true);
    }

    /**
     * Build the store_info content item.
     *
     * @return array<string, string>
     */
    private function buildStoreInfo(Idea89_Assistant_Model_Config $config): array
    {
        $store         = Mage::app()->getStore();
        $storeName     = (string) $store->getName();
        $context       = $config->getStoreContext();
        $assistantName = $config->getAssistantName();

        $body = trim(implode(' ', array_filter([
            $context,
            $context ? '' : 'An online store selling products at ' . $storeName . '.',
            'Assistant name: ' . $assistantName . '.',
        ])));

        return [
            'type'        => 'store_info',
            'external_id' => 'store',
            'title'       => $storeName,
            'body'        => $body,
        ];
    }

    /**
     * Build category content items from all active categories above root level.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCategories(): array
    {
        $store   = Mage::app()->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        /** @var Mage_Catalog_Model_Resource_Category_Collection $collection */
        $collection = Mage::getModel('catalog/category')->getCollection()
            ->addAttributeToSelect(['name', 'url_path', 'is_active', 'level', 'description'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['gt' => 1])
            ->setStoreId($store->getId());

        $items = [];
        foreach ($collection as $cat) {
            $name    = (string) $cat->getName();
            $urlPath = (string) $cat->getData('url_path');
            $desc    = strip_tags((string) $cat->getData('description'));
            $body    = 'Category: ' . str_replace('/', ' > ', $urlPath);
            if ($desc) {
                $body .= "\n" . substr($desc, 0, 500);
            }

            $items[] = [
                'type'        => 'category',
                'external_id' => 'cat_' . $cat->getId(),
                'title'       => $name,
                'body'        => $body,
                'url'         => $urlPath ? $baseUrl . '/' . $urlPath . '.html' : null,
            ];
        }

        return $items;
    }

    /**
     * Build CMS page content items from all active pages with sufficient content.
     *
     * @return array<int, array<string, string>>
     */
    private function buildCmsPages(): array
    {
        /** @var Mage_Cms_Model_Resource_Page_Collection $collection */
        $collection = Mage::getModel('cms/page')->getCollection()
            ->addFieldToFilter('is_active', 1);

        $items = [];
        foreach ($collection as $page) {
            $content = strip_tags((string) $page->getContent());
            // Skip near-empty pages (nav blocks, cookie notices, etc.)
            if (mb_strlen($content) < 80) {
                continue;
            }

            $items[] = [
                'type'        => 'cms_page',
                'external_id' => 'cms_' . $page->getId(),
                'title'       => (string) $page->getTitle(),
                'body'        => mb_substr($content, 0, 2000),
            ];
        }

        return $items;
    }
}
