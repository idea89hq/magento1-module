<?php
/**
 * 1.1.0: a product that is gone, disabled or not visible is removed from the
 * assistant through the delete endpoint instead of being re-sent.
 */

use PHPUnit\Framework\TestCase;

class RemovalTest extends TestCase
{
    /** @var array<int, array> */
    private array $calls = [];

    protected function tearDown(): void
    {
        Mage::reset();
    }

    private function sync(array $product): void
    {
        $test = $this;
        Mage::$models['idea89_assistant/config'] = new Idea89_Test_DataObject(['api_key' => 'k', 'api_url' => 'https://api.test']);
        Mage::$models['catalog/product'] = new Idea89_Test_DataObject($product);
        Mage::$models['idea89_assistant/sync_productSerializer'] = new class {
            public function serialize($p) { return ['external_id' => (string) $p->getId()]; }
        };
        Mage::$models['idea89_assistant/client_idea89Client'] = new class($test) {
            public function __construct(private $t) {}
            public function deleteProducts(array $ids, string $k, string $u): bool { $this->t->record('delete', $ids); return true; }
            public function upsertProducts(array $p, string $k, string $u): bool { $this->t->record('upsert', $p); return true; }
        };
        (new Idea89_Assistant_Model_Sync_CatalogSyncer())->syncProduct(9);
    }

    public function record(string $what, array $payload): void
    {
        $this->calls[] = [$what, $payload];
    }

    public function testDisabledHiddenAndMissingProductsAreRemoved(): void
    {
        $this->sync(['id' => 9, 'status' => 2, 'visibility' => 4]);
        $this->sync(['id' => 9, 'status' => 1, 'visibility' => 1]);
        $this->sync([]);
        $this->assertSame([['delete', ['9']], ['delete', ['9']], ['delete', ['9']]], $this->calls);
    }

    public function testEnabledVisibleProductIsSynced(): void
    {
        $this->sync(['id' => 9, 'status' => 1, 'visibility' => 4]);
        $this->assertSame([['upsert', [['external_id' => '9']]]], $this->calls);
    }
}
