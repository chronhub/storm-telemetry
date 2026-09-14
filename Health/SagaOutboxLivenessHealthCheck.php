<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Liveness AND delivery honesty of the sagas' command outbox, the twin of `OutboxLivenessHealthCheck`
 * over `workflow_outbox`. Two signals, worst first:
 *
 * - Dead letters: `failed` rows are commands no relay will send again; they wait for an operator
 *   to read `storm:saga:inspect` and decide on `storm:saga:redrive`, and they vanish from every
 *   pending-backlog metric at the moment they most need eyes. Any `failed` row is `Degraded`.
 *
 * - Relay lag: the age of the oldest still-`pending` row, `clock_timestamp() - min(created_at)`.
 *   Nothing else says that `storm:saga:relay` stopped, that no worker is scheduled, or that the
 *   command bus is refusing; a saga whose command sits here does not advance.
 *
 * `published` rows are the command trail the settle pairs against and never count as backlog.
 * `Degraded`, not `Down`: the sagas' state is durably stored; a warning surface. `Down` is reserved
 * for a failing query. An ABSENT table is `Ok` with its reason: the saga module is opt-in, so
 * absence means it is not installed, unlike `es_outbox`, whose absence is an incomplete install.
 *
 * Reads `workflow_outbox` by name so Telemetry observes Saga's table without a type dependency, the
 * way the event outbox check knows `es_outbox`. Auto-registered via the `storm.health_check` tag.
 */
final readonly class SagaOutboxLivenessHealthCheck implements HealthCheck
{
    private const string TABLE = 'workflow_outbox';

    /**
     * @infection-ignore-all the threshold's default is only ever observed by a `check()` against a
     *                       real saga outbox, so the integration suite is where a shifted value
     *                       shows; no unit caller reads it.
     */
    public function __construct(
        private Connection $connection,
        private int $degradedAfterSeconds = 300,
    ) {}

    public function name(): string
    {
        return 'saga_outbox_liveness';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdicts read a live `workflow_outbox`, and the
     *                       integration suite proves them against one; the unit suite reaches this
     *                       method only through a kernel boot whose DSN names a closed port.
     */
    public function check(): HealthCheckResult
    {
        try {
            if (! $this->connection->createSchemaManager()->tablesExist([self::TABLE])) {
                return HealthCheckResult::ok('workflow_outbox is absent — the saga module is not installed on this database');
            }

            /** @var array{oldest_pending: int|string|null, failed_count: int|string, oldest_failed: int|string|null}|false $row */
            $row = $this->connection->fetchAssociative(
                /* language=PostgreSQL */
                "SELECT
                    (SELECT EXTRACT(EPOCH FROM (clock_timestamp() - min(created_at)))::int FROM workflow_outbox WHERE status = 'pending') AS oldest_pending,
                    (SELECT count(*) FROM workflow_outbox WHERE status = 'failed') AS failed_count,
                    (SELECT EXTRACT(EPOCH FROM (clock_timestamp() - min(processed_at)))::int FROM workflow_outbox WHERE status = 'failed') AS oldest_failed",
            );
            $oldestPending = ($row === false || $row['oldest_pending'] === null) ? null : (int) $row['oldest_pending'];
            $failedCount = $row === false ? 0 : (int) $row['failed_count'];
            $oldestFailed = ($row === false || $row['oldest_failed'] === null) ? null : (int) $row['oldest_failed'];

            $pendingLagging = $oldestPending !== null && $oldestPending >= $this->degradedAfterSeconds;

            if ($failedCount > 0) {
                return HealthCheckResult::degraded(sprintf(
                    '%d dead-lettered saga command(s), oldest %ds — inspect via storm:saga:inspect, resend via storm:saga:redrive%s',
                    $failedCount,
                    $oldestFailed ?? 0,
                    $pendingLagging ? sprintf('; oldest pending %ds (>= %ds, storm:saga:relay lagging or not running)', $oldestPending, $this->degradedAfterSeconds) : '',
                ));
            }

            if ($oldestPending === null) {
                return HealthCheckResult::ok('no pending saga commands, no dead letters');
            }

            if (! $pendingLagging) {
                return HealthCheckResult::ok(sprintf('oldest pending %ds', $oldestPending));
            }

            return HealthCheckResult::degraded(sprintf(
                'oldest pending saga command is %ds old (>= %ds) — storm:saga:relay is lagging or not running',
                $oldestPending,
                $this->degradedAfterSeconds,
            ));
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('saga outbox liveness query failed ('.$e::class.')');
        }
    }
}
