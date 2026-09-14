<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Liveness of the event outbox relay itself, read from its heartbeat rather than from its backlog.
 *
 * `outbox_liveness` reads the age of the oldest pending row, so on a system with nothing to relay it
 * answers Ok whether the relay is alive or dead; a relay that died on a quiet night is discovered by
 * the first morning's backlog. This check reads `es_outbox_relay`, the row every drain upserts, and
 * degrades on silence:
 *
 * - No heartbeat at all: `Degraded`, the relay has never run against this database.
 * - The freshest heartbeat older than the threshold: `Degraded`, the relay stopped or is wedged.
 * - Otherwise `Ok`, carrying the age.
 *
 * The threshold is a multiple of the relay's poll, never its equal: a daemon at `--sleep=800` beats
 * about once a second when idle, a scheduler one-shot once per schedule, and the default of 300
 * seconds covers a schedule up to a few minutes with room for a recycle.
 *
 * `Degraded`, not `Down`: events are durably stored and the writers keep writing; a warning surface.
 * `Down` is reserved for a failing query. An absent table is an incomplete install, `Degraded` too.
 */
final readonly class OutboxRelayLivenessHealthCheck implements HealthCheck
{
    private const string TABLE = 'es_outbox_relay';

    /**
     * @infection-ignore-all the threshold's default is only ever observed by a `check()` against a
     *                       real heartbeat table, so the integration suite is where a shifted value
     *                       shows; no unit caller reads it.
     */
    public function __construct(
        private Connection $connection,
        private int $degradedAfterSeconds = 300,
    ) {}

    public function name(): string
    {
        return 'outbox_relay_liveness';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdicts read a live `es_outbox_relay`, and the
     *                       integration suite proves them against one; the unit suite reaches this
     *                       method only through a kernel boot whose DSN names a closed port.
     */
    public function check(): HealthCheckResult
    {
        try {
            if (! $this->connection->createSchemaManager()->tablesExist([self::TABLE])) {
                return HealthCheckResult::degraded('es_outbox_relay is absent — storm:install has not run against this database');
            }

            $age = $this->connection->fetchOne(
                /* language=PostgreSQL */
                'SELECT EXTRACT(EPOCH FROM (clock_timestamp() - max(ticked_at)))::int FROM es_outbox_relay',
            );

            if (! is_numeric($age)) {
                return HealthCheckResult::degraded('the outbox relay has never ticked — no heartbeat row; start storm:outbox:relay');
            }

            $age = (int) $age;

            if ($age < $this->degradedAfterSeconds) {
                return HealthCheckResult::ok(sprintf('last relay tick %ds ago', $age));
            }

            return HealthCheckResult::degraded(sprintf(
                'the outbox relay last ticked %ds ago (>= %ds) — it stopped or is wedged; a quiet outbox hides this from outbox_liveness',
                $age,
                $this->degradedAfterSeconds,
            ));
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('outbox relay liveness query failed ('.$e::class.')');
        }
    }
}
