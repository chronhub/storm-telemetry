<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;

/**
 * Exact counts of categories still held by the DEFAULT partition.
 *
 * `MetricsExposition` bounds this collector independently of the catalog-backed sizes.
 * A timeout omits the family and reports a collector error; missing counts never mean zero.
 */
final readonly class EventStoreDefaultRowsMetricsCollector implements MetricsCollector
{
    public function __construct(private Connection $connection) {}

    public function families(): array
    {
        if (! $this->connection->createSchemaManager()->tablesExist(['event_store'])) {
            return [];
        }

        $held = $this->connection->fetchAllAssociative('SELECT category, count(*) AS rows FROM event_store_default GROUP BY category ORDER BY category');

        return [MetricFamily::gauge('storm_event_store_default_rows', 'Rows the DEFAULT partition holds per category, the categories never partitioned', array_map(
            static fn (array $row): MetricSample => new MetricSample(['category' => $row['category']], (int) $row['rows']),
            $held,
        ))];
    }
}
