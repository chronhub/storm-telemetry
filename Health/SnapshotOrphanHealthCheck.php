<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Whether every snapshot still has its stream: an orphan is a snapshot whose stream head is gone,
 * a cache of a dead history that the read-side probe discards on load and that nothing signals
 * until then.
 *
 * `Degraded` from the first orphan, naming the count and `storm:snapshot:prune-orphans`, the
 * structural backstop; `Ok` when every snapshot has its head. An absent `snapshots` table is
 * `Degraded`, core schema that `storm:install` always creates. `Down` is reserved for a failing
 * query. A snapshot whose state lies at a version the head still holds is not read here: that is
 * `storm:snapshot:verify`, which refolds the stream to the snapshot's version and compares.
 */
final readonly class SnapshotOrphanHealthCheck implements HealthCheck
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function name(): string
    {
        return 'snapshot_orphans';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdicts read a live snapshots table, and the
     *                       integration suite proves them against one; the unit suite reaches this
     *                       method only through a kernel boot whose DSN names a closed port.
     */
    public function check(): HealthCheckResult
    {
        try {
            if (! $this->connection->createSchemaManager()->tablesExist(['snapshots'])) {
                return HealthCheckResult::degraded('snapshots is absent — storm:install has not run against this database');
            }
            $orphans = (int) $this->connection->fetchOne(
                /* language=PostgreSQL */
                'SELECT count(*) FROM snapshots s WHERE NOT EXISTS (SELECT 1 FROM stream_heads h WHERE h.stream = s.stream)',
            );
            if ($orphans === 0) {
                return HealthCheckResult::ok('every snapshot has its stream head');
            }

            return HealthCheckResult::degraded(sprintf('%d orphaned snapshot(s), a cache of a dead history — storm:snapshot:prune-orphans removes them', $orphans));
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('snapshot orphan query failed ('.$e::class.')');
        }
    }
}
