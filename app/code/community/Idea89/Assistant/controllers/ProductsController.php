<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Frontend controller for live-product data endpoint.
 *
 * Routes (via idea89 frontName):
 *   POST idea89/products/live → liveAction()
 *
 * Server-to-server endpoint. The IDEA89 API backend calls this with a
 * bearer token derived from the store's signing secret to confirm live
 * price + stock for ≤25 SKUs just before rendering product cards to the
 * shopper. No customer session is involved.
 *
 * M2→M1 substitutions:
 *   HttpPostActionInterface / CsrfAwareActionInterface → Mage_Core_Controller_Front_Action
 *       (M1 frontend controllers don't enforce CSRF form keys; bearer auth gates access)
 *   Result\JsonFactory            → _json() helper on $this->getResponse()
 *   $request->getContent()        → $this->getRequest()->getRawBody()
 *   $request->getHeader()         → $this->getRequest()->getHeader()
 *   ProductRepositoryInterface    → Mage::getModel('catalog/product')->loadByAttribute()
 *   StockRegistryInterface        → Mage::getModel('cataloginventory/stock_item')->loadByProduct()
 *   PersonalizationConfig DI      → Mage::getModel('idea89_assistant/personalizationConfig')
 */
class Idea89_Assistant_ProductsController extends Mage_Core_Controller_Front_Action
{
    /**
     * POST idea89/products/live
     *
     * Body: {"skus":["SKU-A","SKU-B", ...]}  (≤25 SKUs honoured; excess ignored)
     * Auth: Authorization: Bearer <signing_secret>
     *
     * Response 200: {"products":[{sku,price,qty,in_stock,status,url}, ...]}
     * Missing or unknown SKUs are silently omitted — the caller falls back
     * to the last synced value from its own catalog cache.
     * Response 401: {"error":"unauthorized"} when bearer is wrong/absent.
     */
    public function liveAction()
    {
        /** @var Idea89_Assistant_Model_PersonalizationConfig $config */
        $config = Mage::getModel('idea89_assistant/personalizationConfig');
        $secret = $config->getSigningSecret();

        // Constant-time bearer comparison — rejects empty secret as well as
        // wrong/absent Authorization header. hash_equals prevents timing attacks.
        $auth = (string) $this->getRequest()->getHeader('Authorization');
        if ($secret === '' || !hash_equals('Bearer ' . $secret, $auth)) {
            $this->_json(['error' => 'unauthorized'], 401);
            return;
        }

        $body = json_decode((string) $this->getRequest()->getRawBody(), true);
        $skus = is_array($body['skus'] ?? null) ? array_slice($body['skus'], 0, 25) : [];

        $out = [];
        foreach ($skus as $sku) {
            try {
                /** @var Mage_Catalog_Model_Product|false $product */
                $product = Mage::getModel('catalog/product')->loadByAttribute('sku', (string) $sku);
                if ($product === false || !$product->getId()) {
                    // SKU not found — omit silently.
                    continue;
                }

                /** @var Mage_CatalogInventory_Model_Stock_Item $stock */
                $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);

                $out[] = [
                    'sku'      => $product->getSku(),
                    // NOTE: default customer-group (group 0) price;
                    // group/tier pricing not applied.
                    'price'    => (float) $product->getFinalPrice(),
                    'qty'      => (float) $stock->getQty(),
                    'in_stock' => (bool) $stock->getIsInStock(),
                    'status'   => (int) $product->getStatus(),
                    'url'      => $product->getProductUrl(),
                ];
            } catch (Exception $e) {
                // SKU missing/deleted/exception — omit; backend falls back
                // to last synced value.
            }
        }

        $this->_json(['products' => $out]);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Write a JSON response. After calling this, return immediately from
     * the action method — do NOT call loadLayout/renderLayout.
     */
    private function _json(array $data, int $code = 200): void
    {
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setHttpResponseCode($code)
            ->setBody((string) json_encode($data));
    }
}
