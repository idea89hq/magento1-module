<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Converts an M1 catalog product into the JSON shape expected by POST /v1/catalog/upsert.
 * Output array shape is identical to the M2 ProductSerializer — same keys, same types.
 *
 * Factory alias: idea89_assistant/sync_productSerializer
 *
 * M2 → M1 substitutions:
 *   CategoryRepositoryInterface::get($id)       → Mage::getModel('catalog/category')->load($id)
 *   StockRegistryInterface::getStockItem($id)   → Mage::getModel('cataloginventory/stock_item')->loadByProduct($p)
 *   ResourceConnection                          → Mage::getSingleton('core/resource')->getConnection('core_read')
 *   StoreManagerInterface                       → Mage::app()->getStore()
 *   $product->getCustomAttributes()             → $product->getAttributes() (filtered)
 *   Configurable::TYPE_CODE                     → string 'configurable'
 *   $typeInstance->getUsedProducts($product)    → $typeInstance->getUsedProducts(null, $product)
 */
class Idea89_Assistant_Model_Sync_ProductSerializer
{
    /**
     * Per-instance category ID → lowercase trimmed name cache.
     * Persists across all serialize() calls in the same syncer run.
     *
     * @var array<int, string|null>
     */
    private array $categoryNameCache = [];

    public function serialize(Mage_Catalog_Model_Product $product): array
    {
        $store   = Mage::app()->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        // Price: use getPrice() as base; getFinalPrice() can be unreliable in
        // collection loads without a price index. If base price is zero, try final.
        $price = (float) $product->getPrice();
        if ($price <= 0) {
            try {
                $fp = $product->getFinalPrice();
                if ($fp !== null && (float) $fp > 0) {
                    $price = (float) $fp;
                }
            } catch (Exception $e) {
                // Non-fatal — keep zero
            }
        }

        // Stock
        /** @var Mage_CatalogInventory_Model_Stock_Item $stockItem */
        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
        $inStock   = $stockItem->getId() ? (bool) $stockItem->getIsInStock() : true;
        $qty       = $stockItem->getId() ? (int) $stockItem->getQty() : null;

        // Categories
        $categoryIds   = (array) $product->getCategoryIds();
        $categoryNames = $this->resolveCategoryNames($categoryIds);

        // Currency
        $currency = (string) $store->getCurrentCurrencyCode() ?: 'GBP';

        // Reviews + bestseller
        $storeId   = (int) $store->getId();
        $productId = (int) $product->getId();
        $reviews   = $this->fetchReviews($productId, $storeId);

        // is_new: true when today falls within news_from_date / news_to_date
        $isNew    = false;
        $newsFrom = $product->getData('news_from_date');
        if ($newsFrom) {
            $now      = new DateTime();
            $fromDate = new DateTime((string) $newsFrom);
            $newsTo   = $product->getData('news_to_date');
            $isNew    = $now >= $fromDate && ($newsTo === null || $now <= new DateTime((string) $newsTo));
        }

        return [
            'external_id'     => (string) $product->getId(),
            'product_type'    => (string) $product->getTypeId(),
            'sku'             => (string) $product->getSku(),
            'name'            => (string) $product->getName(),
            'description'     => strip_tags((string) ($product->getData('description') ?? '')),
            'price'           => $price > 0 ? $price : null,
            'currency'        => $currency,
            'in_stock'        => $inStock,
            'stock_qty'       => $qty,
            'url'             => (string) $product->getProductUrl(),
            'image_url'       => $this->getImageUrl($product),
            'category_path'   => implode(' > ', $categoryIds),
            // Lowercase trimmed category NAMES for per-row filtering in the API
            // (category_slugs TEXT[] column, migration 0047). Without this the API
            // falls back to split_part on category_path which can't parse ID-joined paths.
            'category_names'  => $categoryNames,
            'attributes'      => $this->extractAttributes($product),
            'variants'        => $this->extractVariants($product),
            'avg_rating'      => $reviews['avg_rating'],
            'review_count'    => $reviews['review_count'],
            'review_snippets' => $reviews['review_snippets'],
            'is_new'          => $isNew,
            'is_featured'     => (bool) $product->getData('is_featured'),
            'bestseller_rank' => $this->fetchBestsellerRank($productId, $storeId),
            // sale_price + is_on_sale: special_price native Magento sale columns;
            // is_on_sale is TRUE when special_price set AND today within the window.
            'sale_price'      => $this->fetchSalePrice($product),
            'is_on_sale'      => $this->isOnSale($product),
        ];
    }

