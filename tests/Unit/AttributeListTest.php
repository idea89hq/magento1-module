<?php
/**
 * The schema-2 attribute list (1.1.0): labels, types, option labels and
 * flags. Invented attributes.
 */

use PHPUnit\Framework\TestCase;

class AttributeListTest extends TestCase
{
    private function attribute(string $code, string $input, array $flags, string $label): Idea89_Test_DataObject
    {
        return new Idea89_Test_DataObject([
            'attribute_code' => $code, 'frontend_input' => $input, 'store_label' => $label, 'frontend_label' => 'Admin ' . $code,
            'is_visible_on_front' => $flags['visible'] ?? 0, 'is_searchable' => $flags['searchable'] ?? 0,
            'is_filterable' => $flags['filterable'] ?? 0, 'is_filterable_in_search' => 0,
        ]);
    }

    public function testListCarriesLabelsOptionLabelsAndFlags(): void
    {
        $product = new class([
            'material' => '57', 'features' => '3,4', 'care' => '<b>Wipe</b>', 'vegan' => '1', 'secret' => 'x', 'url_key' => 'y',
        ]) extends Idea89_Test_DataObject {
            public array $attrs = [];
            public function getAttributes() { return $this->attrs; }
            public function getAttributeText($code) { return ['material' => 'Oak', 'features' => ['Waterproof', 'Foldable']][$code] ?? false; }
        };
        $product->attrs = [
            $this->attribute('material', 'select', ['filterable' => 1], 'Material'),
            $this->attribute('features', 'multiselect', ['visible' => 1], 'Features'),
            $this->attribute('care', 'textarea', ['visible' => 1], ''),
            $this->attribute('vegan', 'boolean', ['searchable' => 1], 'Vegan'),
            $this->attribute('secret', 'text', [], 'Secret'),
            $this->attribute('url_key', 'text', ['searchable' => 1], 'URL'),
        ];
        $s = new Idea89_Assistant_Model_Sync_ProductSerializer();
        $m = new ReflectionMethod($s, 'extractAttributeList');
        $m->setAccessible(true);
        $list = array_column($m->invoke($s, $product), null, 'code');

        $this->assertSame(['material', 'features', 'care', 'vegan'], array_keys($list));
        $this->assertSame(['Oak', 'select', true, false, false], [$list['material']['value'], $list['material']['type'], $list['material']['filterable'], $list['material']['searchable'], $list['material']['visible']]);
        $this->assertSame(['Waterproof', 'Foldable'], $list['features']['value']);
        $this->assertSame('multiselect', $list['features']['type']);
        $this->assertSame(['Admin care', 'Wipe'], [$list['care']['label'], $list['care']['value']]);
        $this->assertSame(['Yes', 'boolean'], [$list['vegan']['value'], $list['vegan']['type']]);
    }
}
