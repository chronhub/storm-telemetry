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
 * the presence of rows and the partition command; `Ok` when it is empty. An absent `event_store`
 * is `Degraded`, core schema that `storm:install` always creates. `Down` is reserved for a failing
 * query. `Degraded`, not `Down`: the store serves, and the migration waits for an off-peak window.
 */
final readonly class EventStorePartitionHealthCheck implements SqlHealthCheck
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function connections(): iterable
    {
        return [$this->connection];
    }

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
            $present = $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM event_store_default)');
            if (! $present) {
                return HealthCheckResult::ok('every category has its own partition, event_store_default is empty');
            }

            return HealthCheckResult::degraded('event_store_default holds rows; storm:event-store:partition prints the migration recipe for a category already there');
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('event store partition query failed ('.$e::class.')');
        }
    }
}
