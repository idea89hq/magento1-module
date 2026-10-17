<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * The widget script as a pinned, content-addressed URL plus its Subresource
 * Integrity hash, for stores that turn on "Load With Subresource Integrity".
 *
 * The rolling `<widget_url>/widget/v1/<api_key>.js` changes with every IDEA89
 * release and every dashboard save, so it can never carry an `integrity`
 * attribute. `<api_url>/widget/v1/<api_key>/pin.json` returns a URL whose path
 * is the SHA-384 of the bytes it serves, and the `sha384-…` value for the tag:
 * those bytes can never change, so the browser check always holds.
 *
 * Caching: the answer is kept in the Magento cache for the `max_age` the API
 * sends (clamped to 1–60 minutes); a failure is remembered for one minute so
 * an unreachable API costs one 2-second timeout a minute, not one per page.
 *
 * Failure mode: any error, or an answer that does not look exactly like a pin
 * on the configured widget host, gives null and the block renders the rolling
 * URL without `integrity`, as before. A pin must never make the widget vanish.
 *
 * Factory alias: idea89_assistant/widgetPin
 *
 * M2→M1 substitutions:
 *   Magento\Framework\HTTP\Client\Curl → Varien_Http_Client
 *   CacheInterface                     → Mage::app()->loadCache()/saveCache()
 *   SerializerInterface                → json_encode()/json_decode()
 */
class Idea89_Assistant_Model_WidgetPin
{
    const CACHE_TAG = 'IDEA89_WIDGET_PIN';

    const FAILURE_TTL = 60;
    const MIN_TTL = 60;
    const MAX_TTL = 3600;

    /** @var array|false|null request-scoped memo; false = no pin */
    private $_memo = null;

    /**
     * @return array{src: string, integrity: string}|null
     */
    public function get(): ?array
    {
        if ($this->_memo !== null) {
            return $this->_memo ?: null;
        }

        $config = $this->_config();
        $apiKey = $config->getApiKey();
        if (!$config->isWidgetIntegrityEnabled() || $apiKey === '') {
            $this->_memo = false;
            return null;
        }

        $cacheKey = 'idea89_widget_pin_' . hash('sha256', $apiKey . '|' . $config->getWidgetUrl());
        $cached = $this->_loadCache($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                $pin = self::validate($decoded, $config->getWidgetUrl());
                $this->_memo = $pin ?? false;
                return $pin;
            }
        }

        list($pin, $ttl) = $this->_fetch($apiKey, $config->getApiUrl(), $config->getWidgetUrl());
        $this->_saveCache(
            (string) json_encode($pin ?? ['none' => true]),
            $cacheKey,
            $pin === null ? self::FAILURE_TTL : $ttl
        );

        $this->_memo = $pin ?? false;
        return $pin;
    }

    /**
     * Only a URL on the configured widget host, under /widget/v1/p/, named by a
     * 96-character sha384 hex digest, with a matching-shape sha384 integrity
     * value, is ever rendered. Anything else is treated as no pin.
     *
     * @param array $data
     * @return array{src: string, integrity: string}|null
     */
    public static function validate(array $data, string $widgetUrl): ?array
    {
        $src = $data['src'] ?? null;
        $integrity = $data['integrity'] ?? null;
        if (!is_string($src) || !is_string($integrity)) {
            return null;
        }

        $prefix = rtrim($widgetUrl, '/') . '/widget/v1/p/';
        if (strpos($src, $prefix) !== 0) {
            return null;
        }
        if (preg_match('#^[a-f0-9]{96}\.js$#', substr($src, strlen($prefix))) !== 1) {
            return null;
        }
        if (preg_match('#^sha384-[A-Za-z0-9+/]{64}$#', $integrity) !== 1) {
            return null;
        }

        return ['src' => $src, 'integrity' => $integrity];
    }

    /**
     * @return array{0: array{src: string, integrity: string}|null, 1: int}
     */
    protected function _fetch(string $apiKey, string $apiUrl, string $widgetUrl): array
    {
        $apiUrl = rtrim($apiUrl, '/');
        if ($apiUrl === '') {
            return [null, self::FAILURE_TTL];
        }

        try {
            list($status, $raw) = $this->_httpGet($apiUrl . '/widget/v1/' . rawurlencode($apiKey) . '/pin.json');
        } catch (\Throwable $e) {
            return [null, self::FAILURE_TTL];
        }
        if ($status !== 200) {
            return [null, self::FAILURE_TTL];
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return [null, self::FAILURE_TTL];
        }

        $maxAge = is_int($body['max_age'] ?? null) ? $body['max_age'] : self::MIN_TTL;

        return [self::validate($body, $widgetUrl), max(self::MIN_TTL, min(self::MAX_TTL, $maxAge))];
    }

    /**
     * @return array{0: int, 1: string} status code and body
     */
    protected function _httpGet(string $url): array
    {
        $client = new Varien_Http_Client($url, ['timeout' => 2]);
        $response = $client->request('GET');

        return [(int) $response->getStatus(), (string) $response->getBody()];
    }

    /**
     * @return string|false
     */
    protected function _loadCache(string $key)
    {
        return Mage::app()->loadCache($key);
    }

    protected function _saveCache(string $data, string $key, int $ttl): void
    {
        Mage::app()->saveCache($data, $key, [self::CACHE_TAG], $ttl);
    }

    protected function _config(): Idea89_Assistant_Model_Config
    {
        return Mage::getModel('idea89_assistant/config');
    }
}
