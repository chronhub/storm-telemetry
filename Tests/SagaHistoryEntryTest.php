<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Event\SagaRetried;
use Storm\Saga\Event\SagaStarted;
use Storm\Saga\Event\SagaTransitioned;
use Storm\Telemetry\History\SagaHistoryEntry;

/**
 * The normalization every sink relies on: trace columns split out, the event's own data kept as the
 * payload, the event named by its short class name. Tested once here so no sink repeats it.
 */
final class SagaHistoryEntryTest extends TestCase
{
    #[Test]
    public function splits_the_two_trace_columns_out_of_the_payload(): void
    {
        // workflowType / correlationId are columns; keeping them in the payload would duplicate them.
        $entry = SagaHistoryEntry::fromSagaEvent(new SagaTransitioned('transfer', 'corr-2', 1, 'debit', 'await_debit'));

        self::assertSame('transfer', $entry->workflowType);
        self::assertSame('corr-2', $entry->correlationId);
        self::assertArrayNotHasKey('workflowType', $entry->payload);
        self::assertArrayNotHasKey('correlationId', $entry->payload);
        self::assertEqualsCanonicalizing(['from' => 'debit', 'to' => 'await_debit'], $entry->payload);
    }

    #[Test]
    public function names_the_event_by_its_short_class_name(): void
    {
        self::assertSame(
            'SagaStarted',
            SagaHistoryEntry::fromSagaEvent(new SagaStarted('transfer', 'corr-1', 1, 'debit'))->eventType,
        );
    }

    #[Test]
    public function keeps_the_event_specific_payload(): void
    {
        // a retry's value is in its numbers; they must survive into the payload
        $entry = SagaHistoryEntry::fromSagaEvent(new SagaRetried('transfer', 'corr-r', 1, 'charge', 2, 7));

        self::assertEqualsCanonicalizing(['stateKey' => 'charge', 'attempt' => 2, 'retryTotal' => 7], $entry->payload);
    }

    #[Test]
    public function the_three_optional_fields_default_to_the_honest_unknown(): void
    {
        // the wire shapes that predate identity and generation build exactly this way, and every
        // default is a claim an operator reads: generation 0 says unknown, never "the first run",
        // and an empty eventId says "this row was never given identity", never "identity zero"
        $entry = new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []);

        self::assertSame(0, $entry->generation);
        self::assertSame('', $entry->eventId);
        self::assertSame('', $entry->occurredAt);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_event_whose_trace_values_are_off_type_still_normalizes(): void
    {
        // the parameter is `object`, so any announcement reaches here, while the entry's own fields
        // are typed; under strict_types the casts are what stands between a malformed event and a
        // TypeError raised inside the subscriber's backstop, where the row would be dropped whole
        $entry = SagaHistoryEntry::fromSagaEvent(new class()
        {
            public int $workflowType = 42;

            public int $correlationId = 7;

            public string $generation = '3';
        });

        self::assertSame('42', $entry->workflowType);
        self::assertSame('7', $entry->correlationId);
        self::assertSame(3, $entry->generation);
    }

    #[Test]
    public function an_event_carrying_none_of_the_three_trace_fields_keeps_its_payload(): void
    {
        $entry = SagaHistoryEntry::fromSagaEvent(new class()
        {
            public string $reason = 'transport rejected the delivery';
        });

        self::assertSame('', $entry->workflowType);
        self::assertSame('', $entry->correlationId);
        self::assertSame(0, $entry->generation);
        self::assertSame(['reason' => 'transport rejected the delivery'], $entry->payload);
    }
}
