<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Frontend controller for customer identity endpoint.
 *
 * Routes (via idea89 frontName):
 *   GET idea89/customer/me → meAction()
 *
 * Pattern A privacy entry-point. The chat widget calls this same-origin
 * to discover whether the current shopper is logged in. We return the
 * minimum the widget needs to decide between the logged-in flow (fetch
 * recent orders silently) and the guest flow (show the email + order#
 * form). Notably we do NOT return the full email — only an 8-char hash,
 * so the response is useless to an attacker who somehow obtains it.
 *
 * Always returns 200 with a small JSON body; never 401, since "are you
 * logged in?" is a public question. Origin guard rejects cross-origin
 * fetches as defence in depth alongside the browser's same-origin
 * cookie policy.
 *
 *
 * M2→M1 substitutions:
 *   HttpGetActionInterface     → Mage_Core_Controller_Front_Action (action method
 *       is public with no return type — parent-compatible)
 *   Result\JsonFactory         → _json() helper on $this->getResponse()
 *   StoreManagerInterface      → Mage::app()->getStore()
 *   CustomerSession            → Mage::getSingleton('customer/session')
 *   OrderTrackingConfig DI     → Mage::getModel('idea89_assistant/orderTrackingConfig')
 *   PersonalizationConfig DI   → Mage::getModel('idea89_assistant/personalizationConfig')
 *   Identity token minting     → base64url HMAC-SHA256 (ported from M2 Me.php)
 */
class Idea89_Assistant_CustomerController extends Mage_Core_Controller_Front_Action
{
    /**
     * GET idea89/customer/me
     *
     * Resolves session state and returns a minimal JSON payload the
     * widget uses to choose the logged-in vs guest order-tracking flow.
     * Always 200 — the widget handles the non-logged-in case gracefully.
     */
    public function meAction()
    {
        // Origin guard — only same-origin calls (browser fetch with
        // credentials:'include' from the merchant's storefront). Defence
        // in depth; cookie attachment is already restricted by browser
        // policy, this just catches misconfigured downstream proxies.
        if (!$this->_isSameOrigin()) {
            $this->_json(['error' => 'cross_origin_forbidden'], 403);
            return;
        }

        /** @var Mage_Customer_Model_Session $session */
        $session    = Mage::getSingleton('customer/session');
        $isLoggedIn = $session->isLoggedIn();

        // Mint the identity token when personalization is enabled and both
        // secrets are configured. Gated only on personalization — independent
        // of order tracking, so stores with personalization ON but order
        // tracking OFF still receive a token. Token is null when
        // personalization is off or credentials are missing — widget treats
        // null as no identity.
        /** @var Idea89_Assistant_Model_PersonalizationConfig $personalization */
        $personalization = Mage::getModel('idea89_assistant/personalizationConfig');
        $secret = $personalization->getSigningSecret();
        $apiKey = $personalization->getApiKey();
        $token  = null;
        if ($personalization->isEnabled() && $secret !== '' && $apiKey !== '') {
            // Capture time() once — reuse for both iat and exp to avoid
            // a cosmetic discrepancy between the two fields.
            $now = time();
            $payload = [
                'magento_customer_id' => $isLoggedIn ? (string) $session->getCustomerId() : null,
                'customer_group_id'   => (int) $session->getCustomerGroupId(),
                'is_logged_in'        => $isLoggedIn,
                'store_ref'           => $apiKey,
                'iat'                 => $now,
                'exp'                 => $now + 3600,
            ];
            $payloadB64 = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
            $macB64     = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');
            $token      = $payloadB64 . '.' . $macB64;
        }

        /** @var Idea89_Assistant_Model_OrderTrackingConfig $config */
        $config = Mage::getModel('idea89_assistant/orderTrackingConfig');

        // Master toggle — when order tracking is disabled the widget
        // shouldn't ask for order details. We still respond 200 so the
        // widget can gracefully fall back, and include the identity token
        // so personalization remains functional even when order tracking is off.
        if (!$config->isEnabled()) {
            $this->_json([
                'logged_in'       => false,
                'feature_enabled' => false,
                'identity_token'  => $token,
            ]);
            return;
        }

        if (!$isLoggedIn) {
            $this->_json([
                'logged_in'       => false,
                'feature_enabled' => true,
                'identity_token'  => $token,
            ]);
            return;
        }

        $customer  = $session->getCustomer();
        $email     = (string) $customer->getEmail();
        $firstName = (string) $customer->getFirstname();

        $this->_json([
            'logged_in'       => true,
            'feature_enabled' => true,
            // First name is fine to return — it's already plastered all
            // over the customer's account area in plain text.
            'first_name'      => $firstName,
            // Email is hashed — used only for client-side analytics dedup,
            // never displayed. Truncate to 8 chars.
            'email_hash'      => substr(hash('sha256', strtolower($email)), 0, 8),
            'identity_token'  => $token,
        ]);
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
     * Compare the request's Origin header against the storefront base URL.
     * Missing Origin is allowed — top-level GETs and server-side calls
     * don't send Origin, and they have no session cookie to spoof anyway.
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
