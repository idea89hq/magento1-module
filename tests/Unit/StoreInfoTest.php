<?php
/**
 * store_info carries facts only. The merchant's free-text store context lives
 * in the IDEA89 dashboard; this module's own Store Context field was removed
 * because both copies reached every prompt and could contradict each other.
 */

use PHPUnit\Framework\TestCase;

require_once IDEA89_M1_DIR . 'Model/Sync/ContentSyncer.php';

class StoreInfoTest extends TestCase
{
    public function testBodyNamesTheFactsItHas(): void
    {
        $item = Idea89_Assistant_Model_Sync_ContentSyncer::storeInfoItem([
            'name' => 'OpenMage Demo', 'currency' => 'GBP', 'email' => 'shop@demo.co.uk', 'url' => 'https://demo.co.uk/',
        ]);
        $this->assertSame('store_info', $item['type']);
        $this->assertSame('store', $item['external_id']);
        $this->assertSame(
            'Store name: OpenMage Demo. Prices are shown in GBP. Contact email: shop@demo.co.uk. Website: https://demo.co.uk.',
            $item['body']
        );
    }

    public function testPlaceholderEmailAndMissingFactsAreLeftOut(): void
    {
        $item = Idea89_Assistant_Model_Sync_ContentSyncer::storeInfoItem(['email' => 'owner@example.com']);
        $this->assertSame('Store', $item['title']);
        $this->assertSame('', $item['body']);
    }
}
