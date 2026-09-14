<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Whether every category has its own partition: the DEFAULT partition read for what it holds, the
 * state partitioning early exists to prevent.
 *
 * A category that lands in `event_store_default` grows there with every other such category, and
 * once it holds rows the partition command refuses the simple split and prints a manual migration
 * under an exclusive lock instead. `Degraded` from the first row the DEFAULT partition holds, naming
 * how many rows of which categories and the command; `Ok` when it is empty. An absent `event_store`
 * is `Degraded`, core schema that `storm:install` always creates. `Down` is reserved for a failing
 * query. `Degraded`, not `Down`: the store serves, and the migration waits for an off-peak window.
 */
final readonly class EventStorePartitionHealthCheck implements HealthCheck
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function name(): string
    {
        return 'event_store_partitions';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdicts read a live event store, and the integration
     *                       suite proves them against one; the unit suite reaches this method only
     *                       through a kernel boot whose DSN names a closed port.
     */
    public function check(): HealthCheckResult
    {
        try {
            if (! $this->connection->createSchemaManager()->tablesExist(['event_store'])) {
                return HealthCheckResult::degraded('event_store is absent — storm:install has not run against this database');
            }
            /** @var list<array{category: string, rows: int|string}> $held */
            $held = $this->connection->fetchAllAssociative(
                /* language=PostgreSQL */
                'SELECT category, count(*) AS rows FROM event_store_default GROUP BY category ORDER BY category',
            );
            if ($held === []) {
                return HealthCheckResult::ok('every category has its own partition, event_store_default is empty');
            }
            $rows = array_sum(array_map(static fn (array $row): int => (int) $row['rows'], $held));

            return HealthCheckResult::degraded(sprintf(
                '%d row(s) of %d categor%s live in event_store_default: %s — partition early; storm:event-store:partition prints the migration recipe for a category already there',
                $rows,
                count($held),
                count($held) === 1 ? 'y' : 'ies',
                implode(', ', array_column($held, 'category')),
            ));
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('event store partition query failed ('.$e::class.')');
        }
    }
}
