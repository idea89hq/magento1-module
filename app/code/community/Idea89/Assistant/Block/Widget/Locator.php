<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * CMS widget that embeds the store locator inside any CMS page or static block.
 * Renders ONLY the map + list — no page chrome (hero, help, stats).
 * Merchants insert via the WYSIWYG widget picker; the hosting CMS page owns the
 * page surround.
 *
 * Inherits all data accessors (getApiBase, getLocations, getMapProvider, etc.)
 * from Idea89_Assistant_Block_Locator. Adds a plan+enabled guard that renders
 * nothing for non-Pro tenants or when the locator master toggle is off.
 *
 * Factory alias: idea89_assistant/widget_locator (Block factory)
 * Widget id: idea89_assistant_locator (in etc/widget.xml)
 *
 * M2→M1 substitutions:
 *   BlockInterface namespace  → Mage_Widget_Block_Interface
 *   protected $_template      → class property (set before _construct runs)
 */
class Idea89_Assistant_Block_Widget_Locator extends Idea89_Assistant_Block_Locator
    implements Mage_Widget_Block_Interface
{
    /** @var string Default template for widget embed (no page chrome) */
    protected $_template = 'idea89/widget/locator-embed.phtml';

    /**
     * No return-type annotation — must be parent-signature-compatible.
     * Guard: render nothing when locator is disabled or on non-Pro plan.
     */
    protected function _toHtml()
    {
        if (!$this->getLocatorConfig()->isEnabled()) {
            return '';
        }
        if (!$this->isLocatorPlanEnabled()) {
            return '';
        }
        return parent::_toHtml();
    }
}
