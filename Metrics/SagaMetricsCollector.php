<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * The saga block: instances, timers and the command outbox, read by table name from the schema
 * `storm:saga:install` creates. Labels are bounded by construction, workflow types and declared
 * state keys and status enums, never a correlation.
 *
 * Absent tables mean the opt-in Saga package is not installed on this connection: the block
 * reports nothing, and the health surface, not this one, owns naming incomplete installs.
 */
final readonly class SagaMetricsCollector implements MetricsCollector
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
        if (! $this->connection->createSchemaManager()->tablesExist(['workflow_instances', 'workflow_timers', 'workflow_outbox'])) {
            return [];
        }

        return [
            ...$this->instanceFamilies(),
            MetricFamily::gauge(
                'storm_saga_timers',
                'Saga timers by disposition: due, scheduled, claimed, parked',
                $this->timerSamples(),
            ),
            ...$this->outboxFamilies(),
        ];
    }

    /**
     * @return list<MetricSample>
     */
    private function timerSamples(): array
    {
        /** @var array<string, int|string> $row */
        $row = (array) $this->connection->fetchAssociative(
            /** @lang PostgreSQL */
            'SELECT
                count(*) FILTER (WHERE parked_at IS NOT NULL) AS parked,
                count(*) FILTER (WHERE claimed_at IS NOT NULL AND parked_at IS NULL) AS claimed,
                count(*) FILTER (WHERE claimed_at IS NULL AND parked_at IS NULL AND fire_at <= clock_timestamp()) AS due,
                count(*) FILTER (WHERE claimed_at IS NULL AND parked_at IS NULL AND fire_at > clock_timestamp()) AS scheduled
             FROM workflow_timers',
        );

        $samples = [];
        foreach (['due', 'scheduled', 'claimed', 'parked'] as $disposition) {
            $samples[] = new MetricSample(['disposition' => $disposition], (int) ($row[$disposition] ?? 0));
        }

        return $samples;
    }

    /**
     * @return list<MetricFamily>
     */
    private function outboxFamilies(): array
    {
        // three scalar subqueries rather than one pass of `count(*) FILTER`: an aggregate filtered
        // over the WHOLE table cannot use a partial index, so the one-pass form reads every row of
        // the outbox, archive included, to answer three numbers that are usually zero. Split, each
        // arm is served by the partial index the schema already declares on its status. On a live
        // two-million-row outbox the difference is a full parallel scan against three buffers, and
        // the scrape runs on a timer, so the cost repeats forever.
        /** @var array<string, int|string|null> $row */
        $row = (array) $this->connection->fetchAssociative(
            /** @lang PostgreSQL */
            "SELECT
                (SELECT count(*) FROM workflow_outbox WHERE status = 'pending') AS pending,
                (SELECT count(*) FROM workflow_outbox WHERE status = 'failed') AS failed,
                COALESCE((SELECT EXTRACT(EPOCH FROM (clock_timestamp() - min(created_at)))::bigint
                          FROM workflow_outbox WHERE status = 'pending'), 0) AS oldest_pending_age",
        );

        return [
            MetricFamily::gauge('storm_saga_outbox', 'Saga command outbox rows by status', [
                new MetricSample(['status' => 'pending'], (int) ($row['pending'] ?? 0)),
                new MetricSample(['status' => 'failed'], (int) ($row['failed'] ?? 0)),
            ]),
            MetricFamily::gauge('storm_saga_outbox_oldest_pending_age_seconds', 'Age of the oldest still-pending saga command, 0 when none', [
                new MetricSample([], (int) ($row['oldest_pending_age'] ?? 0)),
            ]),
        ];
    }

    /**
     * The three instance gauges, folded from ONE grouping.
     *
     * They are three projections of the same `GROUP BY`, so asking them separately reads the whole
     * instance table three times per scrape to answer questions one pass already contains. The fused
     * result is bounded by declaration, one row per workflow type, status and state key a definition
     * declares, and the folding happens over that handful of rows.
     *
     * @return list<MetricFamily>
     *
     * @throws Exception on a DBAL failure interrogating the table
     */
    private function instanceFamilies(): array
    {
        /** @var array<string, array<string, int>> $byStatus */
        $byStatus = [];
        /** @var array<string, array<string, int>> $byState */
        $byState = [];
        /** @var array<string, int> $retries */
        $retries = [];

        foreach ($this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            'SELECT workflow_type, status, state_key,
                    count(*) AS n,
                    COALESCE(sum(retry_total), 0) AS retries
             FROM workflow_instances
             GROUP BY 1, 2, 3',
        ) as $row) {
            $type = (string) $row['workflow_type'];
            $status = (string) $row['status'];
            $state = (string) $row['state_key'];
            $n = (int) $row['n'];

            $byStatus[$type][$status] = ($byStatus[$type][$status] ?? 0) + $n;
            $byState[$type][$state] = ($byState[$type][$state] ?? 0) + $n;
            $retries[$type] = ($retries[$type] ?? 0) + (int) $row['retries'];
        }

        return [
            MetricFamily::gauge(
                'storm_saga_instances',
                'Saga instances by workflow type and status',
                self::samples($byStatus, 'status'),
            ),
            MetricFamily::gauge(
                'storm_saga_instances_by_state',
                'Saga instances by workflow type and current state key',
                self::samples($byState, 'state_key'),
            ),
            MetricFamily::gauge(
                'storm_saga_retries',
                'Sum of per-instance retry counts by workflow type',
                array_map(
                    static fn (string $type): MetricSample => new MetricSample(['workflow_type' => $type], $retries[$type]),
                    array_keys($retries),
                ),
            ),
        ];
    }

    /**
     * One sample per pair of the folded map, the second dimension named by `$label`.
     *
     * @param  array<string, array<string, int>>  $folded
     * @return list<MetricSample>
     */
    private static function samples(array $folded, string $label): array
    {
        $samples = [];

        foreach ($folded as $type => $values) {
            foreach ($values as $value => $n) {
                $samples[] = new MetricSample(['workflow_type' => $type, $label => (string) $value], $n);
            }
        }

        return $samples;
    }
}
