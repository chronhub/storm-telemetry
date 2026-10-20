<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * Where a saga's lifecycle history goes, the strategy seam.
 *
 * `SagaHistorySubscriber` turns each engine `Saga*` event into a `SagaHistoryEntry` and hands it here,
 * and the implementation decides the destination: a queryable table, a log stream shipped to an
 * external sink, an async publish, or several at once through a `FanOutSagaHistorySink`.
 *
 * An implementation may throw, on a database blip or an encode failure for instance, and the caller
 * keeps that off the observed saga: the subscriber wraps the call in a backstop catch, and a fan-out
 * wraps each child in `BestEffortSagaHistorySink` for per-sink isolation. A sink stays free to fail
 * loudly, since the boundary around it guarantees the engine never sees the failure.
 */
interface SagaHistorySink
{
    /**
     * Record one saga-history entry to the sink's destination.
     */
    public function record(SagaHistoryEntry $entry): void;
}
