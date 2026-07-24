<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Typed accessor for `idea89/personalization/*` config paths.
 *
 * Both secret fields are stored encrypted by Magento's core encryption key.
 * The same decrypt pattern used in Config::getApiKey() is applied here.
 *
 * M2→M1 substitutions (no M2 source exists yet; class built to the brief spec):
 *   $scopeConfig->isSetFlag(path)  → Mage::getStoreConfigFlag(path)
 *   $scopeConfig->getValue(path)   → Mage::getStoreConfig(path)
 *   $encryptor->decrypt($v)        → Mage::helper('core')->decrypt($v)
 *   Constructor injection removed  → static Mage:: calls
 */
class Idea89_Assistant_Model_PersonalizationConfig
{
    const XML_PATH_ENABLED        = 'idea89/personalization/enabled';
    const XML_PATH_SIGNING_SECRET = 'idea89/personalization/signing_secret';
    // The identity token's store_ref is the store's main API key. There is no
    // separate personalization/api_key field, so read the general one (matches M2).
    const XML_PATH_API_KEY        = 'idea89/general/api_key';

    public function isEnabled(): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_ENABLED);
    }

    public function getSigningSecret(): string
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_SIGNING_SECRET);
        if ($value === '') {
            return '';
        }
        // M1 obscure/encrypted fields are always stored as ciphertext — always decrypt.
        return (string) Mage::helper('core')->decrypt($value);
    }

    public function getApiKey(): string
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_API_KEY);
        if ($value === '') {
            return '';
        }
        // M1 obscure/encrypted fields are always stored as ciphertext — always decrypt.
        return (string) Mage::helper('core')->decrypt($value);
    }
}
