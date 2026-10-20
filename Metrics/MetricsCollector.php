<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

/**
 * One block of ops metrics, collected statelessly at scrape time.
 *
 * A collector makes bounded reads of the live database, with no process counter and no storage, so
 * the numbers hold under any count of workers and the endpoint stays a pure read. Implementations
 * observe by table name, never by type, the health-check convention, so Telemetry reads the modules
 * it observes without depending on them.
 *
 * - A collector whose tables are absent returns an empty list, since an opt-in feature that is off
 *   has nothing to report.
 *
 * - The exposition catches a throwing collector and reports it as an error sample, never a failed
 *   scrape.
 *
 * - The exposition bounds each collector with a per-scrape `statement_timeout` on the default
 *   connection, reported through the same error sample; a collector reading another connection must
 *   bound its own queries.
 */
interface MetricsCollector
{
    /**
     * Collect this block's families against the live database.
     *
     * @return list<MetricFamily>
     */
    public function families(): array;
}