    private function fetchSalePrice(Mage_Catalog_Model_Product $product): ?float
    {
        if (!$this->isOnSale($product)) {
            return null;
        }
        $sale = $product->getData('special_price');
        return ($sale !== null && (float) $sale > 0) ? (float) $sale : null;
    }

    private function isOnSale(Mage_Catalog_Model_Product $product): bool
    {
        $sale = $product->getData('special_price');
        if ($sale === null || (float) $sale <= 0) {
            return false;
        }
        $now    = new DateTime();
        $fromOk = true;
        $toOk   = true;
        if ($from = $product->getData('special_from_date')) {
            $fromOk = $now >= new DateTime((string) $from);
        }
        if ($to = $product->getData('special_to_date')) {
            $toOk = $now <= new DateTime((string) $to);
        }
        return $fromOk && $toOk;
    }

    /**
     * Resolve a list of category IDs to lowercase trimmed names, deduped + sorted.
     * Skips root category (ID ≤ 1). Caches per serializer instance.
     *
     * @param  int[]|string[] $categoryIds
     * @return string[]
     */
    private function resolveCategoryNames(array $categoryIds): array
    {
        $names = [];
        foreach ($categoryIds as $id) {
            $intId = (int) $id;
            if ($intId <= 1) {
                continue;
            }
            if (!array_key_exists($intId, $this->categoryNameCache)) {
                try {
                    /** @var Mage_Catalog_Model_Category $category */
                    $category = Mage::getModel('catalog/category')->load($intId);
                    $rawName  = (string) $category->getName();
                    $clean    = trim(mb_strtolower($rawName));
                    $this->categoryNameCache[$intId] = $clean !== '' ? $clean : null;
                } catch (Exception $e) {
                    $this->categoryNameCache[$intId] = null;
                }
            }
            $name = $this->categoryNameCache[$intId];
            if ($name !== null) {
                $names[$name] = true;
            }
        }
        $list = array_keys($names);
        sort($list);
        return $list;
    }

