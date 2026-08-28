<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Liveness AND delivery honesty of the transactional outbox. Two signals, worst first:
 *
 * - Dead letters: `failed` rows left the pending index for good; they will never be relayed
 *   without an operator running `storm:outbox:failed`, and they vanish from every pending-backlog
 *   metric at the exact moment they most need eyes. Any `failed` row is `Degraded`, with count and
 *   the oldest's age; "no pending rows" must never read as "everything was delivered".
 *
 * - Relay lag: the age of the oldest still-`pending` row, `clock_timestamp() - min(occurred_at)`.
 *   Whether the relay runs as a scheduler one-shot or a daemon, nothing else signals that it
 *   stopped, no worker is scheduled, or the broker is down and rows are piling up.
 *
 * `Degraded`, not `Down`: a lagging or dead-lettered outbox still serves, since events are durably
 * stored; a warning surface, not a pull-the-pod signal. `Down` is reserved for "can't even query".
 * An ABSENT table is `Degraded` too: `es_outbox` is core schema that `storm:install` always creates,
 * so absence is an incomplete install the writer will trip over, never "the feature is off".
 *
 * Reads `es_outbox` by name so Telemetry observes Chronicler's table without a type dependency, the
 * way `DatabaseHealthCheck` knows `SELECT 1`; the name mirrors Chronicler's `OutboxSchema`.
 *
 * Auto-registered via the `storm.health_check` autoconfigure tag on the `HealthCheck` interface.
 */
final readonly class OutboxLivenessHealthCheck implements HealthCheck
{
    private const string TABLE = 'es_outbox';

    /**
     * @infection-ignore-all the threshold's default is only ever observed by a `check()` against a
     *                       real outbox, so the integration suite is where a shifted value shows;
     *                       no unit caller reads it, and none honestly can.
     */
    public function __construct(
        private Connection $connection,
        private int $degradedAfterSeconds = 300,
    ) {}

    public function name(): string
    {
        return 'outbox_liveness';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdicts read a live `es_outbox`, and the integration
     *                       suite proves them against one. The unit suite reaches this method only
     *                       through a kernel boot whose DSN names a closed port, so every run here
     *                       takes the catch and no test asserts which verdict came back.
     */
    public function check(): HealthCheckResult
    {
        try {
            if (! $this->connection->createSchemaManager()->tablesExist([self::TABLE])) {
                // es_outbox is CORE schema, storm:install always creates it, so an absent table is
                // never "the feature is off": it is an incomplete install the writer will trip over.
                return HealthCheckResult::degraded('es_outbox is absent — storm:install has not run against this database');
            }

            /** @var array{oldest_pending: int|string|null, failed_count: int|string, oldest_failed: int|string|null}|false $row */
            $row = $this->connection->fetchAssociative(
                /** @lang PostgreSQL */
                "SELECT
                    (SELECT EXTRACT(EPOCH FROM (clock_timestamp() - min(occurred_at)))::int FROM es_outbox WHERE status = 'pending') AS oldest_pending,
                    (SELECT count(*) FROM es_outbox WHERE status = 'failed') AS failed_count,
                    (SELECT EXTRACT(EPOCH FROM (clock_timestamp() - min(failed_at)))::int FROM es_outbox WHERE status = 'failed') AS oldest_failed",
            );
            $oldestPending = ($row === false || $row['oldest_pending'] === null) ? null : (int) $row['oldest_pending'];
            $failedCount = $row === false ? 0 : (int) $row['failed_count'];
            $oldestFailed = ($row === false || $row['oldest_failed'] === null) ? null : (int) $row['oldest_failed'];

            $pendingLagging = $oldestPending !== null && $oldestPending >= $this->degradedAfterSeconds;

            // Dead letters first: a failed row left the pending index for good, so it is invisible to
            // every backlog metric precisely when it most needs an operator; inspect or replay via
            // storm:outbox:failed. "No pending" must never read as "all delivered".
            if ($failedCount > 0) {
                return HealthCheckResult::degraded(sprintf(
                    '%d dead-lettered outbox row(s), oldest %ds — inspect/replay via storm:outbox:failed%s',
                    $failedCount,
                    $oldestFailed ?? 0,
                    $pendingLagging ? sprintf('; oldest pending %ds (>= %ds, relay lagging or not running)', $oldestPending, $this->degradedAfterSeconds) : '',
                ));
            }

            if ($oldestPending === null) {
                return HealthCheckResult::ok('no pending outbox rows, no dead letters');
            }

            if (! $pendingLagging) {
                return HealthCheckResult::ok(sprintf('oldest pending %ds', $oldestPending));
            }

            return HealthCheckResult::degraded(sprintf(
                'oldest pending outbox row is %ds old (>= %ds) — the relay is lagging or not running',
                $oldestPending,
                $this->degradedAfterSeconds,
            ));
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('outbox liveness query failed ('.$e::class.')');
        }
    }
}
