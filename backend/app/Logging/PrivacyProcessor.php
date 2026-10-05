<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Strips context keys that could carry raw CSV content before a record reaches any sink.
 *
 * Implements FEAT-SEC-002 / NFR-OBS-001. See docs/conventions/logging-and-privacy.md.
 */
class PrivacyProcessor implements ProcessorInterface
{
    /** @var list<string> */
    public const FORBIDDEN_KEYS = ['file', 'csv', 'rows', 'row', 'cells', 'cell', 'content', 'sample', 'payload_rows'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }
}
