<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Storm\Clock\FrozenClock;
use Storm\Saga\Event\CompensationFailed;
use Storm\Saga\Event\SagaAwaitEscalated;
use Storm\Saga\Event\SagaAwaitOverdue;
use Storm\Saga\Event\SagaCancelled;
use Storm\Saga\Event\SagaCancelRefused;
use Storm\Saga\Event\SagaChildCancelSkipped;
use Storm\Saga\Event\SagaChildSpawnSkipped;
use Storm\Saga\Event\SagaCompensated;
use Storm\Saga\Event\SagaCompensationSkipped;
use Storm\Saga\Event\SagaCompleted;
use Storm\Saga\Event\SagaFamilyCrossingReplayed;
use Storm\Saga\Event\SagaFamilyMemberConcluded;
use Storm\Saga\Event\SagaGloballyTimedOut;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Event\SagaJoinArmArrived;
use Storm\Saga\Event\SagaJoinCompleted;
use Storm\Saga\Event\SagaJoinFailed;
use Storm\Saga\Event\SagaOutcomeDiscarded;
use Storm\Saga\Event\SagaPaused;
use Storm\Saga\Event\SagaRaceSettled;
use Storm\Saga\Event\SagaResumed;
use Storm\Saga\Event\SagaRetimed;
use Storm\Saga\Event\SagaRetimeDenied;
use Storm\Saga\Event\SagaRetried;
use Storm\Saga\Event\SagaStarted;
use Storm\Saga\Event\SagaTransitioned;
use Storm\Telemetry\History\SagaHistoryEntry;
use Storm\Telemetry\History\SagaHistorySink;
use Storm\Telemetry\SagaHistorySubscriber;
use Stringable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The subscriber is a thin adapter: it normalizes a Saga* event into a SagaHistoryEntry and forwards it to
 * the sink. The two behaviors that matter are the forward, and the cardinal rule that a sink failure must
 * not break the observed saga; the engine dispatches these after commit, with no isolation of its own.
 */
final class SagaHistorySubscriberTest extends TestCase
{
    #[Test]
    public function forwards_a_normalised_entry_to_the_sink(): void
    {
        $sink = new class() implements SagaHistorySink
        {
            public ?SagaHistoryEntry $entry = null;

            public function record(SagaHistoryEntry $entry): void
            {
                $this->entry = $entry;
            }
        };

        new SagaHistorySubscriber($sink, new NullLogger, FrozenClock::at('2026-07-18T10:00:00.000000Z'))
            ->onSagaStarted(new SagaStarted('TransferWorkflow', 'corr-1', 1, 'started'));

        self::assertNotNull($sink->entry);
        self::assertSame('TransferWorkflow', $sink->entry->workflowType);
        self::assertSame('corr-1', $sink->entry->correlationId);
        self::assertSame('SagaStarted', $sink->entry->eventType);
    }

    #[Test]
    #[DataProvider('handledSagaEvents')]
    public function forwards_all_supported_saga_events_to_the_sink(
        string $handler,
        object $event,
        string $expectedEventType,
    ): void {
        $sink = new class() implements SagaHistorySink
        {
            public ?SagaHistoryEntry $entry = null;

            public function record(SagaHistoryEntry $entry): void
            {
                $this->entry = $entry;
            }
        };

        $subscriber = new SagaHistorySubscriber($sink, new NullLogger, FrozenClock::at('2026-07-18T10:00:00.000000Z'));
        $subscriber->{$handler}($event);

        self::assertNotNull($sink->entry);
        self::assertSame('TransferWorkflow', $sink->entry->workflowType);
        self::assertSame('corr-1', $sink->entry->correlationId);
        self::assertSame($expectedEventType, $sink->entry->eventType);
    }

