<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Checks that every running projection's runner is still alive.
 *
 * A runner renews its lease every cycle, idle cycles included, so a `running` projection whose lease
 * expired has a runner that stopped without exiting or died without releasing. The lag cannot see
 * that freeze, since the runner's own tick ratchets the safe-head floor.
 *
 * Its verdicts:
 *
 * - `Degraded` from the first expired lease under a running status, naming the projection, the age
 *   of its last heartbeat and the recovery: recycle the frozen process first, since a lock it holds
 *   inside a transaction blocks any takeover, then let a second runner claim the expired lease.
 *
 * - `Ok` naming how many run with a live lease, or that nothing runs.
 *
 * - `Degraded` for an absent `projections` table, core schema that `storm:install` always creates.
 *
 * - `Down` only for a failing read.
 *
 * A paused, idle or failed projection holds no lease and is left to `storm:projection:status`.
 */
final readonly class ProjectionRunnerLivenessHealthCheck implements SqlHealthCheck
{
    /**
     * Takes the catalog lazily, materialized at the first `check()`.
     *
     * The catalog depends on the projector's homes, with the event store and its cipher key behind
     * them, and a kernel that only describes the health checks by name, the ApiOps describe surface,
     * must never boot that graph. A manually composed SQL catalog must supply its connection to take
     * part in the SQL bounds.
     */
    public function __construct(
        #[Autowire(lazy: true)]
        private ProjectionCatalog $catalog,
        #[Autowire(service: 'storm.read_model_store_connection')]
        private ?Connection $connection = null,
    ) {}

    public function connections(): iterable
    {
        return $this->connection === null ? [] : [$this->connection];
    }

    public function name(): string
    {
        return 'projection_runner_liveness';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdicts read a live `projections` table, and the
     *                       integration suite proves them against one; the unit suite reaches this
     *                       method only through a kernel boot whose DSN names a closed port.
     */
    public function check(): HealthCheckResult
    {
        try {
            $running = 0;
            $frozen = [];
            foreach ($this->catalog->all() as $row) {
                if ($row->status !== ProjectionStatus::Running) {
                    continue;
                }
                $running++;
                if ($this->catalog->hasLiveLease($row->name)) {
                    continue;
                }
                $age = $row->lastHeartbeatAt === null ? null : max(0, time() - (int) strtotime($row->lastHeartbeatAt));
                $frozen[] = sprintf('%s (lease expired, last heartbeat %s)', $row->name, $age === null ? 'never' : $age.'s ago');
            }
        } catch (Throwable $e) {
            // an absent table surfaces as a driver exception: read the class, never the message; any
            // other failure, the lease reads included, is `Down`, never an escape into the HTTP body
            return str_contains($e::class, 'TableNotFound')
                ? HealthCheckResult::degraded('projections is absent — storm:install has not run against this database')
                : HealthCheckResult::down('projection runner liveness read failed ('.$e::class.')');
        }
        if ($frozen !== []) {
            return HealthCheckResult::degraded(sprintf(
                '%d running projection(s) let their lease expire — the runner stopped without exiting or died without releasing: %s; recycle the frozen process or terminate its backend first, a lock it holds inside a transaction blocking any takeover, then storm:projection:run <name> --drain claims the expired lease and catches up',
                count($frozen),
                implode('; ', $frozen),
            ));
        }

        return HealthCheckResult::ok($running === 0 ? 'no projection running' : sprintf('%d running, every lease live', $running));
    }
}
