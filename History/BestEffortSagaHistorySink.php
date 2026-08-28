<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use Psr\Log\LoggerInterface;
use Storm\Telemetry\BestEffortLogger;
use Throwable;

/**
 * Wraps a sink so a recording failure degrades to a logged drop, never a thrown exception; the cardinal
 * rule of an observability layer is that the observer must not break the observed. Its place is per-child
 * inside a `FanOutSagaHistorySink`: one sink failing, on a DB blip or a down log transport, must not
 * starve the others. The subscriber keeps its own top-level backstop for the engine-safety guarantee;
 * this decorator adds the sibling-isolation that a bare fan-out lacks.
 *
 * @see FanOutSagaHistorySink
 */
final readonly class BestEffortSagaHistorySink implements SagaHistorySink
{
    private LoggerInterface $logger;

    public function __construct(
        private SagaHistorySink $inner,
        LoggerInterface $logger,
    ) {
        $this->logger = new BestEffortLogger($logger);
    }

    public function record(SagaHistoryEntry $entry): void
    {
        try {
            $this->inner->record($entry);
        } catch (Throwable $e) {
            // A dropped audit row is the acceptable degradation; a propagated throw is not. The
            // warning itself must hold that same promise, hence the BestEffortLogger wrap at construction.
            $this->logger->warning('storm.telemetry.history_write_failed', [
                'sink' => $this->inner::class,
                'event_type' => $entry->eventType,
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