    /**
     * @return iterable<string, array{0: string, 1: object, 2: string}>
     */
    public static function handledSagaEvents(): iterable
    {
        yield 'started' => [
            'onSagaStarted',
            new SagaStarted('TransferWorkflow', 'corr-1', 1, 'started'),
            'SagaStarted',
        ];

        yield 'transitioned' => [
            'onSagaTransitioned',
            new SagaTransitioned('TransferWorkflow', 'corr-1', 1, 'started', 'charged'),
            'SagaTransitioned',
        ];

        yield 'completed' => [
            'onSagaCompleted',
            new SagaCompleted('TransferWorkflow', 'corr-1', 1, 'completed'),
            'SagaCompleted',
        ];

        yield 'halted' => [
            'onSagaHalted',
            new SagaHalted('TransferWorkflow', 'corr-1', 1, 'charged'),
            'SagaHalted',
        ];

        yield 'compensated' => [
            'onSagaCompensated',
            new SagaCompensated('TransferWorkflow', 'corr-1', 1, ['charged']),
            'SagaCompensated',
        ];

        yield 'compensation skipped' => [
            'onSagaCompensationSkipped',
            new SagaCompensationSkipped('TransferWorkflow', 'corr-1', 1, ['charged']),
            'SagaCompensationSkipped',
        ];

        yield 'globally timed out' => [
            'onSagaGloballyTimedOut',
            new SagaGloballyTimedOut('TransferWorkflow', 'corr-1', 1, 'charged'),
            'SagaGloballyTimedOut',
        ];

        yield 'await escalated' => [
            'onSagaAwaitEscalated',
            new SagaAwaitEscalated('TransferWorkflow', 'corr-1', 1, 'charged'),
            'SagaAwaitEscalated',
        ];

        yield 'await overdue' => [
            'onSagaAwaitOverdue',
            new SagaAwaitOverdue('TransferWorkflow', 'corr-1', 1, 'charged'),
            'SagaAwaitOverdue',
        ];

        yield 'compensation failed' => [
            'onCompensationFailed',
            new CompensationFailed('TransferWorkflow', 'corr-1', 1, 'charged', 'boom'),
            'CompensationFailed',
        ];

        yield 'cancelled' => [
            'onSagaCancelled',
            new SagaCancelled('TransferWorkflow', 'corr-1', 1, 'charged', 'operator request'),
            'SagaCancelled',
        ];

        yield 'retried' => [
            'onSagaRetried',
            new SagaRetried('TransferWorkflow', 'corr-1', 1, 'charged', 2, 3),
            'SagaRetried',
        ];

        yield 'cancel refused' => [
            'onSagaCancelRefused',
            new SagaCancelRefused('TransferWorkflow', 'corr-1', 1, 'charged', 'at an effect-gating wait'),
            'SagaCancelRefused',
        ];

        yield 'outcome discarded' => [
            'onSagaOutcomeDiscarded',
            new SagaOutcomeDiscarded('TransferWorkflow', 'corr-1', 1, 'charged', stdClass::class),
            'SagaOutcomeDiscarded',
        ];

        yield 'child spawn skipped' => [
            'onSagaChildSpawnSkipped',
            new SagaChildSpawnSkipped('TransferWorkflow', 'corr-1', 1, 'ChargeWorkflow', 'charge', 'already spawned'),
            'SagaChildSpawnSkipped',
        ];

        yield 'child cancel skipped' => [
            'onSagaChildCancelSkipped',
            new SagaChildCancelSkipped('TransferWorkflow', 'corr-1', 1, 'ChargeWorkflow', 'child-1', 'already concluded'),
            'SagaChildCancelSkipped',
        ];

        yield 'family member concluded' => [
            'onSagaFamilyMemberConcluded',
            new SagaFamilyMemberConcluded('TransferWorkflow', 'corr-1', 1, 'await_children', 'charges', 3, 3, 2),
            'SagaFamilyMemberConcluded',
        ];

        yield 'family crossing replayed' => [
            'onSagaFamilyCrossingReplayed',
            new SagaFamilyCrossingReplayed('TransferWorkflow', 'corr-1', 1, 'await_children', stdClass::class),
            'SagaFamilyCrossingReplayed',
        ];

        yield 'retimed' => [
            'onSagaRetimed',
            new SagaRetimed('TransferWorkflow', 'corr-1', 1, 'charged', 30, 2),
            'SagaRetimed',
        ];

        yield 'retime denied' => [
            'onSagaRetimeDenied',
            new SagaRetimeDenied('TransferWorkflow', 'corr-1', 1, 'charged', 'the cap is reached'),
            'SagaRetimeDenied',
        ];

        yield 'race settled' => [
            'onSagaRaceSettled',
            new SagaRaceSettled('TransferWorkflow', 'corr-1', 1, 'charged', 'card', ['wire'], ['cash'], []),
            'SagaRaceSettled',
        ];

        yield 'join arm arrived' => [
            'onSagaJoinArmArrived',
            new SagaJoinArmArrived('TransferWorkflow', 'corr-1', 1, 'charged', 'card', ['wire']),
            'SagaJoinArmArrived',
        ];

        yield 'join completed' => [
            'onSagaJoinCompleted',
            new SagaJoinCompleted('TransferWorkflow', 'corr-1', 1, 'charged', ['card', 'wire']),
            'SagaJoinCompleted',
        ];

        yield 'join failed' => [
            'onSagaJoinFailed',
            new SagaJoinFailed('TransferWorkflow', 'corr-1', 1, 'charged', 'card', ['wire'], [], ['cash']),
            'SagaJoinFailed',
        ];

        yield 'paused' => [
            'onSagaPaused',
            new SagaPaused('TransferWorkflow', 'corr-1', 1, 'maintenance window'),
            'SagaPaused',
        ];

        yield 'resumed' => [
            'onSagaResumed',
            new SagaResumed('TransferWorkflow', 'corr-1', 1),
            'SagaResumed',
        ];
    }

