<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Resolves the merchant's custom URL slug for the store-finder page.
 *
 * The default /store-finder is registered as a standard route (frontName=
 * 'store-finder') and works without this router. When the merchant sets
 * idea89/locator/url_path to a custom slug (e.g. 'showrooms'), the standard
 * router won't recognise it, so this router claims it and forwards it to
 * the locator IndexController.
 *
 * Pattern follows Mage_Cms_Controller_Router::match() exactly:
 *   - set moduleName/controllerName/actionName on request
 *   - set REWRITE_REQUEST_PATH_ALIAS
 *   - return true (standard router dispatches on the next loop iteration)
 *
 * moduleName is set to the frontName ('store-finder') so
 * Mage_Core_Controller_Varien_Router_Standard::getModuleByFrontName()
 * can resolve it to Idea89_Assistant in the next dispatch iteration.
 *
 * Factory alias: idea89_assistant/locator_router
 */
class Idea89_Assistant_Model_Locator_Router extends Mage_Core_Controller_Varien_Router_Abstract
{
    /**
     * Match a custom store-locator URL slug and route it to IndexController.
     * No return-type annotation — must be parent-signature-compatible.
     */
    public function match(Zend_Controller_Request_Http $request)
    {
        /** @var Idea89_Assistant_Model_LocatorConfig $locatorConfig */
        $locatorConfig = Mage::getModel('idea89_assistant/locatorConfig');
        $configured = $locatorConfig->getUrlPath();

        // Default slug is handled by the standard router — nothing to do.
        if ($configured === '' || $configured === Idea89_Assistant_Model_LocatorConfig::DEFAULT_URL_PATH) {
            return false;
        }

        $path = trim($request->getPathInfo(), '/');
        if ($path !== $configured) {
            return false;
        }

        // Set moduleName to the frontName so the standard router's
        // getModuleByFrontName() lookup succeeds on the next loop iteration.
        $request->setModuleName('store-finder')
            ->setControllerName('index')
            ->setActionName('index');

        // Rewrite pathInfo to the canonical frontName so this router doesn't
        // re-match the same slug on the next dispatch iteration.
        $request->setPathInfo('/' . Idea89_Assistant_Model_LocatorConfig::DEFAULT_URL_PATH);

        $request->setAlias(
            Mage_Core_Model_Url_Rewrite::REWRITE_REQUEST_PATH_ALIAS,
            $configured
        );

        return true;
    }
}
