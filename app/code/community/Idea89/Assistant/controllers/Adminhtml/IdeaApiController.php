<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Admin AJAX controller for IDEA89 API actions.
 *
 * Routes (wired via config.xml <admin><routers> injection):
 *   POST adminhtml/ideaApi/test  → testAction()   (Task 4)
 *   POST adminhtml/ideaApi/sync  → syncAction()   (Task 5)
 *
 * URL resolves because config.xml registers Idea89_Assistant_Adminhtml
 * as a module prefix prepended before Mage_Adminhtml, so OpenMage
 * finds controllers/Adminhtml/IdeaApiController.php for the
 * "ideaApi" controller segment.
 *
 * M2 → M1 substitutions:
 *   ResultJsonFactory         → setHeader + setBody directly on getResponse()
 *   RequestInterface::isPost  → getRequest()->isPost() (available on M1 Zend controller)
 *   ACL resource path stays   → system/config/idea89 (matches adminhtml.xml ACL)
 *
 * IMPORTANT: _isAllowed() returns bool without a return-type hint so it
 * stays compatible with the untyped M1 parent signature. testAction()
 * likewise has no return type to stay parent-compatible.
 */
class Idea89_Assistant_Adminhtml_IdeaApiController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Guard: only users with System > Configuration > idea89 access may call these actions.
     * Parent Mage_Adminhtml_Controller_Action::_isAllowed() is typed `: bool` in
     * OpenMage 20.x, so this override MUST declare `: bool` or PHP fatals with a
     * "Declaration must be compatible" signature error.
     */
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config/idea89');
    }

    /**
     * Emit a JSON response and stop further output.
     * Internal helper — not an action, not overriding any parent method.
     */
    protected function _json(array $data): void
    {
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody((string) json_encode($data));
    }

    /**
     * POST adminhtml/ideaApi/test
     *
     * Calls testConnection() on the API client and returns the result JSON.
     * No return-type hint — overrides parent action dispatch which is untyped.
     */
    public function testAction()
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        $apiKey = $config->getApiKey();

        if ($apiKey === '') {
            $this->_json(['ok' => false, 'error' => 'No API key configured. Save the config first.']);
            return;
        }

        $apiUrl = $config->getApiUrl();

        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');
        $result = $client->testConnection($apiKey, $apiUrl);

        $this->_json($result);
    }

    /**
     * POST adminhtml/ideaApi/sync
     *
     * Triggers a full catalog + content sync. Guarded by API key check.
     * No return-type hint — overrides parent action dispatch which is untyped.
     */
    public function syncAction()
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');

        if ($config->getApiKey() === '') {
            $this->_json(['ok' => false, 'error' => 'No API key configured. Save the config first.']);
            return;
        }

        try {
            @set_time_limit(600);

            if ($config->isSyncProducts()) {
                /** @var Idea89_Assistant_Model_Sync_CatalogSyncer $catalogSyncer */
                $catalogSyncer = Mage::getModel('idea89_assistant/sync_catalogSyncer');
                $catalogSyncer->syncAll();
            }

            // A refusal over the sync key during the product sync would refuse
            // the content too; say why instead of "completed".
            if (Idea89_Assistant_Model_Client_Idea89Client::getSyncKeyRejection() === null) {
                /** @var Idea89_Assistant_Model_Sync_ContentSyncer $contentSyncer */
                $contentSyncer = Mage::getModel('idea89_assistant/sync_contentSyncer');
                $contentSyncer->syncAll();
            }

            $rejection = Idea89_Assistant_Model_Client_Idea89Client::getSyncKeyRejection();
            if ($rejection !== null) {
                $this->_json(['ok' => false, 'error' => $rejection]);
                return;
            }

            $this->_json(['ok' => true, 'synced' => 'completed']);
        } catch (Exception $e) {
            Mage::log('IDEA89 SyncNow failed: ' . $e->getMessage(), Zend_Log::ERR, 'idea89.log', true);
            $this->_json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
