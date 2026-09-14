<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Whether the relays can still publish: the pending rows of both outboxes that failed a publish and
 * are being retried, the trace a broker outage leaves while the backlog checks still read Ok.
 *
 * A failed publish bumps `attempts` and records `last_error` on the row it withheld, on `es_outbox`
 * for the event relay and on `workflow_outbox` for the saga relay; the backlog checks read the age
 * of the oldest pending row and go Degraded only past their threshold, the accepted blind window,
 * and the relay's attempt budget dead-letters the row before that window closes. This check reads
 * the attempts instead: `Degraded` from the first pending row that spent one, naming how many on
 * each relay and the last error recorded, so an operator reads the outage, restores the broker and
 * replays what the budget dead-lettered meanwhile.
 *
 * Dead letters are the liveness checks' business; this one reads what is still being retried. An
 * absent `workflow_outbox` is `Ok` with its reason, the saga module being opt-in; an absent
 * `es_outbox` is `Degraded`, core schema that `storm:install` always creates. `Down` is reserved
 * for a failing query.
 */
final readonly class OutboxPublishHealthCheck implements HealthCheck
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function name(): string
    {
        return 'outbox_publish';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdicts read live outboxes, and the integration suite
     *                       proves them against real ones; the unit suite reaches this method only
     *                       through a kernel boot whose DSN names a closed port.
     */
    public function check(): HealthCheckResult
    {
        try {
            $tables = $this->connection->createSchemaManager();
            if (! $tables->tablesExist(['es_outbox'])) {
                return HealthCheckResult::degraded('es_outbox is absent — storm:install has not run against this database');
            }
            $events = $this->backingOff('es_outbox');
            $sagaInstalled = $tables->tablesExist(['workflow_outbox']);
            $commands = $sagaInstalled ? $this->backingOff('workflow_outbox') : ['count' => 0, 'error' => null];

            if ($events['count'] === 0 && $commands['count'] === 0) {
                return HealthCheckResult::ok($sagaInstalled
                    ? 'no pending row failed a publish on either outbox'
                    : 'no pending event row failed a publish; workflow_outbox is absent — the saga module is not installed on this database');
            }

            // each relay names its own count and its own last error: the two outboxes fail on
            // their own lanes, and one error must never hide the other
            $parts = [];
            if ($events['count'] > 0) {
                $parts[] = sprintf('%d event row(s) backing off after a failed publish (storm:outbox:relay), last error: %s', $events['count'], $events['error'] ?? 'not recorded');
            }
            if ($commands['count'] > 0) {
                $parts[] = sprintf('%d saga command(s) backing off after a failed dispatch (storm:saga:relay), last error: %s', $commands['count'], $commands['error'] ?? 'not recorded');
            }

            return HealthCheckResult::degraded(implode('; ', $parts).' — restore the broker, then replay what the attempt budget dead-lettered meanwhile');
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('outbox publish query failed ('.$e::class.')');
        }
    }

    /**
     * @return array{count: int, error: string|null}
     */
    private function backingOff(string $table): array
    {
        /** @var array{count: int|string, error: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            /* language=PostgreSQL */
            'SELECT count(*) AS count, (array_agg(last_error ORDER BY attempts DESC, id DESC))[1] AS error
             FROM '.$table." WHERE status = 'pending' AND attempts > 0",
        );

        return ['count' => $row === false ? 0 : (int) $row['count'], 'error' => $row === false ? null : $row['error']];
    }
}
