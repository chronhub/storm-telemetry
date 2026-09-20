<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;

/**
 * The event-store outbox and inbox block, read by table name from the core schema. The partition
 * dimension is deliberately AGGREGATED, a distinct count rather than a label: a partition key IS a
 * qualified stream, so it names one aggregate instance, an unbounded set that would explode series
 * cardinality.
 *
 * `cooling` counts pending rows whose `next_attempt_at` lies in the future, backing off after a
 * failure or held by the ordering cooling floor; a relay reading zero pending while cooling is
 * high is drained, not idle.
 */
final readonly class OutboxMetricsCollector implements MetricsCollector
{
    public function __construct(
        private Connection $connection,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure interrogating the tables
     */
    public function families(): array
    {
        $families = [];

        if ($this->connection->createSchemaManager()->tablesExist(['es_outbox'])) {
            /** @var array<string, int|string|null> $row */
            // One pass over the pending rows, grouped by partition in a hash aggregate: the partition
            // count falls out of the group count, the other gauges out of the group sums. A
            // `count(DISTINCT partition_key)` sorted every pending row on every scrape, spilling to
            // disk past a few hundred thousand and keeping the pass single-threaded. The failed
            // rows are few and ride their own partial index.
            $row = (array) $this->connection->fetchAssociative(
                /* language=PostgreSQL */
                "SELECT
                    COALESCE(sum(p.n), 0) AS pending,
                    count(*) AS partitions,
                    COALESCE(sum(p.cooling), 0) AS cooling,
                    COALESCE(sum(p.backing_off), 0) AS backing_off,
                    COALESCE(EXTRACT(EPOCH FROM (clock_timestamp() - min(p.oldest)))::bigint, 0) AS oldest_pending_age,
                    (SELECT count(*) FROM es_outbox WHERE status = 'failed') AS failed
                 FROM (
                    SELECT partition_key,
                           count(*) AS n,
                           count(*) FILTER (WHERE next_attempt_at > clock_timestamp()) AS cooling,
                           count(*) FILTER (WHERE attempts > 0) AS backing_off,
                           min(occurred_at) AS oldest
                    FROM es_outbox
                    WHERE status = 'pending'
                    GROUP BY partition_key
                 ) p",
            );

            $families[] = MetricFamily::gauge('storm_outbox_events', 'Event outbox rows by status', [
                new MetricSample(['status' => 'pending'], (int) ($row['pending'] ?? 0)),
                new MetricSample(['status' => 'failed'], (int) ($row['failed'] ?? 0)),
            ]);
            $families[] = MetricFamily::gauge('storm_outbox_events_pending_partitions', 'Distinct partitions holding pending event rows', [
                new MetricSample([], (int) ($row['partitions'] ?? 0)),
            ]);
            $families[] = MetricFamily::gauge('storm_outbox_events_cooling', 'Pending event rows whose next attempt lies in the future', [
                new MetricSample([], (int) ($row['cooling'] ?? 0)),
            ]);
            // a row that failed a publish and is still retried, whether or not its back-off has
            // passed: what a broker outage leaves behind, and what the pending count alone hides
            $families[] = MetricFamily::gauge('storm_outbox_events_backing_off', 'Pending event rows that failed at least one publish and are still retried', [
                new MetricSample([], (int) ($row['backing_off'] ?? 0)),
            ]);
            $families[] = MetricFamily::gauge('storm_outbox_events_oldest_pending_age_seconds', 'Age of the oldest still-pending event row, 0 when none', [
                new MetricSample([], (int) ($row['oldest_pending_age'] ?? 0)),
            ]);
        }

        if ($this->connection->createSchemaManager()->tablesExist(['es_outbox_relay'])) {
            /** @var array{age: int|string|null, relayed: int|string|null}|false $beat */
            $beat = $this->connection->fetchAssociative(
                /* language=PostgreSQL */
                'SELECT EXTRACT(EPOCH FROM (clock_timestamp() - max(ticked_at)))::bigint AS age, sum(relayed_total) AS relayed FROM es_outbox_relay',
            );

            // No sample at all before the first tick, never a zero: a zero would read as a relay
            // that just ticked, the very blindness this family exists to end; an absent series is
            // what an alert rule tests for with absent().
            if ($beat !== false && $beat['age'] !== null) {
                $families[] = MetricFamily::gauge('storm_outbox_relay_tick_age_seconds', 'Seconds since the event outbox relay last drained, whether or not it found work', [
                    new MetricSample([], (int) $beat['age']),
                ]);
                $families[] = MetricFamily::gauge('storm_outbox_relay_relayed_total', 'Event rows the outbox relay has disposed of over its heartbeat rows', [
                    new MetricSample([], (int) ($beat['relayed'] ?? 0)),
                ]);
            }
        }

        if ($this->connection->createSchemaManager()->tablesExist(['es_inbox'])) {
            // The retained rows are the statistics collector's live-tuple estimate, never a
            // `count(*)`: the inbox takes one row per consumed message and a scrape walked all of
            // them, at a cost that grew with the retention and rivaled the inserts it measured.
            // The two duplicate gauges stay exact and ride the partial index on `duplicates > 0`,
            // a handful of rows however many the table retains.
            // absent, never a zero, when the statistics row is not found under the current schema:
            // a zero would read as an empty inbox, and the two duplicate gauges below read the table
            // by the search path, so the two could disagree in silence
            $rows = $this->connection->fetchOne(
                /* language=PostgreSQL */
                "SELECT n_live_tup FROM pg_stat_user_tables WHERE relname = 'es_inbox' AND schemaname = current_schema()",
            );
            /** @var array{skipped: int|string, touched: int|string} $inbox */
            $inbox = $this->connection->fetchAssociative(
                /* language=PostgreSQL */
                'SELECT COALESCE(sum(duplicates), 0) AS skipped, count(*) AS touched
                 FROM es_inbox WHERE duplicates > 0',
            );
            if ($rows !== false && $rows !== null) {
                $families[] = MetricFamily::gauge('storm_inbox_rows', 'Processed-message rows currently retained by the idempotency inbox, as the statistics collector estimates them', [
                    new MetricSample([], (int) $rows),
                ]);
            } else {
                // absent is invisible on a dashboard; the log line is what makes the case audible.
                // Defensive: `tablesExist` reads the same schemas `current_schema()` heads, so a
                // table found without its statistics row has not been reproduced
                $this->logger?->warning('storm.telemetry.inbox_rows_gauge_absent', ['schema' => $this->connection->fetchOne('SELECT current_schema()')]);
            }
            // the redeliveries the inbox absorbed, over the rows it retains: a skip runs no handler
            // and acks, so without these two the class leaves no trace outside the table
            $families[] = MetricFamily::gauge('storm_inbox_duplicates_skipped', 'Redeliveries the idempotency inbox absorbed, summed over the rows it retains', [
                new MetricSample([], (int) $inbox['skipped']),
            ]);
            $families[] = MetricFamily::gauge('storm_inbox_duplicate_rows', 'Retained inbox rows that absorbed at least one redelivery', [
                new MetricSample([], (int) $inbox['touched']),
            ]);
        }

        return $families;
    }
}
