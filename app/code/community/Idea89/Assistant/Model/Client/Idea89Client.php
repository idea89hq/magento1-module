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
     * Builds a configured Varien_Http_Client ready for a JSON POST.
     */
    private function buildPostClient(string $url, string $apiKey, int $timeout): Varien_Http_Client
    {
        $client = new Varien_Http_Client($url);
        $client->setConfig(['timeout' => $timeout]);
        $client->setHeaders([
            'Content-Type'    => 'application/json',
            'X-IDEA89-Key'    => $apiKey,
            'X-IDEA89-Domain' => $this->domainHeader(),
        ]);
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
        $body = json_encode(['products' => $products]);

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
                return false;
            }
            return true;
        } catch (Exception $e) {
            Mage::log('IDEA89 syncContent exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
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
                return false;
            }
            return true;
        } catch (Exception $e) {
            Mage::log('IDEA89 upsertPromos exception: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log');
            return false;
        }
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
                'X-IDEA89-Key'    => $apiKey,
                'X-IDEA89-Domain' => $this->domainHeader(),
            ]);
            $resp   = $client->request(Varien_Http_Client::GET);
            $status = $resp->getStatus();

            if ($status === 200) {
                return ['ok' => true];
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
}
