<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * One-time handover of the removed Brand Colour field.
 *
 * Factory alias: idea89_assistant/brandColourHandover (cron, hourly)
 *
 * The field (Widget Appearance > Brand Colour) was sent to the widget as
 * data-color and silently beat the colour picked in the IDEA89 dashboard. The
 * colour now lives only in the dashboard. Each saved value is sent once;
 * IDEA89 uses it only while the dashboard is still on the theme's own palette,
 * so shoppers keep seeing the colour they saw before. A config row is deleted
 * once IDEA89 has confirmed; until then it is retried every hour.
 *
 * The default scope goes first, then websites, then store views: the first
 * value used wins, and the default is what most pages showed.
 */
class Idea89_Assistant_Model_BrandColourHandover
{
    const PATH = 'idea89/widget/brand_color';

    public function run(): void
    {
        /** @var Idea89_Assistant_Model_Config $config */
        $config = Mage::getModel('idea89_assistant/config');
        $resource = Mage::getSingleton('core/resource');
        $conn = $resource->getConnection('core_write');
        $table = $resource->getTableName('core/config_data');
        $rows = $conn->fetchAll(
            $conn->select()->from($table, ['config_id', 'scope', 'scope_id', 'value'])->where('path = ?', self::PATH)
        );
        if (!$rows) {
            return;
        }
        /** @var Idea89_Assistant_Model_Client_Idea89Client $client */
        $client = Mage::getModel('idea89_assistant/client_idea89Client');
        $apiUrl = $config->getApiUrl();
        self::handOver(
            $rows,
            $config->getApiKey(),
            function (string $colour, string $apiKey) use ($client, $apiUrl): bool {
                return $client->seedBrandColor($colour, $apiKey, $apiUrl);
            },
            function (int $configId) use ($conn, $table): void {
                $conn->delete($table, ['config_id = ?' => $configId]);
            }
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows core_config_data rows
     * @param callable(string, string): bool $send
     * @param callable(int): void $delete
     */
    public static function handOver(array $rows, string $apiKey, callable $send, callable $delete): void
    {
        $order = ['default' => 0, 'websites' => 1, 'stores' => 2];
        usort($rows, static function (array $a, array $b) use ($order): int {
            return [($order[$a['scope']] ?? 3), (int) $a['scope_id']] <=> [($order[$b['scope']] ?? 3), (int) $b['scope_id']];
        });
        foreach ($rows as $row) {
            $colour = trim((string) $row['value']);
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
                // Empty or not a colour the widget could use: nothing to hand over.
                $delete((int) $row['config_id']);
                continue;
            }
            if ($apiKey === '') {
                continue; // not connected yet; try again once it is
            }
            if ($send($colour, $apiKey)) {
                $delete((int) $row['config_id']);
            }
        }
    }
}
