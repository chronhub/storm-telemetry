<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Whether every running projection's runner is still alive: a runner renews its lease every cycle,
 * idle cycles included, so a `running` projection whose lease has expired is a runner that stopped
 * without exiting or died without releasing, a freeze the lag cannot see, since the safe-head floor
 * is ratcheted by the runner's own tick.
 *
 * `Degraded` from the first expired lease under a running status, naming the projection, the age of
 * its last heartbeat and the recovery, the frozen process recycled first, since a lock it holds
 * inside a transaction blocks any takeover, then a second runner claiming the expired lease; `Ok` naming how
 * many run with a live lease, or that nothing runs. An absent `projections` table is `Degraded`,
 * core schema `storm:install` always creates. `Down` is reserved for a failing read. A projection
 * paused, idle or failed holds no lease and is not read here: `storm:projection:status` is its verb.
 */
final readonly class ProjectionRunnerLivenessHealthCheck implements HealthCheck
{
    /**
     * Lazy on purpose: the catalog stands on the projector's homes, the event store and its cipher
     * key behind them, and a kernel that only describes the health checks by name, the ApiOps
     * describe surface, must never boot that graph; it materializes at the first `check()`.
     */
    public function __construct(
        #[Autowire(lazy: true)]
        private ProjectionCatalog $catalog,
    ) {}

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
            // other failure, the lease reads included, is Down, never an escape into the HTTP body
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
