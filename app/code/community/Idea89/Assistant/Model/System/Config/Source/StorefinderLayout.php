<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Dropdown source for the `idea89/locator/layout` config field.
 * Empty value (leave blank) defers to the global dashboard setting —
 * the override rule documented in Block/Locator.
 *
 * M2→M1: implements Varien_Object instead of OptionSourceInterface (no interface in M1).
 * toOptionArray() convention is identical across both versions.
 */
class Idea89_Assistant_Model_System_Config_Source_StorefinderLayout
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '',          'label' => 'Use dashboard setting'],
            ['value' => 'fullwidth', 'label' => 'Fullwidth (edge-to-edge map)'],
            ['value' => 'boxed',     'label' => 'Boxed (max-width rounded card)'],
        ];
    }
}
