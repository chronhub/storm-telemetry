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
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

/**
 * Adapts the engine's in-process `Saga*` domain events into `SagaHistoryEntry` records and forwards
 * them to the configured `SagaHistorySink`. The destination is the sink's choice, not hard-coded here:
 * a queryable table, a log stream, an async publish, or several via a fan-out. One `#[AsEventListener]`
 * per `Saga*` event; the completeness guard enforces that every event keeps a handler.
 *
 * Cardinal rule: the observer must not break the observed. The engine dispatches these after the saga
 * commits, in `Step::finish`'s loop which has no isolation of its own, so a failure here must degrade to
 * a logged drop, never a thrown exception re-entering an already-committed saga; otherwise a retrying
 * bus would redeliver it, the timer runner would abort the batch, and the outbox relay's post-commit
 * settle would skip a compensation. A logging concern must never suppress a business rollback. This
 * top-level catch is the hard backstop holding whatever sink is wired, and its own fallback logger is
 * `BestEffortLogger`-wrapped, so the backstop cannot be pierced by the very channel it reports through;
 * a fan-out additionally isolates each sink via `BestEffortSagaHistorySink`. The event store stays the
 * source of truth; this trail may drop or duplicate rows.
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
        // the hard backstop must not be pierceable by its own fallback channel: the warning below
        // fires inside the engine's post-commit dispatch, where a throwing logger would re-enter a
        // committed saga, causing redelivery, an aborted timer batch, or skipped compensation
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
    public function onSagaPaused(SagaPaused $event): void
    {
        $this->record($event);
    }

    #[AsEventListener]
    public function onSagaResumed(SagaResumed $event): void
    {
        $this->record($event);
    }

    /**
     * Normalize and forward. Best-effort: any failure is caught and logged, never propagated back into the
     * engine's post-commit dispatch, per the cardinal rule that the observer must not break the observed.
     */
    private function record(object $event): void
    {
        try {
            // identity and occurrence time are minted HERE, before any fan-out or async hop: the
            // eventId survives transport retries, since the same id means the same announcement
            // redelivered, and occurredAt is the announce time; on the async strategy the table's
            // recorded_at is the CONSUMPTION time, which orders arrival, not the saga's life
            $this->sink->record(SagaHistoryEntry::fromSagaEvent(
                $event,
                bin2hex(random_bytes(16)),
                $this->clock->now()->toString(),
            ));
        } catch (Throwable $e) {
            // A dropped audit row is the acceptable degradation; a thrown exception here is not. It would
            // break a saga that already committed. Log it under the exception key for a Monolog backtrace.
            $this->logger->warning('storm.telemetry.history_write_failed', [
                'event' => $event::class,
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
