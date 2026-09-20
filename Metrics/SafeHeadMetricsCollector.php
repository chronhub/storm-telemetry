<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;

/** Observes only the gap immediately above the persisted floor, never general backlog age. */
final readonly class SafeHeadMetricsCollector implements MetricsCollector
{
    public function __construct(private Connection $connection) {}

    public function families(): array
    {
        if (! $this->connection->createSchemaManager()->tablesExist(['event_store', 'event_store_high_water'])) {
            return [];
        }
        $row = $this->connection->fetchAssociative(
            'WITH floor AS (SELECT COALESCE((SELECT position FROM event_store_high_water WHERE id = 1), 0) AS position) '
            .'SELECT CASE WHEN e.sequence_no > f.position + 1 THEN 1 ELSE 0 END AS active, '
            .'CASE WHEN e.sequence_no > f.position + 1 THEN GREATEST(0, EXTRACT(EPOCH FROM clock_timestamp() - e.recorded_at)) ELSE 0 END AS age '
            .'FROM floor f LEFT JOIN LATERAL (SELECT sequence_no, recorded_at FROM event_store '
            .'WHERE sequence_no > f.position ORDER BY sequence_no LIMIT 1) e ON true',
        );

        return [
            MetricFamily::gauge('storm_safe_head_gap_present', 'One when the first committed row above the floor leaves a gap', [new MetricSample([], (int) ($row['active'] ?? 0))]),
            MetricFamily::gauge('storm_safe_head_gap_neighbor_age_seconds', 'Age of the upper neighbor of the head gap, not elapsed blocking time', [new MetricSample([], (float) ($row['age'] ?? 0))]),
        ];
    }
}
