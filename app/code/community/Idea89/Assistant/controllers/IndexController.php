<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Front-end controller for the Store Locator page.
 *
 * Registered under the idea89_locator standard router (frontName store-finder).
 * 404s when any gate fails; renders the locator page otherwise.
 *
 * Gates (cheap → expensive):
 *   1. Assistant enabled (local config flag)
 *   2. API key present (local config)
 *   3. Locator master toggle on (local config)
 *   4. Plan includes Store Locator (one HTTP call via RemoteCfg, fail-closed)
 *
 * M2→M1 substitutions:
 *   PageFactory                    → loadLayout() + renderLayout()
 *   $page->setStatusHeader(404)    → $this->_forward('noRoute')
 *   $page->getConfig()->getTitle() → $layout->getBlock('head')->setTitle()
 */
class Idea89_Assistant_IndexController extends Mage_Core_Controller_Front_Action
{
    /**
     * No return-type annotation — must be parent-signature-compatible.
     */
    public function indexAction()
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        /** @var Idea89_Assistant_Model_LocatorConfig $locatorConfig */
        $locatorConfig = Mage::getModel('idea89_assistant/locatorConfig');
        /** @var Idea89_Assistant_Model_RemoteCfg $remoteCfg */
        $remoteCfg = Mage::getModel('idea89_assistant/remoteCfg');

        // Order: local flags first (cheap), remote plan-gate last (~2 s timeout).
        if (
            !$config->isEnabled()
            || !$config->getApiKey()
            || !$locatorConfig->isEnabled()
            || !$remoteCfg->isLocatorPlanEnabled()
        ) {
            $this->_forward('noRoute');
            return;
        }

        $this->loadLayout();

        /** @var Mage_Page_Block_Html_Head $head */
        $head = $this->getLayout()->getBlock('head');
        if ($head) {
            $head->setTitle($locatorConfig->getPageTitle());
            $head->setDescription($locatorConfig->getMetaDescription());
        }

        $this->renderLayout();
    }
}
