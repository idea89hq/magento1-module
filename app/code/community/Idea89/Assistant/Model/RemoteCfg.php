<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Fetches the widget boot-cfg JSON served at <apiUrl>/widget/v1/<apiKey>.js.
 * Reads locatorEnabled (Pro-tier gate) and map/brand/layout fields.
 * Fail-closed: any network or parse error returns locatorEnabled=false.
 * Result is cached for the life of the PHP request via static property.
 *
 * Factory alias: idea89_assistant/remoteCfg
 *
 * M2→M1 substitutions:
 *   Magento\Framework\HTTP\Client\Curl → Varien_Http_Client
 *   Constructor injection removed       → static Mage:: calls
 *   Private property cache             → static class-level property
 */
class Idea89_Assistant_Model_RemoteCfg extends Varien_Object
{
    /** @var array|null Shared across all instances within one PHP request */
    private static $_cachedCfg = null;

    /** @var array Safe defaults — every field present, locatorEnabled=false */
    private static $_fallback = [
        'provider'          => 'stadia',
        'key'               => null,
        'country'           => null,
        'count'             => 3,
        'brandColor'        => null,
        'storefinderLayout' => 'fullwidth',
        'locatorEnabled'    => false,
    ];

    /**
     * Return the parsed cfg from the bundle endpoint.
     * Caches result statically for the request lifetime.
     *
     * @return array{provider:string,key:string|null,country:string|null,count:int,brandColor:string|null,storefinderLayout:string,locatorEnabled:bool}
     */
    public function get(): array
    {
        if (self::$_cachedCfg !== null) {
            return self::$_cachedCfg;
        }

        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        $apiUrl = rtrim($config->getApiUrl(), '/');
        $apiKey = $config->getApiKey();

        if ($apiUrl === '' || $apiKey === '') {
            return self::$_cachedCfg = self::$_fallback;
        }

        try {
            $url    = $apiUrl . '/widget/v1/' . $apiKey . '.js';
            $client = new Varien_Http_Client($url, ['timeout' => 2]);
            $response = $client->request('GET');
            $body = $response->getBody();

            // Bundle embeds cfg as `var cfg = {...};`
            if (
                preg_match('/var cfg = (\{[\s\S]*?\});/', $body, $m)
                && is_array($cfg = json_decode($m[1], true))
            ) {
                return self::$_cachedCfg = [
                    'provider'          => is_string($cfg['mapProvider'] ?? null)
                        ? $cfg['mapProvider'] : 'stadia',
                    'key'               => is_string($cfg['mapKey'] ?? null)
                        ? $cfg['mapKey'] : null,
                    'country'           => is_string($cfg['defaultCountryCode'] ?? null)
                        ? $cfg['defaultCountryCode'] : null,
                    'count'             => is_int($cfg['nearestResultsCount'] ?? null)
                        ? $cfg['nearestResultsCount'] : 3,
                    'brandColor'        => is_string($cfg['brandColor'] ?? null)
                        ? $cfg['brandColor'] : null,
                    'storefinderLayout' => (is_string($cfg['storefinderLayout'] ?? null)
                        && in_array($cfg['storefinderLayout'], ['fullwidth', 'boxed'], true))
                        ? $cfg['storefinderLayout'] : 'fullwidth',
                    'locatorEnabled'    => is_bool($cfg['locatorEnabled'] ?? null)
                        ? $cfg['locatorEnabled'] : false,
                ];
            }
        } catch (\Throwable $e) {
            // network/timeout/TLS/Error → fall through to fail-closed fallback (fail closed on ANY error)
        }

        return self::$_cachedCfg = self::$_fallback;
    }

    /**
     * Pro-tier gate. Returns false for non-Pro plans, network failures,
     * or misconfigured tenants — failing closed is correct for a billing check.
     */
    public function isLocatorPlanEnabled(): bool
    {
        return (bool) $this->get()['locatorEnabled'];
    }
}
