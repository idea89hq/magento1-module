<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Frontend controller for order-tracking endpoints.
 *
 * Routes (via idea89 frontName):
 *   POST idea89/orders/lookup  → lookupAction()   (guest, email + increment_id)
 *   GET  idea89/orders/recent  → recentAction()   (logged-in customer)
 *   GET  idea89/orders/detail  → detailAction()   (logged-in customer, ?increment_id=)
 *
 * M2→M1 substitutions:
 *   HttpPostActionInterface / HttpGetActionInterface → single controller extending
 *       Mage_Core_Controller_Front_Action (action methods are public, no return type)
 *   CsrfAwareActionInterface dropped — M1 frontend controllers don't enforce CSRF;
 *       the (email + increment_id) pair gates access on lookup
 *   Result\JsonFactory        → _json() helper on $this->getResponse()
 *   $request->getContent()    → $this->getRequest()->getRawBody()
 *   $request->getHeader()     → $this->getRequest()->getHeader()
 *   $request->getClientIp()   → Mage::helper('core/http')->getRemoteAddr()
 *   StoreManagerInterface     → Mage::app()->getStore()
 *   CustomerSession           → Mage::getSingleton('customer/session')
 *   OrderRepositoryInterface  → Mage::getModel('sales/order') + getCollection()
 *   PSR-3 LoggerInterface     → Mage::log()
 *   Constructor DI removed    → Mage:: factory accessors inline
 */
class Idea89_Assistant_OrdersController extends Mage_Core_Controller_Front_Action
{
    /**
     * Soft email regex — Magento allows long, internationalised local
     * parts but we only need it to weed out obviously-bad inputs before
     * hitting the DB. The DB equality check is the canonical match.
     */
    private const EMAIL_RE = '/^[^@\s]{1,128}@[^@\s]{1,256}\.[A-Za-z]{2,24}$/';

    // -------------------------------------------------------------------------
    // POST idea89/orders/lookup
    // -------------------------------------------------------------------------

    /**
     * Guest order lookup. Body: {"increment_id":"100023","email":"shopper@example.com"}.
     *
     * Auth: (email, increment_id) pair is both identifier and credential.
     * Identical 404 on: order not found, wrong store, email mismatch.
     * Rate-limit fires BEFORE body parse so enumeration attackers pay
     * on every attempt regardless of payload validity.
     */
    public function lookupAction()
    {
        // Origin guard
        if (!$this->_isSameOrigin()) {
            $this->_json(['error' => 'cross_origin_forbidden'], 403);
            return;
        }

        /** @var Idea89_Assistant_Model_OrderTrackingConfig $config */
        $config = Mage::getModel('idea89_assistant/orderTrackingConfig');
        if (!$config->isEnabled()) {
            $this->_json(['error' => 'feature_disabled'], 404);
            return;
        }

        // Rate-limit BEFORE parsing input so an enumeration attacker
        // pays the cost on every attempt regardless of how malformed
        // their payloads are.
        $ip   = (string) Mage::helper('core/http')->getRemoteAddr();
        /** @var Idea89_Assistant_Model_GuestLookupRateLimit $rateLimit */
        $rateLimit = Mage::getModel('idea89_assistant/guestLookupRateLimit');
        $gate      = $rateLimit->check($ip);
        if (!$gate['allowed']) {
            $this->getResponse()->setHeader('Retry-After', (string) $gate['retry_after'], true);
            $this->_json(['error' => 'rate_limited', 'retry_after' => $gate['retry_after']], 429);
            return;
        }

        // Body parse
        $raw  = (string) $this->getRequest()->getRawBody();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->_json(['error' => 'invalid_body'], 400);
            return;
        }

        $incrementId = isset($body['increment_id']) ? (string) $body['increment_id'] : '';
        $email       = isset($body['email']) ? (string) $body['email'] : '';

        // Coarse input validation — bouncing obvious garbage keeps logs clean.
        if ($incrementId === '' || strlen($incrementId) > 32) {
            $this->_json(['error' => 'order_not_found'], 404);
            return;
        }
        if (!preg_match(self::EMAIL_RE, $email)) {
            $this->_json(['error' => 'order_not_found'], 404);
            return;
        }

        $storeId = (int) Mage::app()->getStore()->getId();

        try {
            /** @var Mage_Sales_Model_Order $order */
            $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
        } catch (\Throwable $e) {
            $this->_json(['error' => 'lookup_failed'], 500);
            return;
        }

        // Identical 404 whether: order not found, wrong store, email mismatch —
        // never reveal that the order exists to prevent enumeration.
        if (!$order->getId()
            || (int) $order->getStoreId() !== $storeId
        ) {
            Mage::log(
                '[idea89-orders-lookup] not_found increment_id=' . $incrementId,
                Zend_Log::INFO,
                'idea89.log'
            );
            $this->_json(['error' => 'order_not_found'], 404);
            return;
        }

        $storedEmail = strtolower((string) $order->getCustomerEmail());
        if ($storedEmail !== strtolower($email)) {
            Mage::log(
                '[idea89-orders-lookup] email_mismatch increment_id=' . $incrementId,
                Zend_Log::INFO,
                'idea89.log'
            );
            $this->_json(['error' => 'order_not_found'], 404);
            return;
        }

        // Successful match — clear the rate-limit bucket so this user
        // can keep tracking other orders without bumping into the cap
        // because of earlier typos.
        $rateLimit->reset($ip);
        Mage::log(
            '[idea89-orders-lookup] match increment_id=' . $incrementId,
            Zend_Log::INFO,
            'idea89.log'
        );

