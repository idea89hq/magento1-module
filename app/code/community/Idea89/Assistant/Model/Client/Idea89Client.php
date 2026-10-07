<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * HTTP client wrapper for the IDEA89 SaaS API.
 *
 * M2 → M1 substitutions:
 *   Magento\Framework\HTTP\Client\Curl  → Varien_Http_Client (Zend HTTP)
 *   Constructor DI removed              → new Varien_Http_Client($url) per-request
 *   $this->logger->warning(...)         → Mage::log(..., Zend_Log::ERR, 'idea89.log')
 *   ScopeConfigInterface::getValue()    → Mage::app()->getStore()->getBaseUrl()
 *   JSON_THROW_ON_ERROR in json_encode  → removed (caught by outer try/catch)
 *
 * Endpoint paths, header names (X-IDEA89-Key, X-IDEA89-Domain, Content-Type),
 * timeouts (15 s general / 60 s batch), and testConnection response shape are
 * identical to the M2 client so the SaaS API sees no difference in callers.
 *
 * Factory alias: idea89_assistant/client_idea89Client
 */
class Idea89_Assistant_Model_Client_Idea89Client
{
    const TIMEOUT       = 15;
    const BATCH_TIMEOUT = 60;

    /** API error codes that mean "the catalogue sync key is the problem". */
    const SYNC_KEY_ERRORS = ['sync_key_not_set', 'sync_key_required', 'invalid_sync_key'];

    /**
     * The API's merchant-facing message from the last catalogue write refused
     * over the sync key in this request, or null. Static because Mage::getModel()
     * hands every caller a fresh client: the syncers and the Sync Now action
     * each have their own instance but need to see the same refusal. The write
     * methods still only return false; observers call them during admin saves.
     *
     * @var string|null
     */
    private static $syncKeyRejection = null;

    /**
     * @return string|null
     */
    public static function getSyncKeyRejection()
    {
        return self::$syncKeyRejection;
    }

