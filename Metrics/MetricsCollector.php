<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

/**
 * One block of ops metrics, collected statelessly at scrape time: bounded reads of the live
 * database, no process counters, no storage, so the numbers are correct under any count of
 * workers and the endpoint stays a pure read.
 *
 * Implementations observe by TABLE NAME, never by type, the health-check convention: Telemetry
 * reads the modules it observes without depending on them. A collector whose tables are absent
 * returns an empty list, since an opt-in feature that is off has nothing to report; a throwing
 * collector is caught by the exposition and surfaced as an error sample, never a failed scrape.
 * The exposition also bounds each collector with a per-scrape `statement_timeout` on the default
 * connection, surfaced through the same error sample; a collector reading another connection
 * must bound its own queries.
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
