<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * Saga history off: the no-op sink.
 *
 * It suits an app that wants none of the recording cost, and is a safe default for a build that pulls
 * the Telemetry package but routes history nowhere.
 */
final class NullSagaHistorySink implements SagaHistorySink
{
    public function record(SagaHistoryEntry $entry): void
    {
        // records nothing
    }
}
