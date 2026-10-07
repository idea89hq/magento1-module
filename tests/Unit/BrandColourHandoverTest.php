<?php
/**
 * The removed Brand Colour field is sent to IDEA89 once per saved value, and
 * its config row is deleted only after IDEA89 confirms.
 */

use PHPUnit\Framework\TestCase;

require_once IDEA89_M1_DIR . 'Model/BrandColourHandover.php';

class BrandColourHandoverTest extends TestCase
{
    /** @var list<int> */
    private array $deleted = [];
    /** @var list<string> */
    private array $sent = [];

    private function handOver(array $rows, string $apiKey = 'k', bool $ok = true): void
    {
        Idea89_Assistant_Model_BrandColourHandover::handOver(
            $rows,
            $apiKey,
            function (string $colour) use ($ok): bool {
                $this->sent[] = $colour;
                return $ok;
            },
            function (int $id): void {
                $this->deleted[] = $id;
            }
        );
    }

    public function testSendsDefaultScopeFirstAndDeletesOnConfirm(): void
    {
        $this->handOver([
            ['config_id' => 9, 'scope' => 'stores', 'scope_id' => 1, 'value' => '#112233'],
            ['config_id' => 4, 'scope' => 'default', 'scope_id' => 0, 'value' => ' #2563EB '],
        ]);
        $this->assertSame(['#2563EB', '#112233'], $this->sent);
        $this->assertSame([4, 9], $this->deleted);
    }

    public function testKeepsTheRowWhenIdea89CannotBeReached(): void
    {
        $this->handOver([['config_id' => 4, 'scope' => 'default', 'scope_id' => 0, 'value' => '#2563eb']], 'k', false);
        $this->assertSame(['#2563eb'], $this->sent);
        $this->assertSame([], $this->deleted);
    }

    public function testWaitsWhileTheStoreIsNotConnected(): void
    {
        $this->handOver([['config_id' => 4, 'scope' => 'default', 'scope_id' => 0, 'value' => '#2563eb']], '');
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->deleted);
    }

    public function testDropsValuesThatAreNotAColourWithoutSendingThem(): void
    {
        $this->handOver([
            ['config_id' => 1, 'scope' => 'default', 'scope_id' => 0, 'value' => null],
            ['config_id' => 2, 'scope' => 'websites', 'scope_id' => 1, 'value' => 'red'],
        ]);
        $this->assertSame([], $this->sent);
        $this->assertSame([1, 2], $this->deleted);
    }
}
