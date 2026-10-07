<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Whether the relays can still publish: the pending rows of both outboxes that failed a publish and
 * are being retried, the trace a broker outage leaves while the backlog checks still read Ok.
 *
 * A failed publish leaves the row it withheld with an attempt spent and its `last_error` recorded, on
 * `es_outbox` for the event relay and on `workflow_outbox` for the saga relay; the backlog checks read
 * the age of the oldest pending row and go Degraded only past their threshold, the accepted blind
 * window, and the attempt budget never dead-letters a row an outage withheld. This check reads the
 * attempts instead: `Degraded` from the first pending row that spent one, naming how many on each
 * relay and the last error recorded, so an operator reads the outage and restores the broker.
 *
 * The saga relay spends its attempt when it claims, before it publishes, so a spent attempt alone
 * does not name a failure there:
 *
 * - A claim whose lease still runs is a dispatch under way, and stays out of the count.
 * - A lapsed claim with no error recorded is a drain lost before its outcome, or one that outlasted
 *   its lease: counted apart, and never named a failed dispatch.
 *
 * Dead letters are the liveness checks' business; this one reads what is still being retried. An
 * absent `workflow_outbox` is `Ok` with its reason, the saga module being opt-in; an absent
 * `es_outbox` is `Degraded`, core schema that `storm:install` always creates. `Down` is reserved
 * for a failing query.
 */
final readonly class OutboxPublishHealthCheck implements SqlHealthCheck
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
            $events = $this->backingOff();
            $sagaInstalled = $tables->tablesExist(['workflow_outbox']);
            $commands = $sagaInstalled ? $this->sagaCommands() : ['count' => 0, 'lapsed' => 0, 'error' => null];

            if ($events['count'] === 0 && $commands['count'] === 0 && $commands['lapsed'] === 0) {
                return HealthCheckResult::ok($sagaInstalled
                    ? 'no pending row failed a publish on either outbox'
                    : 'no pending event row failed a publish; workflow_outbox is absent — the saga module is not installed on this database');
            }

            // each relay names its own count and its own last error: the two outboxes fail on
            // their own lanes, and one error must never hide the other
            $failures = [];
            if ($events['count'] > 0) {
                $failures[] = sprintf('%d event row(s) backing off after a failed publish (storm:outbox:relay), last error: %s', $events['count'], $events['error'] ?? 'not recorded');
            }
            if ($commands['count'] > 0) {
                $failures[] = sprintf('%d saga command(s) backing off after a failed dispatch (storm:saga:relay), last error: %s', $commands['count'], $commands['error'] ?? 'not recorded');
            }
            $parts = $failures === [] ? [] : [implode('; ', $failures).' — restore the broker, and the next relay tick publishes what the outage withheld'];
            // a lapsed claim recorded no failure, and nothing about it points at the broker
            if ($commands['lapsed'] > 0) {
                $parts[] = sprintf('%d saga command(s) whose claim lapsed with no outcome recorded (storm:saga:relay), a drain lost before it reported or one that outlasted its claim_lease_seconds; the next claim retakes them', $commands['lapsed']);
            }

            return HealthCheckResult::degraded(implode('; ', $parts));
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('outbox publish query failed ('.$e::class.')');
        }
    }

    /**
     * @return array{count: int, error: string|null}
     */
    private function backingOff(): array
    {
        /** @var array{count: int|string, error: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            /* language=PostgreSQL */
            "SELECT count(*) AS count, (array_agg(last_error ORDER BY attempts DESC, id DESC))[1] AS error
             FROM es_outbox WHERE status = 'pending' AND attempts > 0",
        );

        return ['count' => $row === false ? 0 : (int) $row['count'], 'error' => $row === false ? null : $row['error']];
    }

    /**
     * The saga commands whose claim is over, a claim in flight left out: `count` those a failed
     * dispatch left an error on, `lapsed` those whose claim ended with no outcome recorded. The last
     * error is read among the former alone, so a lapsed claim never hides it.
     *
     * @return array{count: int, lapsed: int, error: string|null}
     */
    private function sagaCommands(): array
    {
        /** @var array{count: int|string, lapsed: int|string, error: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            /* language=PostgreSQL */
            "SELECT count(*) FILTER (WHERE last_error IS NOT NULL) AS count,
                    count(*) FILTER (WHERE last_error IS NULL) AS lapsed,
                    (array_agg(last_error ORDER BY attempts DESC, id DESC) FILTER (WHERE last_error IS NOT NULL))[1] AS error
             FROM workflow_outbox
             WHERE status = 'pending' AND attempts > 0 AND (claimed_until IS NULL OR claimed_until <= clock_timestamp())",
        );

        return $row === false
            ? ['count' => 0, 'lapsed' => 0, 'error' => null]
            : ['count' => (int) $row['count'], 'lapsed' => (int) $row['lapsed'], 'error' => $row['error']];
    }
}
