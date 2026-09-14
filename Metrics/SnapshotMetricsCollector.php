<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;

/**
 * The snapshot block: how many snapshots the store retains with their stream head, and how many
 * it holds for a stream that is gone, the orphans nothing else signals. Reports nothing when the
 * table is not installed rather than failing the scrape.
 */
final readonly class SnapshotMetricsCollector implements MetricsCollector
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @return list<MetricFamily>
     */
    public function families(): array
    {
        if (! $this->connection->createSchemaManager()->tablesExist(['snapshots'])) {
            return [];
        }
        /** @var array{retained: int|string, orphaned: int|string} $row */
        $row = (array) $this->connection->fetchAssociative(
            /* language=PostgreSQL */
            'SELECT count(*) FILTER (WHERE h.stream IS NOT NULL) AS retained, count(*) FILTER (WHERE h.stream IS NULL) AS orphaned
             FROM snapshots s LEFT JOIN stream_heads h ON h.stream = s.stream',
        );

        return [
            MetricFamily::gauge('storm_snapshots', 'Snapshot rows by status: retained with their stream head, or orphaned of it', [
                new MetricSample(['status' => 'retained'], (int) $row['retained']),
                new MetricSample(['status' => 'orphaned'], (int) $row['orphaned']),
            ]),
        ];
    }
}
