<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * Why a saga timeline came back empty, the distinction that keeps an empty answer from lying.
 *
 * Saga history takes three opt-ins:
 *
 * - The package must be installed
 * - `storm:telemetry:install` must have created the table
 * - The app must have pointed the `SagaHistorySink` alias at {@see TableSagaHistorySink}
 *
 * The default alias is {@see NullSagaHistorySink}, and the table sink itself does nothing while the
 * table is absent. An operator reading "no history for this saga" would conclude the engine recorded
 * nothing, when usually nothing was ever wired to record.
 *
 * Every case is an observable fact about the table, never a verdict on the wiring: rows cannot tell
 * an active sink over an empty table from no sink at all, nor stale rows from a live recorder. A fresh
 * install writes nothing yet, a full prune empties a recording table, and old rows outlive an alias
 * pointed back at null. The messages built on these cases must name the possibilities rather than
 * pick one.
 */
enum HistoryAvailability: string
{
    /**
     * The table does not exist: `storm:telemetry:install` has not run, and its absence says nothing
     * about any saga.
     */
    case NotInstalled = 'not_installed';

    /**
     * The table exists and holds no row at all: nothing has landed yet, through a fresh install, a full
     * prune or no recording sink, and the rows cannot tell which.
     */
    case EmptyTable = 'empty_table';

    /**
     * Rows exist for other correlations: something wrote here at some point, and this correlation has
     * nothing that landed.
     */
    case HasRows = 'has_rows';
}
