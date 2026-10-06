<?php
/**
 * The swatch type the module sends must be one the IDEA89 API accepts
 * ('color', 'image', 'text'); 1.0.3 sent Magento's number (0, 1, 2), which
 * failed the whole upsert batch it was in.
 */

use PHPUnit\Framework\TestCase;

class SwatchTypeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mage::reset();
    }

    private function swatchFor(array $row): ?array
    {
        Mage::$models['eav/entity_attribute_option_swatch'] = new Idea89_Test_DataObject($row);
        $serializer = new Idea89_Assistant_Model_Sync_ProductSerializer();
        $m = new ReflectionMethod($serializer, 'resolveSwatchForOption');
        $m->setAccessible(true);
        return $m->invoke($serializer, 7);
    }

    public function testColourSwatchIsNamed(): void
    {
        $this->assertSame(['type' => 'color', 'value' => '#aa3300'], $this->swatchFor(['id' => 1, 'value' => '#aa3300', 'filename' => '']));
    }

    public function testImageAndTextSwatchesAreNamed(): void
    {
        $this->assertSame(['type' => 'image', 'value' => 'sw/oak.png'], $this->swatchFor(['id' => 1, 'value' => '', 'filename' => 'sw/oak.png']));
        $this->assertSame(['type' => 'text', 'value' => 'XL'], $this->swatchFor(['id' => 1, 'value' => 'XL', 'filename' => '']));
    }

    public function testNoSwatchRowIsNull(): void
    {
        $this->assertNull($this->swatchFor([]));
    }
}
