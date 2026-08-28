<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * Where a saga's lifecycle history goes: the strategy seam. The `SagaHistorySubscriber` normalizes
 * each engine `Saga*` event into a `SagaHistoryEntry` and hands it here; the implementation decides
 * the destination, whether a queryable table, a log stream shipped to an external sink, an async
 * publish, or several at once via a `FanOutSagaHistorySink`.
 *
 * An implementation MAY throw, for instance on a DB blip or an encode failure. It is the caller's job
 * to keep that off the observed saga: the subscriber wraps the call in a backstop catch, and a fan-out
 * wraps each child in `BestEffortSagaHistorySink` for per-sink isolation. So a sink stays free to fail
 * loudly; the boundary around it guarantees the engine never sees it.
 */
interface SagaHistorySink
{
    /**
     * Record one saga-history entry to the sink's destination.
     */
    public function record(SagaHistoryEntry $entry): void;
}