        /** @var Idea89_Assistant_Model_OrderSanitizer $sanitizer */
        $sanitizer = Mage::getModel('idea89_assistant/orderSanitizer');
        $this->_json(['order' => $sanitizer->sanitize($order, true)]);
    }

    // -------------------------------------------------------------------------
    // GET idea89/orders/recent
    // -------------------------------------------------------------------------

    /**
     * Returns the logged-in customer's most recent N orders (slim shape).
     * 401 if not logged in — widget falls back to the guest form.
     *
     * Scoped by customer_id AND store_id so multi-store-view merchants
     * don't leak orders from a sibling store view. limit param clamped to
     * [HARD_MIN_RECENT, getMaxRecentOrders()].
     */
    public function recentAction()
    {
        // Origin guard
        if (!$this->_isSameOrigin()) {
            $this->_json(['error' => 'cross_origin_forbidden'], 403);
            return;
        }

        /** @var Idea89_Assistant_Model_OrderTrackingConfig $config */
        $config = Mage::getModel('idea89_assistant/orderTrackingConfig');
        if (!$config->isEnabled()) {
            $this->_json(['error' => 'feature_disabled'], 404);
            return;
        }

        /** @var Mage_Customer_Model_Session $session */
        $session = Mage::getSingleton('customer/session');
        if (!$session->isLoggedIn()) {
            $this->_json(['error' => 'not_logged_in'], 401);
            return;
        }

        $customerId = (int) $session->getCustomerId();
        $storeId    = (int) Mage::app()->getStore()->getId();

        $ceiling  = $config->getMaxRecentOrders();
        $rawLimit = $this->getRequest()->getParam('limit');
        $limit    = is_numeric($rawLimit) ? (int) $rawLimit : $ceiling;
        $limit    = max(
            Idea89_Assistant_Model_OrderTrackingConfig::HARD_MIN_RECENT,
            min($ceiling, $limit)
        );

        try {
            $collection = Mage::getModel('sales/order')->getCollection()
                ->addFieldToFilter('customer_id', $customerId)
                ->addFieldToFilter('store_id', $storeId)
                ->setOrder('created_at', 'DESC')
                ->setPageSize($limit);
        } catch (\Throwable $e) {
            $this->_json(['error' => 'lookup_failed'], 500);
            return;
        }

        /** @var Idea89_Assistant_Model_OrderSanitizer $sanitizer */
        $sanitizer = Mage::getModel('idea89_assistant/orderSanitizer');
        $orders    = [];
        foreach ($collection as $order) {
            $orders[] = $sanitizer->sanitize($order, false);
        }

        $this->_json(['orders' => $orders]);
    }

    // -------------------------------------------------------------------------
    // GET idea89/orders/detail?increment_id=NNNN
    // -------------------------------------------------------------------------

    /**
     * Returns one logged-in customer's order in detail shape (items +
     * tracking entries). Identical 404 for: not found / wrong customer /
     * wrong store — no information leaked about the order space.
     */
    public function detailAction()
    {
        // Origin guard
        if (!$this->_isSameOrigin()) {
            $this->_json(['error' => 'cross_origin_forbidden'], 403);
            return;
        }

        /** @var Idea89_Assistant_Model_OrderTrackingConfig $config */
        $config = Mage::getModel('idea89_assistant/orderTrackingConfig');
        if (!$config->isEnabled()) {
            $this->_json(['error' => 'feature_disabled'], 404);
            return;
        }

        /** @var Mage_Customer_Model_Session $session */
        $session = Mage::getSingleton('customer/session');
        if (!$session->isLoggedIn()) {
            $this->_json(['error' => 'not_logged_in'], 401);
            return;
        }

        $incrementId = (string) ($this->getRequest()->getParam('increment_id') ?? '');
        if ($incrementId === '' || strlen($incrementId) > 32) {
            $this->_json(['error' => 'order_not_found'], 404);
            return;
        }

        $customerId = (int) $session->getCustomerId();
        $storeId    = (int) Mage::app()->getStore()->getId();

        try {
            $collection = Mage::getModel('sales/order')->getCollection()
                ->addFieldToFilter('increment_id', $incrementId)
                ->addFieldToFilter('customer_id', $customerId)
                ->addFieldToFilter('store_id', $storeId)
                ->setPageSize(1);
        } catch (\Throwable $e) {
            $this->_json(['error' => 'lookup_failed'], 500);
            return;
        }

        $collection->load();
        $order = $collection->getFirstItem();
        if (!$order || !$order->getId()) {
            // Identical 404 whether the order doesn't exist OR belongs
            // to someone else OR is on the wrong store view — no enumeration.
            $this->_json(['error' => 'order_not_found'], 404);
            return;
        }

        /** @var Idea89_Assistant_Model_OrderSanitizer $sanitizer */
        $sanitizer = Mage::getModel('idea89_assistant/orderSanitizer');
        $this->_json(['order' => $sanitizer->sanitize($order, true)]);
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

    /**
     * Origin guard: compare the request's Origin header host against the
     * storefront base URL host. Missing Origin is allowed — top-level GETs
     * and server-to-server calls don't send Origin, and they have no
     * session cookie to spoof anyway.
     */
    private function _isSameOrigin(): bool
    {
        $origin = (string) ($this->getRequest()->getHeader('Origin') ?: '');
        if ($origin === '') {
            return true;
        }
        $baseUrl    = (string) Mage::app()->getStore()->getBaseUrl();
        $baseHost   = (string) parse_url($baseUrl, PHP_URL_HOST);
        $originHost = (string) parse_url($origin, PHP_URL_HOST);
        return $originHost !== '' && $originHost === $baseHost;
    }
}
