<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * Forwards each entry to several sinks, the way to write to more than one at once, such as a log and a
 * table.
 *
 * The loop catches nothing, so for per-sink isolation each child must be wrapped in a
 * `BestEffortSagaHistorySink`; otherwise the first child to throw skips the rest. The intended
 * composition is `FanOut([BestEffort(a), BestEffort(b), …])`, where a failing child neither stops its
 * siblings nor reaches the subscriber's backstop.
 *
 * @see BestEffortSagaHistorySink
 */
final readonly class FanOutSagaHistorySink implements SagaHistorySink
{
    /**
     * @param  iterable<SagaHistorySink>  $sinks
     */
    public function __construct(private iterable $sinks) {}

    public function record(SagaHistoryEntry $entry): void
    {
        foreach ($this->sinks as $sink) {
            $sink->record($entry);
        }
    }
}
