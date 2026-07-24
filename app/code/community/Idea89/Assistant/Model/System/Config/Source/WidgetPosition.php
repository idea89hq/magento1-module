<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Dropdown source for the `idea89/widget/position` config field.
 */
class Idea89_Assistant_Model_System_Config_Source_WidgetPosition
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'bottom-right', 'label' => 'Bottom Right'],
            ['value' => 'bottom-left',  'label' => 'Bottom Left'],
        ];
    }
}
