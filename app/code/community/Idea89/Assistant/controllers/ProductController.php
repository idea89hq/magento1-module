<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Frontend controller for the full-product mini-PDP endpoint.
 *
 * Routes (via idea89 frontName):
 *   GET idea89/product/mini?sku=<sku>  (or ?id=<entity_id>)
 *
 * Renders a real Magento product-view page (gallery, price, configurable
 * swatches, add-to-cart form + their JS) with the site chrome stripped via
 * the idea89_product_mini layout handle. The chat widget loads this in a
 * lightbox iframe; add-to-cart and swatches work exactly like the real PDP
 * because it IS the real PDP layout, just without header/footer/breadcrumbs.
 *
 * The widget checks the response for the string "idea89-mini-pdp" (set as a
 * body class by the layout handle) and throws if absent — so that class MUST
 * appear in every 200 response.
 *
 * Security: gated on module enabled + product visible in site + product
 * assigned to the current website. Same guards as the real PDP.
 *
 * M2→M1 substitutions:
 *   ProductRepositoryInterface       → Mage::getModel('catalog/product')->loadByAttribute() / getIdBySku()
 *   ProductHelper::canShow()         → $product->isVisibleInSiteVisibility()
 *   PageFactory + addHandle()        → $this->loadLayout() + manual $update->addHandle()
 *   Registry::register($k,$v,true)   → Mage::unregister() + Mage::register()
 *   ResultFactory (404)              → $this->_forward('noRoute')
 */
class Idea89_Assistant_ProductController extends Mage_Core_Controller_Front_Action
{
    /**
     * GET idea89/product/mini
     */
    public function miniAction()
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        if (!$config->isEnabled()) {
            $this->_forward('noRoute');
            return;
        }

        $sku = trim((string) $this->getRequest()->getParam('sku'));
        $id  = trim((string) $this->getRequest()->getParam('id'));

        $store   = Mage::app()->getStore();
        $storeId = (int) $store->getId();

        $product = null;

        try {
            if ($sku !== '') {
                // getIdBySku() returns false when not found; load by id for store scope.
                $productId = Mage::getResourceModel('catalog/product')->getIdBySku($sku);
                if (!$productId) {
                    $this->_forward('noRoute');
                    return;
                }
                $product = Mage::getModel('catalog/product')
                    ->setStoreId($storeId)
                    ->load((int) $productId);
            } elseif ($id !== '') {
                $product = Mage::getModel('catalog/product')
                    ->setStoreId($storeId)
                    ->load((int) $id);
            } else {
                $this->_forward('noRoute');
                return;
            }
        } catch (Exception $e) {
            $this->_forward('noRoute');
            return;
        }

        if (!$product || !$product->getId()) {
            $this->_forward('noRoute');
            return;
        }

        // Block "Not Visible Individually" child simples, disabled products, etc.
        if (!$product->isVisibleInSiteVisibility()) {
            $this->_forward('noRoute');
            return;
        }

        // Block products not assigned to the current website (multi-website installs).
        $websiteId = (int) $store->getWebsiteId();
        if (!in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true)) {
            $this->_forward('noRoute');
            return;
        }

        // Several product-view blocks read product id straight from the request
        // (review form, options block, etc.). Inject so they resolve the product
        // exactly like the real PDP route, even when loaded via ?sku=.
        $this->getRequest()->setParam('id', $product->getId());

        // Blocks read the product from the registry (same keys the PDP uses).
        if (Mage::registry('current_product')) {
            Mage::unregister('current_product');
        }
        if (Mage::registry('product')) {
            Mage::unregister('product');
        }
        Mage::register('current_product', $product);
        Mage::register('product', $product);

        // Build layout manually, mirroring what Mage_Catalog_Helper_Product_View::
        // initProductLayout() does — but without calling loadLayout() because its
        // addActionLayoutHandles() would add "idea89_product_mini" (our action name)
        // as the page handle instead of "catalog_product_view" (the real PDP XML).
        // Instead: get the update object directly, add all needed handles, then drive
        // the load→xml→blocks sequence ourselves. addActionLayoutHandles() is called
        // first so the STORE_<code> and THEME_<pkg>_<theme> handles are still added.
        $this->addActionLayoutHandles();

        /** @var Mage_Core_Model_Layout_Update $update */
        $update = $this->getLayout()->getUpdate();
        $update->addHandle('default');
        $update->addHandle('catalog_product_view');
        $update->addHandle('PRODUCT_TYPE_' . $product->getTypeId());
        $update->addHandle('PRODUCT_' . $product->getId());
        $update->addHandle('idea89_product_mini');

        $this->loadLayoutUpdates();
        $this->generateLayoutXml();
        $this->generateLayoutBlocks();

        // Guarantee the detection marker class is on the root block. The layout
        // XML <action method="addBodyClass"> is the canonical way; this is a belt
        // so the widget check never silently fails if the root block was not yet
        // instantiated when the XML action ran.
        /** @var Mage_Page_Block_Html|false $root */
        $root = $this->getLayout()->getBlock('root');
        if ($root) {
            $root->addBodyClass('idea89-mini-pdp');
        }

        Mage::dispatchEvent('catalog_controller_product_view', ['product' => $product]);

        $this->getResponse()->setHeader('Cache-Control', 'no-store', true);

        $this->renderLayout();
    }
}
