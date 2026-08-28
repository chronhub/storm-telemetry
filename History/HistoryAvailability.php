<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * Why a saga timeline came back empty; the distinction that keeps an empty answer from lying.
 *
 * Saga history is opt-in three times over:
 *
 * - The package must be installed;
 * - `storm:telemetry:install` must have created the table;
 * - The app must have pointed the `SagaHistorySink` alias at {@see TableSagaHistorySink}.
 *
 * The default alias is {@see NullSagaHistorySink}, and the table sink itself no-ops while the table
 * is absent. An operator reading "no history for this saga" would conclude the engine recorded
 * nothing, when the truth is usually that nothing was ever wired to record.
 *
 * Every case is an OBSERVABLE fact about the table, never a verdict on the wiring: rows cannot
 * tell an active sink over an empty table from no sink at all, nor stale rows from a live
 * recorder. A fresh install writes nothing yet, a full prune empties a recording table, and old
 * rows outlive an alias pointed back at null. The messages built on these cases must keep that
 * honesty and name the possibilities, not pick one.
 */
enum HistoryAvailability: string
{
    /** The table does not exist: `storm:telemetry:install` has not run, and absence says nothing about any saga. */
    case NotInstalled = 'not_installed';

    /** The table exists and holds no row at all: nothing has landed yet, whether a fresh install, a full prune, or no recording sink; rows cannot tell which. */
    case EmptyTable = 'empty_table';

    /** Rows exist for other correlations: something has written here at some point; this correlation has nothing that landed. */
    case HasRows = 'has_rows';
}
