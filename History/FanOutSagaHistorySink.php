<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * Forwards each entry to several sinks: how writing to more than one at once is expressed, for example
 * log and table. A plain loop that does NOT catch, so for per-sink isolation each child must be
 * best-effort, wrapped in a `BestEffortSagaHistorySink`; otherwise the first child to throw skips the
 * rest. The intended composition is `FanOut([BestEffort(a), BestEffort(b), …])`, where a failing child
 * neither starves its siblings nor reaches the subscriber's backstop.
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
