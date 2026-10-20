<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use Psr\Log\LoggerInterface;
use Storm\Telemetry\BestEffortLogger;
use Throwable;

/**
 * Wraps a sink so a recording failure ends as a logged drop, never a thrown exception, since the
 * observer must not break the observed.
 *
 * It belongs on each child of a `FanOutSagaHistorySink`: one sink failing on a database blip or a down
 * log transport must not keep the others from recording. The subscriber keeps its own backstop for the
 * engine's safety; this decorator adds the sibling isolation a bare fan-out lacks.
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
            // a dropped audit row is the accepted degradation; the warning must not throw either,
            // hence the `BestEffortLogger` wrap at construction
            $this->logger->warning('storm.telemetry.history_write_failed', [
                'sink' => $this->inner::class,
                'event_type' => $entry->eventType,
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
