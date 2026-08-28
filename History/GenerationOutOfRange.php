<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use InvalidArgumentException;

use function sprintf;

/**
 * A `generation` filter outside what the `workflow_history.generation` column can hold: refused by
 * the store before the query, where the driver would otherwise answer an out-of-range error quoting
 * the SQL to the caller. The bound lives beside the column it describes, so the console and HTTP
 * surfaces inherit one refusal without coordinating a copy of it.
 */
final class GenerationOutOfRange extends InvalidArgumentException
{
    public static function outside(int $generation): self
    {
        return new self(sprintf(
            'The generation %d is outside what the history column stores, 1 to %d: no run ever carried it.',
            $generation,
            WorkflowHistoryStore::MAX_GENERATION,
        ));
    }
}
