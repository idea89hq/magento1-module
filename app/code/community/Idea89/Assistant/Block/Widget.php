<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Frontend block for the IDEA89 chat widget.
 *
 * Renders the <idea89-assistant> custom element and the loader script
 * tag when the module is enabled and an API key is configured.
 *
 * Factory alias: idea89_assistant/widget
 */
class Idea89_Assistant_Block_Widget extends Mage_Core_Block_Template
{
    /** @var Idea89_Assistant_Model_Config */
    protected $_config;

    /**
     * Cache the Config model so every getter doesn't re-instantiate it.
     * _construct() is called by the parent framework after __construct(); no
     * return type declared to remain compatible with the parent signature.
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_config = Mage::getModel('idea89_assistant/config');
    }

    public function shouldRender(): bool
    {
        return $this->_config->isEnabled() && $this->_config->getApiKey() !== '';
    }

    public function getApiKey(): string
    {
        return $this->_config->getApiKey();
    }

    public function getApiUrl(): string
    {
        return $this->_config->getApiUrl();
    }

    public function getWidgetUrl(): string
    {
        return $this->_config->getWidgetUrl();
    }

    public function getWidgetPosition(): string
    {
        return $this->_config->getWidgetPosition();
    }
}
