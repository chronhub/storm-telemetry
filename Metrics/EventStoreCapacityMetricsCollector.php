<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;

/**
 * Catalog-backed partition and database sizes, isolated from exhaustive row counting.
 *
 * Reports nothing when the event store is absent. A failed exact DEFAULT count belongs to
 * `EventStoreDefaultRowsMetricsCollector` and cannot suppress these capacity signals.
 */
final readonly class EventStoreCapacityMetricsCollector implements MetricsCollector
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @return list<MetricFamily>
     */
    public function families(): array
    {
        if (! $this->connection->createSchemaManager()->tablesExist(['event_store'])) {
            return [];
        }

        /** @var list<array{partition: string, bytes: int|string}> $partitions */
        $partitions = $this->connection->fetchAllAssociative(
            /* language=PostgreSQL */
            "SELECT c.relname AS partition, pg_total_relation_size(c.oid) AS bytes
             FROM pg_inherits i
             JOIN pg_class c ON c.oid = i.inhrelid
             JOIN pg_class p ON p.oid = i.inhparent
             JOIN pg_namespace n ON n.oid = p.relnamespace
             WHERE p.relname = 'event_store' AND n.nspname = current_schema()
             ORDER BY c.relname",
        );

        return [
            MetricFamily::gauge('storm_event_store_partition_bytes', 'Total size of each event store partition, indexes included', array_map(
                static fn (array $row): MetricSample => new MetricSample(['partition' => $row['partition']], (int) $row['bytes']),
                $partitions,
            )),
            MetricFamily::gauge('storm_database_bytes', 'Size of the current database, what a volume alert compares to its capacity', [
                new MetricSample([], (int) $this->connection->fetchOne('SELECT pg_database_size(current_database())')),
            ]),
        ];
    }
}
