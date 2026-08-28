<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

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
            $row = (array) $this->connection->fetchAssociative(
                /** @lang PostgreSQL */
                "SELECT
                    count(*) FILTER (WHERE status = 'pending') AS pending,
                    count(*) FILTER (WHERE status = 'failed') AS failed,
                    count(DISTINCT partition_key) FILTER (WHERE status = 'pending') AS partitions,
                    count(*) FILTER (WHERE status = 'pending' AND next_attempt_at > clock_timestamp()) AS cooling,
                    COALESCE(EXTRACT(EPOCH FROM (clock_timestamp() - min(occurred_at) FILTER (WHERE status = 'pending')))::bigint, 0) AS oldest_pending_age
                 FROM es_outbox",
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
            $families[] = MetricFamily::gauge('storm_outbox_events_oldest_pending_age_seconds', 'Age of the oldest still-pending event row, 0 when none', [
                new MetricSample([], (int) ($row['oldest_pending_age'] ?? 0)),
            ]);
        }

        if ($this->connection->createSchemaManager()->tablesExist(['es_inbox'])) {
            $families[] = MetricFamily::gauge('storm_inbox_rows', 'Processed-message rows currently retained by the idempotency inbox', [
                new MetricSample([], (int) $this->connection->fetchOne('SELECT count(*) FROM es_inbox')),
            ]);
        }

        return $families;
    }
}
