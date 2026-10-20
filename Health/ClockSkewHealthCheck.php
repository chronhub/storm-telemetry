<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Throwable;

/**
 * Checks that the application's clock and the database's agree.
 *
 * The application mints the instants its timers are armed and claimed against, and the database
 * mints the heartbeats and the leases. When one host's time jumps or drifts, the two clocks act
 * against each other:
 *
 * - Saga timers fire early or late against every horizon.
 * - Ages and leases are read against a clock that moved.
 *
 * Nothing else names the jump: the timers fire, the sagas move, and every row looks legitimate.
 *
 * Its verdicts:
 *
 * - `Ok` names the agreement.
 * - `Degraded` from the threshold on, naming the direction and the seconds.
 * - `Down` only for a failing read.
 *
 * The threshold counts seconds of skew rather than a drift rate, so a host that drifts slowly crosses
 * it late, which a time daemon exists to prevent; a deployment whose tempos are seconds sets it
 * lower. Only true time says which host moved: the check reads the two clocks against each other and
 * blames neither.
 */
final readonly class ClockSkewHealthCheck implements SqlHealthCheck
{
    /**
     * @param  Clock<PointInTime>  $clock
     *
     * @infection-ignore-all the threshold's default is only ever observed by a `check()` against a
     *                       real database clock, so the integration suite is where a shifted value
     *                       shows; no unit caller reads it.
     */
    public function __construct(
        private Connection $connection,
        private Clock $clock,
        private int $degradedAfterSeconds = 5,
    ) {}

    public function connections(): iterable
    {
        return [$this->connection];
    }

    public function name(): string
    {
        return 'clock_skew';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdict reads the live database clock, and the
     *                       integration suite proves it against one; the unit suite reaches this
     *                       method only through a kernel boot whose DSN names a closed port.
     */
    public function check(): HealthCheckResult
    {
        try {
            $skew = ClockSkew::read($this->connection, $this->clock);
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('clock skew read failed ('.$e::class.')');
        }
        if (abs($skew) < $this->degradedAfterSeconds) {
            return HealthCheckResult::ok(sprintf('application and database clocks agree within %ds (skew %+ds)', $this->degradedAfterSeconds, $skew));
        }

        return HealthCheckResult::degraded(sprintf(
            'the application clock reads %ds %s the database clock (>= %ds) — one of the two hosts jumped or drifts, which one true time says: saga timers armed by the application fire early or late against it, storm:saga:timers claims by that clock; fix the host\'s time, then storm:saga:timers:audit for the timers armed beyond their tempo',
            abs($skew),
            $skew > 0 ? 'ahead of' : 'behind',
            $this->degradedAfterSeconds,
        ));
    }
}
