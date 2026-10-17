<?php
/**
 * Load With Subresource Integrity: the pin is only ever an exact pin on the
 * configured widget host, it is cached for the time the API allows, and any
 * failure means no pin, so the block falls back to the rolling script tag.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once IDEA89_M1_DIR . 'Model/Config.php';
require_once IDEA89_M1_DIR . 'Model/WidgetPin.php';

class Idea89_Test_PinConfig extends Idea89_Assistant_Model_Config
{
    public bool $enabled = true;
    public string $apiKey = 'pk_live_1';

    public function isWidgetIntegrityEnabled(): bool
    {
        return $this->enabled;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getApiUrl(): string
    {
        return 'https://api.idea89.com';
    }

    public function getWidgetUrl(): string
    {
        return 'https://api.idea89.com';
    }
}

class Idea89_Test_WidgetPin extends Idea89_Assistant_Model_WidgetPin
{
    public Idea89_Test_PinConfig $config;
    /** @var array{0: int, 1: string}|\Throwable */
    public $answer = [500, ''];
    /** @var string[] */
    public array $requests = [];
    /** @var array<string, array{data: string, ttl: int}> */
    public array $cache = [];

    public function __construct()
    {
        $this->config = new Idea89_Test_PinConfig();
    }

    protected function _httpGet(string $url): array
    {
        $this->requests[] = $url;
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }
        return $this->answer;
    }

    protected function _loadCache(string $key)
    {
        return $this->cache[$key]['data'] ?? false;
    }

    protected function _saveCache(string $data, string $key, int $ttl): void
    {
        $this->cache[$key] = ['data' => $data, 'ttl' => $ttl];
    }

    protected function _config(): Idea89_Assistant_Model_Config
    {
        return $this->config;
    }
}

class WidgetPinTest extends TestCase
{
    private const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const PINNED = 'https://api.idea89.com/widget/v1/p/' . self::HASH . '.js';
    private const INTEGRITY = 'sha384-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private function pinAnswer(int $maxAge = 300): array
    {
        return [200, (string) json_encode(['src' => self::PINNED, 'integrity' => self::INTEGRITY, 'max_age' => $maxAge])];
    }

    public function testAValidPinIsReturnedAndCachedForTheMaxAgeTheApiSends(): void
    {
        $pin = new Idea89_Test_WidgetPin();
        $pin->answer = $this->pinAnswer(300);

        $this->assertSame(['src' => self::PINNED, 'integrity' => self::INTEGRITY], $pin->get());
        $this->assertSame(['https://api.idea89.com/widget/v1/pk_live_1/pin.json'], $pin->requests);
        $this->assertSame(300, array_values($pin->cache)[0]['ttl']);
    }

    public function testMaxAgeIsClampedToBetweenOneMinuteAndOneHour(): void
    {
        $pin = new Idea89_Test_WidgetPin();
        $pin->answer = $this->pinAnswer(86400);
        $pin->get();
        $this->assertSame(3600, array_values($pin->cache)[0]['ttl']);

        $pin = new Idea89_Test_WidgetPin();
        $pin->answer = $this->pinAnswer(1);
        $pin->get();
        $this->assertSame(60, array_values($pin->cache)[0]['ttl']);
    }

    public function testASecondRequestIsServedFromTheCacheWithoutCallingTheApi(): void
    {
        $first = new Idea89_Test_WidgetPin();
        $first->answer = $this->pinAnswer();
        $first->get();

        $second = new Idea89_Test_WidgetPin();
        $second->cache = $first->cache;
        $this->assertSame(['src' => self::PINNED, 'integrity' => self::INTEGRITY], $second->get());
        $this->assertSame([], $second->requests);
    }

    public function testOneRequestAsksTheApiOnlyOnce(): void
    {
        $pin = new Idea89_Test_WidgetPin();
        $pin->answer = $this->pinAnswer();
        $pin->get();
        $pin->cache = [];
        $pin->get();
        $this->assertCount(1, $pin->requests);
    }

    public function testTurnedOffMeansNoPinAndNoCall(): void
    {
        $pin = new Idea89_Test_WidgetPin();
        $pin->config->enabled = false;
        $pin->answer = $this->pinAnswer();

        $this->assertNull($pin->get());
        $this->assertSame([], $pin->requests);
    }

    public function testAnUnreachableApiGivesNoPinAndIsRememberedForAMinute(): void
    {
        $pin = new Idea89_Test_WidgetPin();
        $pin->answer = new \RuntimeException('timed out');

        $this->assertNull($pin->get());
        $this->assertSame(60, array_values($pin->cache)[0]['ttl']);

        $again = new Idea89_Test_WidgetPin();
        $again->cache = $pin->cache;
        $this->assertNull($again->get());
        $this->assertSame([], $again->requests);
    }

    public function testANon200AnswerGivesNoPin(): void
    {
        $pin = new Idea89_Test_WidgetPin();
        $pin->answer = [503, '{"error":"pin_unavailable"}'];
        $this->assertNull($pin->get());
    }

    public function testABodyThatIsNotJsonGivesNoPin(): void
    {
        $pin = new Idea89_Test_WidgetPin();
        $pin->answer = [200, '<html>'];
        $this->assertNull($pin->get());
    }

    #[DataProvider('notAPin')]
    public function testAnAnswerThatIsNotExactlyAPinOnTheWidgetHostIsNeverRendered(array $answer): void
    {
        $pin = new Idea89_Test_WidgetPin();
        $pin->answer = [200, (string) json_encode($answer + ['max_age' => 300])];
        $this->assertNull($pin->get());
    }

    public static function notAPin(): array
    {
        $host = 'https://api.idea89.com';
        $src = self::PINNED;
        return [
            'another host' => [['src' => 'https://evil.example/widget/v1/p/' . self::HASH . '.js',
                'integrity' => self::INTEGRITY]],
            'look-alike host' => [['src' => $host . '.evil.example/widget/v1/p/' . self::HASH . '.js',
                'integrity' => self::INTEGRITY]],
            'not a pin path' => [['src' => $host . '/widget/v1/pk_live_1.js', 'integrity' => self::INTEGRITY]],
            'short hash' => [['src' => $host . '/widget/v1/p/abc.js', 'integrity' => self::INTEGRITY]],
            'path tricks' => [['src' => $src . '?x=<script>', 'integrity' => self::INTEGRITY]],
            'sha256 integrity' => [['src' => $src, 'integrity' => 'sha256-' . str_repeat('A', 43) . '=']],
            'quote in integrity' => [['src' => $src, 'integrity' => 'sha384-"' . str_repeat('A', 63)]],
            'missing integrity' => [['src' => $src]],
        ];
    }
}
