<?php

namespace Tests\Unit;

use App\Logging\PrivacyProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class PrivacyProcessorTest extends TestCase
{
    private const SENTINEL = 'SENTINEL-CELL-ค่า-တန်ဖိုး-9471';

    public function test_forbidden_context_keys_are_redacted_recursively(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'upload received',
            context: [
                'analysis_id' => 'a1',
                'file' => self::SENTINEL,
                'nested' => ['rows' => [self::SENTINEL], 'row_count' => 3],
            ],
        );

        $scrubbed = (new PrivacyProcessor)($record);

        $this->assertStringNotContainsString(self::SENTINEL, json_encode($scrubbed->context, JSON_UNESCAPED_UNICODE));
        $this->assertSame('a1', $scrubbed->context['analysis_id']);
        $this->assertSame(3, $scrubbed->context['nested']['row_count']);
    }
}
