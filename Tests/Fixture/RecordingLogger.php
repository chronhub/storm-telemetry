<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps what it receives, so a test can read the OpenTelemetry SDK's own failure
 * reports instead of letting them fall through to STDERR.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }

    /**
     * @return list<string> the messages logged at `$level`
     */
    public function messagesAt(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['message'],
            array_filter($this->records, static fn (array $record): bool => $record['level'] === $level),
        ));
    }
}
