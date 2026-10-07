<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Block for the Store Locator standalone page.
 * Template: app/design/frontend/base/default/template/idea89/locator.phtml
 *
 * Provides all data accessors consumed by locator.phtml:
 *   getLocatorConfig() — merchant copy and SEO fields
 *   getApiBase()       — browser-facing widget script base URL
 *   getApiKey()        — API key (decrypted)
 *   getMapProvider()   — 'stadia'|'google' from RemoteCfg
 *   getMapKey()        — map provider API key (nullable)
 *   getDefaultCountryCode() — ISO country code (nullable)
 *   getBrandColor()    — hex (nullable, dashboard → null)
 *   getStorefinderLayout()  — 'fullwidth'|'boxed'
 *   getNearestResultsCount() — int
 *   getLocations()     — array from /widget/v1/locations
 *   renderStoreJsonLd() — schema.org Store JSON-LD for one location
 *
 * Factory alias: idea89_assistant/locator (Block factory)
 *
 * M2→M1 substitutions:
 *   Constructor injection → Mage::getModel() / Mage::app()
 *   $this->_storeManager  → Mage::app()->getStore()
 *   Curl HTTP client      → Varien_Http_Client
 *   str_contains()        → strpos() !== false  (PHP 7.4 compat)
 *   getCacheKeyInfo()     → getCacheKey() / getCacheLifetime()
 */
class Idea89_Assistant_Block_Locator extends Mage_Core_Block_Template
{
    /**
     * No return-type annotation — must be parent-signature-compatible.
     */
    protected function _construct()
    {
        parent::_construct();
    }

    public function getLocatorConfig(): Idea89_Assistant_Model_LocatorConfig
    {
        return Mage::getModel('idea89_assistant/locatorConfig');
    }

    private function getConfig(): Idea89_Assistant_Model_Config
    {
        return Mage::getModel('idea89_assistant/config');
    }

    private function getRemoteCfg(): Idea89_Assistant_Model_RemoteCfg
    {
        return Mage::getModel('idea89_assistant/remoteCfg');
    }

    private function getMapCfg(): array
    {
        return $this->getRemoteCfg()->get();
    }

    public function isLocatorPlanEnabled(): bool
    {
        return $this->getRemoteCfg()->isLocatorPlanEnabled();
    }

    public function getApiBase(): string
    {
        $config = $this->getConfig();
        return rtrim($config->getWidgetUrl() ?: $config->getApiUrl() ?: '', '/');
    }

    public function getApiKey(): string
    {
        return (string) $this->getConfig()->getApiKey();
    }

    public function getMapProvider(): string
    {
        return $this->getMapCfg()['provider'];
    }

    public function getMapKey(): ?string
    {
        return $this->getMapCfg()['key'];
    }

    public function getDefaultCountryCode(): ?string
    {
        return $this->getMapCfg()['country'];
    }

    /**
     * The dashboard's brand colour, or null for the template's default. The
     * colour is set in the IDEA89 dashboard only; the module's own Brand
     * Colour field was removed.
     */
    public function getBrandColor(): ?string
    {
        return $this->getMapCfg()['brandColor'];
    }

    /**
     * Layout fallback: Magento Locator config → dashboard cfg → 'fullwidth'.
     * Empty string means "defer to dashboard" — checked in getLayout().
     */
    public function getStorefinderLayout(): string
    {
        $override = $this->getLocatorConfig()->getLayout();
        if ($override !== '') {
            return $override;
        }
        return $this->getMapCfg()['storefinderLayout'];
    }

    public function getNearestResultsCount(): int
    {
        return (int) $this->getMapCfg()['count'];
    }

    /**
     * Server-side fetch of all active locations for JSON-LD and coverage stats.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLocations(): array
    {
        $config = $this->getConfig();
        $apiUrl = rtrim($config->getApiUrl() ?: '', '/');
        if ($apiUrl === '') {
            return [];
        }
        $url = $apiUrl . '/widget/v1/locations';
        try {
            $parsed = parse_url((string) Mage::app()->getStore()->getBaseUrl());
            $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

            $client = new Varien_Http_Client($url, ['timeout' => 5]);
            $client->setHeaders('X-IDEA89-Key', $this->getApiKey());
            $client->setHeaders('Origin', $origin);
            $response = $client->request('GET');
            $body = $response->getBody();
            $data = json_decode($body, true);
            if (!is_array($data) || !isset($data['locations']) || !is_array($data['locations'])) {
                return [];
            }
            return $data['locations'];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Build a schema.org Store JSON-LD blob for one location.
     *
     * @param array<string, mixed> $loc
     */
    public function renderStoreJsonLd(array $loc): string
    {
        $addr  = is_array($loc['address'] ?? null) ? $loc['address'] : [];
        $geo   = is_array($loc['geo'] ?? null) ? $loc['geo'] : [];
        $hours = [];

        if (isset($loc['hours']['regular']) && is_array($loc['hours']['regular'])) {
            $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            foreach ($loc['hours']['regular'] as $dayIdx => $range) {
                // PHP 7.4-safe replacement for str_contains()
                if (!is_string($range) || strpos($range, '–') === false) {
                    continue;
                }
                $parts     = explode('–', $range, 2);
                $open      = $parts[0];
                $close     = $parts[1];
                $dayIdxInt = (int) $dayIdx;
                if ($dayIdxInt < 0 || $dayIdxInt > 6) {
                    continue;
                }
                $hours[] = [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => $dayNames[$dayIdxInt],
                    'opens'     => $open,
                    'closes'    => $close,
                ];
            }
        }

        $baseUrl = Mage::app()->getStore()->getBaseUrl();
        $payload = [
            '@context' => 'https://schema.org',
            '@type'    => 'Store',
            '@id'      => $baseUrl . 'store-finder#' . rawurlencode((string) ($loc['external_id'] ?? '')),
            'name'     => $loc['name'] ?? '',
            'address'  => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => trim(
                    ((string) ($addr['line_1'] ?? '')) . ' ' . ((string) ($addr['line_2'] ?? ''))
                ),
                'addressLocality' => $addr['city'] ?? '',
                'postalCode'      => $addr['postcode'] ?? '',
                'addressCountry'  => $addr['country_code'] ?? '',
            ],
            'geo'      => [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $geo['lat'] ?? null,
                'longitude' => $geo['lng'] ?? null,
            ],
            'telephone'                 => $loc['phone'] ?? null,
            'url'                       => $loc['url'] ?? null,
            'openingHoursSpecification' => $hours,
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** 15-minute block cache lifetime (matches M2 design doc §7). */
    public function getCacheLifetime(): int
    {
        return 900;
    }

    /** Unique cache key — busted when API key, map provider, or locator content changes. */
    public function getCacheKey(): string
    {
        return 'IDEA89_LOCATOR_' . md5(implode('_', [
            $this->getApiKey(),
            $this->getMapProvider(),
            $this->getLocatorConfig()->getContentVersion(),
        ]));
    }
}
