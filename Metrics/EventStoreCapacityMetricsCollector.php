<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;

/**
 * The event store's capacity block: the size of each partition, what the DEFAULT partition holds
 * per category, and the size of the database, read from the catalog by table name.
 *
 * The store is never pruned by design, so growth is the one failure that is certain, and it
 * arrives per partition: a category left in `event_store_default` grows there, the state
 * partitioning early prevents, and a category with its own partition grows alone. The partition
 * sizes tell where the growth is, the DEFAULT rows per category tell who was never partitioned,
 * and the database size is the number a volume alert compares to its capacity. Reports nothing
 * when the store is not installed rather than failing the scrape.
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
        /** @var list<array{category: string, rows: int|string}> $held */
        $held = $this->connection->fetchAllAssociative(
            /* language=PostgreSQL */
            'SELECT category, count(*) AS rows FROM event_store_default GROUP BY category ORDER BY category',
        );

        return [
            MetricFamily::gauge('storm_event_store_partition_bytes', 'Total size of each event store partition, indexes included', array_map(
                static fn (array $row): MetricSample => new MetricSample(['partition' => $row['partition']], (int) $row['bytes']),
                $partitions,
            )),
            // a category here was never partitioned: its rows share the catch-all with every other
            // such category, and the command refuses to split them out once they are there
            MetricFamily::gauge('storm_event_store_default_rows', 'Rows the DEFAULT partition holds per category, the categories never partitioned', array_map(
                static fn (array $row): MetricSample => new MetricSample(['category' => $row['category']], (int) $row['rows']),
                $held,
            )),
            MetricFamily::gauge('storm_database_bytes', 'Size of the current database, what a volume alert compares to its capacity', [
                new MetricSample([], (int) $this->connection->fetchOne('SELECT pg_database_size(current_database())')),
            ]),
        ];
    }
}
