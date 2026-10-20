<?php

declare(strict_types=1);

namespace Storm\Telemetry;

use Psr\Log\LoggerInterface;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
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
use Storm\Saga\Event\SagaFamilyCrossingUnresolved;
use Storm\Saga\Event\SagaFamilyMemberConcluded;
use Storm\Saga\Event\SagaGloballyTimedOut;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Event\SagaJoinArmArrived;
use Storm\Saga\Event\SagaJoinCompleted;
use Storm\Saga\Event\SagaJoinFailed;
use Storm\Saga\Event\SagaOutcomeDeferred;
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
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

/**
 * Turns the engine's in-process `Saga*` events into `SagaHistoryEntry` records and forwards them to
 * the configured `SagaHistorySink`.
 *
 * The sink chooses the destination: a queryable table, a log stream, an async publish, or several
 * through a fan-out. Each `Saga*` event has its own `#[AsEventListener]`, and the completeness guard
 * enforces a handler for every event.
 *
 * The observer must not break the observed. The engine dispatches these events after the saga
 * commits, in the loop of `Step::finish` that has no isolation of its own, so a failure here must
 * end as a logged drop. A thrown exception would re-enter the committed saga:
 *
 * - A retrying bus would redeliver it.
 * - The timer runner would abort its batch.
 * - The outbox relay's post-commit settle would skip a compensation.
 *
 * A logging concern must never suppress a business rollback. The catch in `record()` is the backstop
 * for whatever sink is wired, and its fallback logger is wrapped in `BestEffortLogger`, so the
 * channel it reports through cannot break it; a fan-out also isolates each sink through
 * `BestEffortSagaHistorySink`. The event store stays the source of truth, and this trail may drop or
 * duplicate rows.
 */
final readonly class SagaHistorySubscriber
{
    private LoggerInterface $logger;

    /**
     * @param  Clock<PointInTime>  $clock  mints `occurredAt` at the announcement, before any fan-out
     */
    public function __construct(
        private SagaHistorySink $sink,
        LoggerInterface $logger,
        private Clock $clock,
    ) {
        $this->logger = new BestEffortLogger($logger);
    }

    #[AsEventListener]
    public function onSagaStarted(SagaStarted $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaTransitioned(SagaTransitioned $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaCompleted(SagaCompleted $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaHalted(SagaHalted $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaCompensated(SagaCompensated $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaCompensationSkipped(SagaCompensationSkipped $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaChildSpawnSkipped(SagaChildSpawnSkipped $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaChildCancelSkipped(SagaChildCancelSkipped $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaGloballyTimedOut(SagaGloballyTimedOut $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaAwaitEscalated(SagaAwaitEscalated $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaAwaitOverdue(SagaAwaitOverdue $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onCompensationFailed(CompensationFailed $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaCancelled(SagaCancelled $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaCancelRefused(SagaCancelRefused $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaOutcomeDiscarded(SagaOutcomeDiscarded $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaOutcomeDeferred(SagaOutcomeDeferred $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaRetried(SagaRetried $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaRetimed(SagaRetimed $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaRetimeDenied(SagaRetimeDenied $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaRaceSettled(SagaRaceSettled $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaJoinArmArrived(SagaJoinArmArrived $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaJoinCompleted(SagaJoinCompleted $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaJoinFailed(SagaJoinFailed $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaFamilyMemberConcluded(SagaFamilyMemberConcluded $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaFamilyCrossingReplayed(SagaFamilyCrossingReplayed $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaFamilyCrossingUnresolved(SagaFamilyCrossingUnresolved $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaPaused(SagaPaused $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaResumed(SagaResumed $event): void
    {
        $this->record($event);
    }

    private function record(object $event): void
    {
        try {
            // minted before any fan-out or async hop: a redelivered announcement keeps its event id,
            // and `occurredAt` is the announce time; an async table's `recorded_at` is consumption
            // time, which orders arrival rather than the saga's life
            $this->sink->record(SagaHistoryEntry::fromSagaEvent(
                $event,
                bin2hex(random_bytes(16)),
                $this->clock->now()->toString(),
            ));
        } catch (Throwable $e) {
            // a dropped audit row is the accepted degradation; `exception` gives Monolog the backtrace
            $this->logger->warning('storm.telemetry.history_write_failed', [
                'event' => $event::class,
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
