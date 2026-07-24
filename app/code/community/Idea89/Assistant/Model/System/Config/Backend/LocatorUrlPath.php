<?php
declare(strict_types=1);
/**
 * Copyright (c) 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Save-time validator for `idea89/locator/url_path`.
 *
 * Why this exists: the locator's custom Router (Controller/Router.php) runs
 * before the URL Rewrite router. If a merchant sets the slug to "showrooms"
 * while the storefront already has a CMS page, product, category, or custom
 * module living at /showrooms, the locator would silently steal that URL.
 * We intercept the save and refuse conflicting slugs with a message that
 * names the conflicting entity.
 *
 * Checks (in order):
 *   1. Reserved core slugs (cart, checkout, customer, admin, …). Mirrors the
 *      runtime guard in LocatorConfig so the merchant gets the rejection at
 *      SAVE time, not at request time.
 *   2. Frontend router frontNames — catches any Magento module whose
 *      frontend/routers/<name>/args/frontName claims this slug.
 *   3. URL rewrite table (core_url_rewrite) — covers products, categories,
 *      and custom rewrites in one query. Entity type is inferred from
 *      category_id / product_id columns and the id_path prefix.
 *   4. CMS page identifiers (cms/page collection) — not stored in url_rewrite
 *      by default in all M1 installs, so checked separately.
 *
 * Empty value and the default 'store-finder' bypass all checks — empty
 * resolves to the default at runtime, and 'store-finder' is owned by
 * this module's own router.
 *
 * M2 -> M1 substitutions:
 *   Namespace class              -> PEAR_Case flat class name
 *   extends Value (M2 Config)   -> extends Mage_Core_Model_Config_Data
 *   DI constructor               -> static Mage:: calls
 *   LocalizedException           -> Mage::throwException()
 *   RouteConfigInterface         -> Mage::getConfig()->getNode('frontend/routers')
 *   ResourceConnection           -> Mage::getSingleton('core/resource')
 *   url_rewrite table            -> core_url_rewrite (entity_type col absent in
 *                                   M1 — type inferred from category_id/product_id)
 *   beforeSave() (public M2)     -> _beforeSave() (protected M1 hook)
 *
 * IMPORTANT: _beforeSave() signature MUST match the M1 parent exactly.
 * Do NOT add a return-type hint — the parent Mage_Core_Model_Abstract is
 * untyped; adding ": self" would fatal under strict_types.
 */
