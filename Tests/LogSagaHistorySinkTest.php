<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Storm\Telemetry\History\LogSagaHistorySink;
use Storm\Telemetry\History\SagaHistoryEntry;
use Stringable;

final class LogSagaHistorySinkTest extends TestCase
{
    #[Test]
    public function logs_the_entry_as_a_structured_info_record(): void
    {
        $logger = new class() extends AbstractLogger
        {
            public ?string $level = null;

            public string $message = '';

            /** @var array<string, mixed> */
            public array $context = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->level = (string) $level;
                $this->message = (string) $message;
                $this->context = $context;
            }
        };

        new LogSagaHistorySink($logger)
            ->record(new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', ['startState' => 'debit']));

        self::assertSame('info', $logger->level);
        self::assertSame('storm.saga.history', $logger->message);
        self::assertSame('transfer', $logger->context['workflow_type']);
        self::assertSame('corr-1', $logger->context['correlation_id']);
        self::assertSame('SagaStarted', $logger->context['event_type']);
        self::assertSame(['startState' => 'debit'], $logger->context['payload']);
    }

    #[Test]
    public function the_record_carries_every_field_of_the_entry_it_was_built_from(): void
    {
        $logger = new class() extends AbstractLogger
        {
            /** @var array<string, mixed> */
            public array $context = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->context = $context;
            }
        };

        $entry = new SagaHistoryEntry(
            'transfer',
            'corr-1',
            'SagaStarted',
            ['startState' => 'debit'],
            generation: 2,
            eventId: 'evt-1',
            occurredAt: '2026-08-01T07:53:31.000000+00:00',
        );

        new LogSagaHistorySink($logger)->record($entry);

        // the expected keys derive from the entry itself: a field added there and forgotten in the
        // sink fails here loudly instead of silently vanishing from the shipped record
        $expected = [];
        foreach (get_object_vars($entry) as $property => $value) {
            $expected[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property))] = $value;
        }
        ksort($expected);

        $context = $logger->context;
        ksort($context);

        self::assertSame($expected, $context);
    }
}
