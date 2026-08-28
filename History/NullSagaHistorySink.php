<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * Saga history off: the no-op sink. The choice for an app that wants none of the recording cost, and a
 * safe default for a build that pulls the Telemetry package but routes history nowhere.
 */
final class NullSagaHistorySink implements SagaHistorySink
{
    public function record(SagaHistoryEntry $entry): void
    {
        // intentionally nothing
    }
}