    #[Test]
    public function every_declared_handler_is_exercised_by_the_forwarding_suite(): void
    {
        // the structural guard next door proves every saga event HAS a handler, from the parameter
        // types alone; it cannot see a body. A handler whose body is empty, or that forwards a
        // hand-built entry instead of delegating, passes it and drops audit rows in silence, so the
        // forwarding suite has to run every one. This binds the two: a handler added without its
        // case here fails, rather than joining the list of the silently unexercised
        $exercised = [];
        foreach (self::handledSagaEvents() as [$handler]) {
            $exercised[] = $handler;
        }

        $declared = [];
        foreach (new ReflectionClass(SagaHistorySubscriber::class)->getMethods() as $method) {
            if ($method->getAttributes(AsEventListener::class) !== []) {
                $declared[] = $method->getName();
            }
        }

        sort($declared);
        $unexercised = array_values(array_diff($declared, $exercised));

        self::assertSame([], $unexercised, 'handlers no forwarding case runs: '.implode(', ', $unexercised));
    }

    #[Test]
    public function a_sink_failure_is_caught_and_logged_not_propagated(): void
    {
        $sink = new class() implements SagaHistorySink
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

        // The cardinal assertion: the handler must NOT throw, even though the sink fails.
        // If it threw, this test would error; the failure would have re-entered the committed saga.
        new SagaHistorySubscriber($sink, $logger, FrozenClock::at('2026-07-18T10:00:00.000000Z'))
            ->onSagaStarted(new SagaStarted('TransferWorkflow', 'corr-1', 1, 'started'));

        self::assertSame('warning', $logger->level); // surfaced, not silent
        self::assertSame('storm.telemetry.history_write_failed', $logger->message);
        // this warning is the ONLY trace a dropped audit row leaves, so it names the announcement
        // that was lost and the cause; the exception key is what gives Monolog its backtrace
        self::assertSame(SagaStarted::class, $logger->context['event']);
        self::assertSame('sink down', $logger->context['error_message']);
        self::assertInstanceOf(RuntimeException::class, $logger->context['exception']);
    }

    #[Test]
    public function the_backstop_is_not_pierceable_by_its_own_logger(): void
    {
        // the double-failure pierce: the sink fails, THEN the fallback warning channel fails; the
        // "hard backstop" would otherwise let that second throw re-enter the committed saga's post-commit
        // dispatch, causing redelivery, an aborted timer batch, or skipped compensation. BestEffortLogger holds it.
        $sink = new class() implements SagaHistorySink
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

        new SagaHistorySubscriber($sink, $hostileLogger, FrozenClock::at('2026-07-18T10:00:00.000000Z'))
            ->onSagaStarted(new SagaStarted('TransferWorkflow', 'corr-1', 1, 'started'));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function mints_a_stable_identity_and_the_announce_time_before_any_fan_out(): void
    {
        $sink = new class() implements SagaHistorySink
        {
            /** @var list<SagaHistoryEntry> */
            public array $entries = [];

            public function record(SagaHistoryEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };
        $clock = FrozenClock::at('2026-07-18T10:00:00.000000Z');

        $subscriber = new SagaHistorySubscriber($sink, new NullLogger, $clock);
        $subscriber->onSagaRetried(new SagaRetried('TransferWorkflow', 'corr-1', 1, 'sk', 1, 3));
        $subscriber->onSagaRetried(new SagaRetried('TransferWorkflow', 'corr-1', 1, 'sk', 2, 3));

        [$first, $second] = $sink->entries;
        self::assertNotSame('', $first->eventId, 'identity is minted at the announcement');
        // the width is the collision budget: this id is the dedup key of the async strategy, where
        // two rows sharing one mean the same announcement redelivered, so a narrower id would make
        // distinct announcements collide and a deduplicating reader drop a real row
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->eventId, '128 bits, hex-encoded');
        self::assertNotSame($first->eventId, $second->eventId, 'two legitimate retries are DISTINCT events — dedup must never merge them');
        self::assertSame($clock->now()->toString(), $first->occurredAt, 'occurredAt is the announce time, not some later arrival time');
    }
}
