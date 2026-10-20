<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Storm\Telemetry\History\BestEffortSagaHistorySink;
use Storm\Telemetry\History\SagaHistoryEntry;
use Storm\Telemetry\History\SagaHistorySink;
use Stringable;

final class BestEffortSagaHistorySinkTest extends TestCase
{
    #[Test]
    public function forwards_to_the_inner_sink_on_success(): void
    {
        $inner = new class() implements SagaHistorySink
        {
            public ?SagaHistoryEntry $entry = null;

            public function record(SagaHistoryEntry $entry): void
            {
                $this->entry = $entry;
            }
        };
        $entry = new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []);

        new BestEffortSagaHistorySink($inner, new NullLogger)->record($entry);

        self::assertSame($entry, $inner->entry);
    }

    #[Test]
    public function swallows_and_logs_an_inner_failure_without_throwing(): void
    {
        $inner = new class() implements SagaHistorySink
        {
            public function record(SagaHistoryEntry $entry): void
            {
                throw new RuntimeException('sink down');
            }
        };
        $logger = new class() extends AbstractLogger
        {
            public ?string $level = null;

            public ?string $message = null;

            /** @var array<string, mixed> */
            public array $context = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->level = (string) $level;
                $this->message = (string) $message;
                $this->context = $context;
            }
        };

        // must not throw: the decorator exists so that one sink's failure cannot stop a fan-out's
        // siblings
        new BestEffortSagaHistorySink($inner, $logger)
            ->record(new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []));

        self::assertSame('warning', $logger->level);
        self::assertSame('storm.telemetry.history_write_failed', $logger->message);
        // the dropped row is the accepted degradation, so this context is the trace of what was lost;
        // a warning missing the failed sink, the announcement or the cause reads as noise and gets
        // tuned out, the same silence the backstop exists to prevent
        self::assertSame($inner::class, $logger->context['sink']);
        self::assertSame('SagaStarted', $logger->context['event_type']);
        self::assertSame('sink down', $logger->context['error_message']);
        self::assertInstanceOf(RuntimeException::class, $logger->context['exception']);
    }

    #[Test]
    public function a_failing_logger_on_top_of_a_failing_sink_is_absorbed_too(): void
    {
        // the double failure: the sink fails, then the warning channel fails; the wrapped logger
        // absorbs it, and nothing reaches the caller
        $inner = new class() implements SagaHistorySink
        {
            public function record(SagaHistoryEntry $entry): void
            {
                throw new RuntimeException('sink down');
            }
        };
        $hostileLogger = new class() extends AbstractLogger
        {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        };

        new BestEffortSagaHistorySink($inner, $hostileLogger)
            ->record(new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []));

        $this->expectNotToPerformAssertions();
    }
}