    private function getImageUrl(Mage_Catalog_Model_Product $product): ?string
    {
        try {
            $url = (string) Mage::helper('catalog/image')->init($product, 'image');
            return $url !== '' ? $url : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Fetch avg_rating, review_count, and up to 3 approved review snippets
     * via raw DB queries (same approach as M2 — no Magento review API overhead).
     *
     * @return array{avg_rating: float|null, review_count: int, review_snippets: string[]}
     */
    private function fetchReviews(int $productId, int $storeId): array
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        /** @var Varien_Db_Adapter_Pdo_Mysql $conn */
        $conn = $resource->getConnection('core_read');

        $avgRating   = null;
        $reviewCount = 0;

        try {
            $ratingTable = $resource->getTableName('rating_option_vote_aggregated');
            $ratingRow   = $conn->fetchRow(
                $conn->select()
                    ->from($ratingTable, [
                        'avg_percent' => new Zend_Db_Expr('AVG(percent)'),
                        'total_count' => new Zend_Db_Expr('MAX(vote_count)'),
                    ])
                    ->where('entity_pk_value = ?', $productId)
                    ->where('store_id = ?', $storeId)
            );
            if ($ratingRow && $ratingRow['total_count'] > 0) {
                $avgRating   = round((float) $ratingRow['avg_percent'] / 20, 2);
                $reviewCount = (int) $ratingRow['total_count'];
            }
        } catch (Exception $e) {
            // Non-fatal — reviews absent or table missing
        }

        $snippets = [];
        try {
            $reviewDetailTable = $resource->getTableName('review_detail');
            $reviewTable       = $resource->getTableName('review');
            $snippetRows = $conn->fetchAll(
                $conn->select()
                    ->from(['rd' => $reviewDetailTable], ['detail'])
                    ->join(['r' => $reviewTable], 'r.review_id = rd.review_id', [])
                    ->where('r.entity_pk_value = ?', $productId)
                    ->where('r.status_id = ?', 2) // 2 = approved
                    ->where('rd.store_id = ?', $storeId)
                    ->order('r.review_id DESC')
                    ->limit(3)
            );
            foreach ($snippetRows as $row) {
                $s = mb_substr(trim((string) $row['detail']), 0, 300);
                if ($s !== '') {
                    $snippets[] = $s;
                }
            }
        } catch (Exception $e) {
            // Non-fatal
        }

        return [
            'avg_rating'      => $avgRating,
            'review_count'    => $reviewCount,
            'review_snippets' => $snippets,
        ];
    }

    /**
     * Return the most recent monthly bestseller rank for this product, or null.
     * Same table as M2 (sales_bestsellers_aggregated_monthly, populated by reports cron).
     */
    private function fetchBestsellerRank(int $productId, int $storeId): ?int
    {
        $resource = Mage::getSingleton('core/resource');
        $conn     = $resource->getConnection('core_read');
        try {
            $table = $resource->getTableName('sales_bestsellers_aggregated_monthly');
            $row   = $conn->fetchRow(
                $conn->select()
                    ->from($table, ['rating_pos'])
                    ->where('product_id = ?', $productId)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->order('period DESC')
                    ->limit(1)
            );
            return ($row && isset($row['rating_pos']) && $row['rating_pos'] > 0)
                ? (int) $row['rating_pos']
                : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Per-instance cache of swatch data keyed by option_id.
     * Null means "no swatch row exists for this option_id" (already looked up).
     * Array means {type: int, value: string} (already resolved).
     * Key absent means "not yet looked up".
     *
     * @var array<int, array{type: int, value: string}|null>
     */
    private array $swatchCache = [];

    /**
     * Load swatch data for a single option_id from eav_attribute_option_swatch.
     * Returns {type: 0|1|2, value: string} or null if no swatch row exists.
     * Caches every lookup so repeated calls for the same option_id cost nothing.
     * Wrapped in try/catch — swatch failure must never abort serialization.
     *
     * Type derivation (OpenMage has no numeric type column, unlike M2):
     *   filename non-empty               → type 2 (image swatch)
     *   value starts with '#'            → type 1 (colour swatch)
     *   value non-empty                  → type 0 (text swatch)
     *   otherwise                        → null  (no usable swatch)
     *
     * @return array{type: int, value: string}|null
     */
    private function resolveSwatchForOption(int $optionId): ?array
    {
        if (array_key_exists($optionId, $this->swatchCache)) {
            return $this->swatchCache[$optionId];
        }

        try {
            /** @var Mage_Core_Model_Abstract $swatch */
            $swatch = Mage::getModel('eav/entity_attribute_option_swatch')->load($optionId, 'option_id');

            if (!$swatch->getId()) {
                // No row in eav_attribute_option_swatch for this option_id
                $this->swatchCache[$optionId] = null;
                return null;
            }

            $fname = (string) $swatch->getFilename();
            $val   = (string) $swatch->getValue();

            if ($fname !== '') {
                $resolved = ['type' => 2, 'value' => $fname];
            } elseif ($val !== '' && strpos($val, '#') === 0) {
                $resolved = ['type' => 1, 'value' => $val];
            } elseif ($val !== '') {
                $resolved = ['type' => 0, 'value' => $val];
            } else {
                $resolved = null;
            }

            $this->swatchCache[$optionId] = $resolved;
            return $resolved;
        } catch (Exception $e) {
            // Best-effort — swatch table absent or corrupt row; treat as no swatch
            $this->swatchCache[$optionId] = null;
            return null;
        }
    }

    /**
     * For configurable products: extract child SKU variant options.
     * M1 type instance API differs from M2: getUsedProducts(null, $product).
     *
     * Swatch data is attached per-variant under $variant['swatches'][$attrCode]
     * as {type: int, value: string} where type 0=text, 1=colour, 2=image —
     * matching the M2 ProductSerializer output shape exactly.
     * The 'swatches' key is omitted entirely when no attribute has a swatch
     * (matches M2 behaviour; no empty array emitted).
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractVariants(Mage_Catalog_Model_Product $product): array
    {
        if ($product->getTypeId() !== 'configurable') {
            return [];
        }

        try {
            /** @var Mage_Catalog_Model_Product_Type_Configurable $typeInstance */
            $typeInstance = $product->getTypeInstance(true);
            $children     = $typeInstance->getUsedProducts(null, $product);

            if (empty($children)) {
                return [];
            }

            // Configurable attribute code → attribute ID map
            $configurableAttrs = [];
            foreach ($typeInstance->getConfigurableAttributes($product) as $attr) {
                $productAttr = $attr->getProductAttribute();
                if ($productAttr) {
                    $code = $productAttr->getAttributeCode();
                    if ($code) {
                        $configurableAttrs[$code] = (int) $productAttr->getId();
                    }
                }
            }

            $variants = [];
            foreach ($children as $child) {
                /** @var Mage_Catalog_Model_Product $child */
                $childStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);

                $variant = [
                    'sku'      => (string) $child->getSku(),
                    'in_stock' => $childStock->getId() ? (bool) $childStock->getIsInStock() : true,
                ];

                $childPrice = (float) $child->getPrice();
                if ($childPrice > 0) {
                    $variant['price'] = $childPrice;
                }

                $options         = [];
                $superAttributes = [];
                foreach ($configurableAttrs as $attrCode => $attrId) {
                    $label = $child->getAttributeText($attrCode);
                    if ($label === false || $label === null) {
                        $label = $child->getData($attrCode);
                    }
                    if ($label === null || $label === '' || $label === false) {
                        continue;
                    }
                    $strLabel           = is_array($label) ? implode(', ', $label) : (string) $label;
                    $options[$attrCode] = $strLabel;

                    $rawOptionId = $child->getData($attrCode);
                    if ($rawOptionId !== null && $rawOptionId !== '' && is_numeric($rawOptionId)) {
                        $superAttributes[(string) $attrId] = (string) $rawOptionId;

                        // Attach swatch data if available for this variant's option.
                        // resolveSwatchForOption() is cached — safe to call per-variant.
                        $swatch = $this->resolveSwatchForOption((int) $rawOptionId);
                        if ($swatch !== null) {
                            if (!isset($variant['swatches'])) {
                                $variant['swatches'] = [];
                            }
                            $variant['swatches'][$attrCode] = $swatch;
                        }
                    }
                }
                if (!empty($options)) {
                    $variant['options'] = $options;
                }
                if (!empty($superAttributes)) {
                    $variant['super_attributes'] = $superAttributes;
                }

                $variants[] = $variant;
            }

            return $variants;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Extract searchable/filterable attributes as a flat code → label map.
     * In M1 we iterate $product->getAttributes() (all EAV attrs on this entity type)
     * rather than M2's getCustomAttributes() (only explicitly-set custom attrs).
     *
     * @return array<string, string>
     */
    private function extractAttributes(Mage_Catalog_Model_Product $product): array
    {
        $attrs = [];
        try {
            foreach ($product->getAttributes() as $attribute) {
                /** @var Mage_Eav_Model_Entity_Attribute $attribute */
                if (!$attribute->getIsSearchable() && !$attribute->getIsFilterable()) {
                    continue;
                }
                $attrCode = (string) $attribute->getAttributeCode();
                $value    = $product->getAttributeText($attrCode);
                if ($value === false || $value === null) {
                    $value = $product->getData($attrCode);
                }
                if ($value === null || $value === '' || $value === false) {
                    continue;
                }
                if (is_array($value)) {
                    $value = implode(', ', array_filter(
                        $value,
                        fn($v) => $v !== '' && $v !== false
                    ));
                }
                if ((string) $value !== '') {
                    $attrs[$attrCode] = (string) $value;
                }
            }
        } catch (Exception $e) {
            // Best-effort — attribute extraction failure must not abort the sync
        }
        return $attrs;
    }
}
