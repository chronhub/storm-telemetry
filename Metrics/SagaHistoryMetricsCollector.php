<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * True event COUNTERS for the saga lifecycle, derived from `workflow_history`: escalations, skips,
 * retries, overdue waits and every other recorded announcement, one series per workflow type and
 * event type, both bounded vocabularies. A scrape can only count what leaves a trace at rest, and
 * the history table is that trace.
 *
 * The honesty of the derivation lives in the help line: the counts exist only while a TABLE sink
 * records, a zero under a log-only or null sink means "not recorded", never "never happened", and
 * `storm:telemetry:prune` resets them, which Prometheus counter semantics absorb. Per-reason
 * drill-down stays in the rows themselves, the history surfaces; a reason label can join later if
 * a dashboard earns it.
 */
final readonly class SagaHistoryMetricsCollector implements MetricsCollector
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
        if (! $this->connection->createSchemaManager()->tablesExist(['workflow_history'])) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            /* language=PostgreSQL */
            'SELECT workflow_type, event_type, count(*) AS n FROM workflow_history GROUP BY 1, 2',
        );

        $samples = array_map(
            static fn (array $row): MetricSample => new MetricSample(
                ['workflow_type' => (string) $row['workflow_type'], 'event_type' => (string) $row['event_type']],
                (int) $row['n'],
            ),
            $rows,
        );

        return [
            MetricFamily::counter(
                'storm_saga_history_events_total',
                'Saga announcements as recorded by the workflow_history table sink; zero while no table sink is active, reset by prune',
                $samples,
            ),
        ];
    }
}