class Idea89_Assistant_Model_System_Config_Backend_LocatorUrlPath
    extends Mage_Core_Model_Config_Data
{
    /**
     * Strip to [a-z0-9_-], reject reserved slugs, check collisions.
     * Throws (via Mage::throwException) on any conflict with a
     * merchant-readable message naming the conflicting entity.
     *
     * No return type — matches parent Mage_Core_Model_Abstract::_beforeSave().
     *
     * @return $this
     * @throws Mage_Core_Exception when slug collides with a reserved or existing path
     */
    protected function _beforeSave()
    {
        parent::_beforeSave();

        $raw  = (string) $this->getValue();
        $slug = $this->normalise($raw);

        // Store the normalised slug so Magento saves the clean value, not
        // whatever the merchant typed (extra slashes, uppercase, etc.).
        $this->setValue($slug);

        // Empty or the module's own default — nothing to validate.
        // Empty -> runtime resolves to 'store-finder' automatically.
        if ($slug === '' || $slug === Idea89_Assistant_Model_LocatorConfig::DEFAULT_URL_PATH) {
            return $this;
        }

        // 1. Reserved core slugs.
        if (in_array($slug, Idea89_Assistant_Model_LocatorConfig::reservedSlugs(), true)) {
            Mage::throwException(
                Mage::helper('idea89_assistant')->__(
                    'The URL path "%s" is reserved by Magento (e.g. checkout, cart, customer). '
                    . 'Please choose another path like "find-a-shop" or "branches".',
                    $slug
                )
            );
        }

        // 2. Module frontName collision (frontend router config).
        $conflictingModule = $this->findFrontNameConflict($slug);
        if ($conflictingModule !== null) {
            Mage::throwException(
                Mage::helper('idea89_assistant')->__(
                    'The URL path "%s" is already used by the module "%s" (registered route). '
                    . 'Pick a different path or disable/remove that module first.',
                    $slug,
                    $conflictingModule
                )
            );
        }

        // 3. URL rewrite table — products, categories, custom rewrites.
        $urlRewriteConflict = $this->findUrlRewriteConflict($slug);
        if ($urlRewriteConflict !== null) {
            Mage::throwException(
                Mage::helper('idea89_assistant')->__(
                    'The URL path "%s" is already in use by a %s%s. '
                    . 'Choose a different path, or remove the existing rewrite under '
                    . 'Catalog > URL Rewrite Management.',
                    $slug,
                    $urlRewriteConflict['entity_label'],
                    $urlRewriteConflict['target_hint']
                )
            );
        }

        // 4. CMS page identifier collision.
        $cmsTitle = $this->findCmsPageConflict($slug);
        if ($cmsTitle !== null) {
            Mage::throwException(
                Mage::helper('idea89_assistant')->__(
                    'The URL path "%s" is already used by the CMS page "%s". '
                    . 'Choose a different path, or rename/delete that CMS page.',
                    $slug,
                    $cmsTitle
                )
            );
        }

        return $this;
    }

    /**
     * Normalise a raw slug to the allowed character set [a-z0-9_-].
     * Strips leading/trailing slashes and whitespace, lowercases, then
     * removes anything outside the allowed set. Same logic as
     * Idea89_Assistant_Model_LocatorConfig::getUrlPath().
     */
    private function normalise(string $raw): string
    {
        $slug = strtolower(trim($raw, "/ \t\n\r\0\x0B"));
        $slug = (string) preg_replace('/[^a-z0-9_\-]/', '', $slug);
        return $slug;
    }

    /**
     * Check all registered frontend routers. Returns the module name of
     * the first router whose frontName matches $slug, or null if no conflict.
     * Ignores our own Idea89_Assistant router so we don't flag ourselves.
     *
     * @return string|null conflicting module name, or null
     */
    private function findFrontNameConflict(string $slug): ?string
    {
        $routers = Mage::getConfig()->getNode('frontend/routers');
        if (!$routers) {
            return null;
        }

        foreach ($routers->children() as $routerName => $cfg) {
            $frontName = isset($cfg->args->frontName)
                ? (string) $cfg->args->frontName
                : '';

            if ($frontName !== $slug) {
                continue;
            }

            // Skip our own router.
            $module = isset($cfg->args->module)
                ? (string) $cfg->args->module
                : (string) $routerName;

            if ($module === 'Idea89_Assistant') {
                continue;
            }

            return $module !== '' ? $module : (string) $routerName;
        }

        return null;
    }

    /**
     * Query core_url_rewrite for $slug and $slug.html.
     * Returns an array with 'entity_label' and 'target_hint', or null.
     *
     * M1's core_url_rewrite has no entity_type column. Entity type is
     * inferred from:
     *   - product_id NOT NULL  -> "product"
     *   - category_id NOT NULL -> "category"
     *   - otherwise            -> "URL rewrite entry"
     *
     * @return array{entity_label:string,target_hint:string}|null
     */
    private function findUrlRewriteConflict(string $slug): ?array
    {
        $resource = Mage::getSingleton('core/resource');
        $conn     = $resource->getConnection('core_read');
        $table    = $resource->getTableName('core/url_rewrite');

        $row = $conn->fetchRow(
            $conn->select()
                ->from($table, ['target_path', 'product_id', 'category_id'])
                ->where('request_path IN (?)', [$slug, $slug . '.html'])
                ->limit(1)
        );

        if (!is_array($row)) {
            return null;
        }

        if (!empty($row['product_id'])) {
            $label = 'product';
        } elseif (!empty($row['category_id'])) {
            $label = 'category';
        } else {
            $label = 'URL rewrite entry';
        }

        $targetPath = (string) ($row['target_path'] ?? '');
        $hint = $targetPath !== ''
            ? sprintf(' (currently points to %s)', $targetPath)
            : '';

        return ['entity_label' => $label, 'target_hint' => $hint];
    }

    /**
     * Check the cms_page table for a page with identifier matching $slug.
     * Returns the page title if found, null otherwise.
     *
     * CMS page identifiers are not always present in the url_rewrite table
     * (depends on whether URL rewrites are generated for CMS), so we query
     * the model collection directly.
     *
     * @return string|null page title if conflict, null otherwise
     */
    private function findCmsPageConflict(string $slug): ?string
    {
        /** @var Mage_Cms_Model_Resource_Page_Collection $collection */
        $collection = Mage::getModel('cms/page')->getCollection();
        $collection->addFieldToFilter('identifier', $slug);
        $collection->setPageSize(1);

        $page = $collection->getFirstItem();
        if (!$page || !$page->getId()) {
            return null;
        }

        $title = (string) $page->getData('title');
        return $title !== '' ? $title : $slug;
    }
}