    /**
     * The API's message when a response is a sync-key refusal, else null.
     *
     * @return string|null
     */
    public static function syncKeyErrorMessage(int $status, string $body)
    {
        if ($status !== 401) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !in_array(isset($data['error']) ? $data['error'] : null, self::SYNC_KEY_ERRORS, true)) {
            return null;
        }
        $message = isset($data['message']) && is_string($data['message']) ? trim($data['message']) : '';
        return $message !== ''
            ? $message
            : 'Catalogue sync was refused (' . $data['error'] . '). Check the Catalogue sync key field.';
    }

    private function noteSyncKeyRejection(int $status, string $body): void
    {
        $message = self::syncKeyErrorMessage($status, $body);
        if ($message !== null) {
            self::$syncKeyRejection = $message;
        }
    }

    /**
     * Returns the store's hostname to attach as X-IDEA89-Domain.
     * Falls back to empty string when no base URL is configured.
     */
    private function domainHeader(): string
    {
        $baseUrl = (string) Mage::app()->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB, true);
        if ($baseUrl === '') {
            $baseUrl = (string) Mage::app()->getStore()->getBaseUrl();
        }
        $host = parse_url($baseUrl, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    /**
     * Returns the store's base path to attach as X-IDEA89-Site-Path.
     * '/' for a root install, '/shop' for a subfolder.
     *
     * DO NOT change the root value back to the empty string. libcurl treats a
     * header written as "Name: " (colon then only whitespace) as an instruction
     * to REMOVE that header, so an empty value never reaches the API at all —
     * and the API reads an absent header as "this plugin is too old to report a
     * path" and lets the request through. A root store would then be able to
     * sync into a subfolder store's catalog with the wrong API key, which is
     * the exact mix-up this header exists to stop. '/' survives the wire, and
     * the API's normalizeSitePath('/') returns '' — so it round-trips to the
     * same value a root store is registered with, and still matches.
     */
    private function sitePath(): string
    {
        $baseUrl = (string) Mage::app()->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB, true);
        if ($baseUrl === '') {
            $baseUrl = (string) Mage::app()->getStore()->getBaseUrl();
        }
        $path = parse_url($baseUrl, PHP_URL_PATH);
        if (!is_string($path)) {
            return '/';
        }
        $path = strtolower(rtrim($path, '/'));
        if ($path === '') {
            return '/';
        }
        return substr($path, 0, 1) === '/' ? $path : '/' . $path;
    }

    /**
     * Builds a configured Varien_Http_Client ready for a JSON POST. Every
     * caller is a /v1/catalog/* write, so the optional catalog sync key is
     * attached here when the merchant has set one, and left off otherwise.
     */
    private function buildPostClient(string $url, string $apiKey, int $timeout): Varien_Http_Client
    {
        $client = new Varien_Http_Client($url);
        $client->setConfig(['timeout' => $timeout]);
        $headers = [
            'Content-Type'       => 'application/json',
            'X-IDEA89-Key'       => $apiKey,
            'X-IDEA89-Domain'    => $this->domainHeader(),
            'X-IDEA89-Site-Path' => $this->sitePath(),
        ];
        $syncKey = Mage::getModel('idea89_assistant/config')->getSyncKey();
        if ($syncKey !== '') {
            $headers['X-IDEA89-Sync-Key'] = $syncKey;
        }
        $client->setHeaders($headers);
        return $client;
    }

    /**
     * POST a batch of serialized products to the catalog upsert endpoint.
     * Returns true on HTTP 200/201.
     */
    public function upsertProducts(array $products, string $apiKey, string $apiUrl): bool
    {
        if (empty($products)) {
            return true;
        }

        $url  = rtrim($apiUrl, '/') . '/v1/catalog/upsert';
        // Schema 2 (module 1.1.0): an IDEA89 API that predates it ignores the
        // version, the platform and the attribute list.
        $body = json_encode(['schema_version' => 2, 'platform' => 'magento1', 'products' => $products]);

        try {
            $client = $this->buildPostClient($url, $apiKey, self::BATCH_TIMEOUT);
            $client->setRawData($body, 'application/json');
            $resp   = $client->request(Varien_Http_Client::POST);
            if (!$resp->isSuccessful()) {
                Mage::log(
                    'IDEA89 upsertProducts failed: HTTP ' . $resp->getStatus() . ' ' . substr((string) $resp->getBody(), 0, 500),
                    Zend_Log::ERR,
                    'idea89.log'
                );
                $this->noteSyncKeyRejection((int) $resp->getStatus(), (string) $resp->getBody());
                return false;
            }
            return true;
        } catch (Exception $e) {
            Mage::log('IDEA89 upsertProducts exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
            return false;
        }
    }

    /**
     * POST content items (categories, CMS pages, store info) to /v1/catalog/content.
     * Returns true on HTTP 200/201.
     */
    public function syncContent(array $items, string $apiKey, string $apiUrl): bool
    {
        if (empty($items)) {
            return true;
        }

        $url  = rtrim($apiUrl, '/') . '/v1/catalog/content';
        $body = json_encode(['items' => $items]);

        try {
            $client = $this->buildPostClient($url, $apiKey, self::TIMEOUT);
            $client->setRawData($body, 'application/json');
            $resp   = $client->request(Varien_Http_Client::POST);
            if (!$resp->isSuccessful()) {
                Mage::log(
                    'IDEA89 syncContent failed: HTTP ' . $resp->getStatus() . ' ' . substr((string) $resp->getBody(), 0, 500),
                    Zend_Log::ERR,
                    'idea89.log'
                );
                $this->noteSyncKeyRejection((int) $resp->getStatus(), (string) $resp->getBody());
                return false;
            }
            return true;
        } catch (Exception $e) {
            Mage::log('IDEA89 syncContent exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
            return false;
        }
    }

    /**
     * One-time handover of the removed Brand Colour field to the IDEA89
     * dashboard. IDEA89 uses it only while the dashboard is still on the
     * theme's own palette, never overwriting a colour picked there. True on a
     * confirmed 2xx, whether or not it was used: either way the dashboard now
     * owns the colour.
     */
    public function seedBrandColor(string $colour, string $apiKey, string $apiUrl): bool
    {
        $url = rtrim($apiUrl, '/') . '/v1/plugin-settings';
        try {
            $client = $this->buildPostClient($url, $apiKey, self::TIMEOUT);
            $client->setRawData(json_encode(['brand_color_seed' => $colour]), 'application/json');
            $resp = $client->request(Varien_Http_Client::POST);
            if (!$resp->isSuccessful()) {
                Mage::log(
                    'IDEA89 brand colour handover failed: HTTP ' . $resp->getStatus() . ' ' . substr((string) $resp->getBody(), 0, 500),
                    Zend_Log::WARN,
                    'idea89.log'
                );
                return false;
            }
            return true;
        } catch (Exception $e) {
            Mage::log('IDEA89 brand colour handover exception: ' . $e->getMessage(), Zend_Log::WARN, 'idea89.log');
            return false;
        }
    }

    /**
     * POST store-info payload (name, currency, locale etc.) to /v1/catalog/content.
     * Delegates to syncContent — same endpoint, same shape.
     * Returns true on HTTP 200/201.
     */
    public function syncStoreInfo(array $payload, string $apiKey, string $apiUrl): bool
    {
        return $this->syncContent($payload, $apiKey, $apiUrl);
    }

    /**
     * POST promo codes (cart price rules) to /v1/catalog/promos.
     * Returns true on HTTP 200/201.
     */
    public function upsertPromos(array $promos, string $apiKey, string $apiUrl): bool
    {
        if (empty($promos)) {
            return true;
        }

        $url  = rtrim($apiUrl, '/') . '/v1/catalog/promos';
        $body = json_encode(['promos' => $promos]);

        try {
            $client = $this->buildPostClient($url, $apiKey, self::TIMEOUT);
            $client->setRawData($body, 'application/json');
            $resp   = $client->request(Varien_Http_Client::POST);
            if (!$resp->isSuccessful()) {
                Mage::log(
                    'IDEA89 upsertPromos failed: HTTP ' . $resp->getStatus() . ' ' . substr((string) $resp->getBody(), 0, 500),
                    Zend_Log::ERR,
                    'idea89.log'
                );
                $this->noteSyncKeyRejection((int) $resp->getStatus(), (string) $resp->getBody());
                return false;
            }
            return true;
        } catch (Exception $e) {
            Mage::log('IDEA89 upsertPromos exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
            return false;
        }
    }

    /**
     * Remove products from the assistant (deleted, disabled or no longer
     * visible) through /v1/catalog/delete, which every IDEA89 API version
     * accepts. At most 500 ids per request. Returns true when all succeed.
     *
     * @param string[] $externalIds
     */
    public function deleteProducts(array $externalIds, string $apiKey, string $apiUrl): bool
    {
        $externalIds = array_values(array_unique(array_filter(array_map('strval', $externalIds), function ($id) {
            return $id !== '';
        })));
        if (empty($externalIds)) {
            return true;
        }
        $ok = true;
        foreach (array_chunk($externalIds, 500) as $chunk) {
            try {
                $client = $this->buildPostClient(rtrim($apiUrl, '/') . '/v1/catalog/delete', $apiKey, self::TIMEOUT);
                $client->setRawData(json_encode(['external_ids' => $chunk]), 'application/json');
                $resp = $client->request(Varien_Http_Client::POST);
                if (!$resp->isSuccessful()) {
                    Mage::log('IDEA89 deleteProducts failed: HTTP ' . $resp->getStatus(), Zend_Log::ERR, 'idea89.log');
                    $this->noteSyncKeyRejection((int) $resp->getStatus(), (string) $resp->getBody());
                    $ok = false;
                }
            } catch (Exception $e) {
                Mage::log('IDEA89 deleteProducts exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * POST a batch of stock updates (qty + in_stock only) to the lightweight
     * /v1/catalog/stock endpoint. Does not touch embeddings or other fields.
     * Returns true on HTTP 200/201.
     *
     * Mirrors Idea89\Assistant\Model\Client\Idea89Client::upsertStock() from M2.
     */
    public function upsertStock(array $items, string $apiKey, string $apiUrl): bool
    {
        if (empty($items)) {
            return true;
        }

        $url  = rtrim($apiUrl, '/') . '/v1/catalog/stock';
        $body = json_encode(['items' => $items]);

        try {
            $client = $this->buildPostClient($url, $apiKey, self::BATCH_TIMEOUT);
            $client->setRawData($body, 'application/json');
            $resp   = $client->request(Varien_Http_Client::POST);
            if (!$resp->isSuccessful()) {
                Mage::log(
                    'IDEA89 upsertStock failed: HTTP ' . $resp->getStatus() . ' ' . substr((string) $resp->getBody(), 0, 500),
                    Zend_Log::ERR,
                    'idea89.log'
                );
                $this->noteSyncKeyRejection((int) $resp->getStatus(), (string) $resp->getBody());
                return false;
            }
            return true;
        } catch (Exception $e) {
            Mage::log('IDEA89 upsertStock exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
            return false;
        }
    }

    /**
     * Ping /health with the configured API key.
     * Returns ['ok' => true] on HTTP 200, or ['ok' => false, 'error' => '...'] otherwise.
     * Exceptions are caught and surfaced as error strings — never escape to the caller.
     *
     * Response shape is identical to M2 testConnection so dashboard logic is unchanged.
     */
    public function testConnection(string $apiKey, string $apiUrl): array
    {
        $url = rtrim($apiUrl, '/') . '/health';

        try {
            $client = new Varien_Http_Client($url);
            $client->setConfig(['timeout' => self::TIMEOUT]);
            $client->setHeaders([
                'X-IDEA89-Key'       => $apiKey,
                'X-IDEA89-Domain'    => $this->domainHeader(),
                'X-IDEA89-Site-Path' => $this->sitePath(),
            ]);
            $resp   = $client->request(Varien_Http_Client::GET);
            $status = $resp->getStatus();

            if ($status === 200) {
                return $this->verifyCatalogAccess($apiKey, $apiUrl);
            }

            Mage::log('IDEA89 testConnection: HTTP ' . $status, Zend_Log::ERR, 'idea89.log');

            if ($status === 401 || $status === 403) {
                return ['ok' => false, 'error' => 'API key rejected (HTTP ' . $status . '). Check your key.'];
            }

            return ['ok' => false, 'error' => 'API returned HTTP ' . $status . '. Check your API URL and key.'];
        } catch (Exception $e) {
            Mage::log('IDEA89 testConnection exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
            return ['ok' => false, 'error' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Ask the API whether a catalogue sync from this store would be accepted
     * (API key, store address and sync key, the same checks a real sync gets),
     * with nothing written. /health alone cannot tell, so without this a
     * missing sync key only showed up as an empty catalogue.
     */
    private function verifyCatalogAccess(string $apiKey, string $apiUrl): array
    {
        try {
            $client = $this->buildPostClient(rtrim($apiUrl, '/') . '/v1/catalog/verify', $apiKey, self::TIMEOUT);
            $client->setRawData('{}', 'application/json');
            $resp   = $client->request(Varien_Http_Client::POST);
            $status = (int) $resp->getStatus();
            // 404: an API older than this module; the health check already passed.
            if ($status === 200 || $status === 404) {
                return ['ok' => true];
            }
            $body = (string) $resp->getBody();
            Mage::log('IDEA89 catalogue access check failed: HTTP ' . $status . ' ' . substr($body, 0, 500), Zend_Log::ERR, 'idea89.log');
            $syncKeyMessage = self::syncKeyErrorMessage($status, $body);
            if ($syncKeyMessage !== null) {
                return ['ok' => false, 'error' => 'Connected, but catalogue sync will be refused: ' . $syncKeyMessage];
            }
            $data = json_decode($body, true);
            $code = is_array($data) && isset($data['error']) && is_string($data['error']) ? $data['error'] : (string) $status;
            return ['ok' => false, 'error' => 'Connected, but the API refused this store (' . $code . '). Check your API key.'];
        } catch (Exception $e) {
            Mage::log('IDEA89 catalogue access check exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
            return ['ok' => false, 'error' => 'Connected, but the catalogue check failed: ' . $e->getMessage()];
        }
    }
}
