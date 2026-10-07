<?php

declare(strict_types=1);

namespace Storm\Telemetry;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Health\HealthCheckResult;
use Storm\Telemetry\Health\HealthStatus;
use Storm\Telemetry\Health\SqlHealthCheck;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Collects every tagged `HealthCheck` service and runs them on demand. Aggregates their individual
 * results into a single overall status using the worst-wins rule, where `Ok` is below `Degraded`
 * which is below `Down`:
 *
 * - Any `Down` makes the overall `Down`;
 * - Any `Degraded` without a `Down` makes it `Degraded`;
 * - Only all-`Ok` makes it `Ok`.
 *
 * No-check apps return `Ok` by vacuous truth; the response is `{"status": "ok", "checks": {}}`.
 *
 * Backstop catch around each check, `name()` included: even if an implementation throws despite the
 * interface's must-not-throw rule, it is marked `Down` and the others keep running. Only the
 * exception class is recorded, never the message, which may carry hosts, SQL or credentials and ends
 * up in an HTTP body. The caller never sees a partial response on a single bad probe.
 *
 * SQL probes declare their connections through `SqlHealthCheck`. Each statement is capped at
 * 250 ms by default inside a scope that is always rolled back, preserving caller transactions.
 * The 1000 ms collection budget stops admission of further probes and caps their SQL timeout
 * to the remaining budget. It cannot interrupt PHP callbacks, connection establishment or a
 * sequence of individually bounded statements. External probes must bound their own I/O.
 * Exhausted probes report `Down`, never an unobserved `Ok`.
 *
 * Names are defended, never trusted: a blank name, a name outside the `^[a-z][a-z0-9_-]*$` grammar
 * the contract calls stable and URL-safe, or a duplicate is a wiring bug, surfaced as a `Down` entry
 * under a disambiguated key. A duplicate must never silently overwrite an earlier result, which
 * could mask a `Down` behind a later `Ok` while the overall status still said `Down`.
 *
 * @see HealthCheck
 */
final readonly class HealthChecker
{
    /**
     * @param  iterable<HealthCheck>  $checks
     *
     * @throws InvalidArgumentException when either budget is not positive
     */
    public function __construct(
        #[AutowireIterator('storm.health_check')]
        private iterable $checks,
        private int $statementTimeoutMs = 250,
        /**
         * @infection-ignore-all clock-only: this default reaches nothing but the `hrtime` deadline, so
         *                       one millisecond more or less is invisible to a deterministic test
         */
        private int $collectionBudgetMs = 1000,
    ) {
        if ($statementTimeoutMs < 1 || $collectionBudgetMs < 1) {
            throw new InvalidArgumentException('Health budgets must be positive milliseconds.');
        }
    }

    /**
     * Run every registered check, return the aggregate.
     *
     * @return array{status: HealthStatus, checks: array<string, HealthCheckResult>}
     */
    public function runAll(): array
    {
        // clock-only, its factor left out of the profile by line: one off moves the deadline by a
        // microsecond per budget millisecond, which only a reading of `hrtime` could observe
        $deadline = hrtime(true) + $this->collectionBudgetMs * 1_000_000;
        $results = [];
        $overall = HealthStatus::Ok;

        foreach ($this->checks as $check) {
            try {
                $name = trim($check->name());
            } catch (Throwable $e) {
                $this->put($results, $check::class, HealthCheckResult::down('health check name() threw ('.$e::class.')'));
                $overall = HealthStatus::Down;

                continue;
            }

            if ($name === '') {
                $this->put($results, $check::class, HealthCheckResult::down('blank health check name'));
                $overall = HealthStatus::Down;

                continue;
            }

            // @infection-ignore-all equivalent: trim() above already strips a trailing newline, so
            // the D flag closing the `$`-before-newline hole is stated defense, not reachable behavior
            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
                // the contract says stable and URL-safe: "db status", "db/primary" or a control
                // character would be a legal JSON key but an unstable one for the dashboards,
                // metrics and greps the name exists for; refused here, where blank already is
                $this->put($results, $check::class, HealthCheckResult::down('health check name is not URL-safe (expected ^[a-z][a-z0-9_-]*$)'));
                $overall = HealthStatus::Down;

                continue;
            }

            if (isset($results[$name])) {
                // never overwrite: the first result stays; the duplication itself is the failure
                $this->put($results, $name.'@'.$check::class, HealthCheckResult::down(sprintf('duplicate health check name "%s" — rename one of the two', $name)));
                $overall = HealthStatus::Down;

                continue;
            }

            try {
                // clock-only, left out of the profile by line: a divisor one off, `round` or `ceil`
                // for `floor`, and `<= 1` for `< 1` differ only inside a sub-millisecond window
                $remainingMs = (int) floor(($deadline - hrtime(true)) / 1_000_000);
                $result = $remainingMs < 1
                    ? HealthCheckResult::down('health collection budget exhausted')
                    : $this->check($check, min($remainingMs, $this->statementTimeoutMs));
            } catch (Throwable $e) {
                // class only, never the message: this result is serialized into an HTTP body
                $result = HealthCheckResult::down('unhandled '.$e::class);
            }

            $results[$name] = $result;
            $overall = $this->worstOf($overall, $result->status);
        }

        return ['status' => $overall, 'checks' => $results];
    }

    private function check(HealthCheck $check, int $timeoutMs): HealthCheckResult
    {
        if (! $check instanceof SqlHealthCheck) {
            return $check->check();
        }

        $connections = [];
        foreach ($check->connections() as $connection) {
            $connections[spl_object_id($connection)] = $connection;
        }

        // @infection-ignore-all equivalent: `array_shift` below takes the first connection whatever
        // its key, so the list exists for the analyzer, not for the recursion
        return $this->withinConnections(array_values($connections), $check, $timeoutMs);
    }

    /**
     * @param  list<Connection>  $connections
     */
    private function withinConnections(array $connections, HealthCheck $check, int $timeoutMs): HealthCheckResult
    {
        $connection = array_shift($connections);
        if ($connection === null) {
            return $check->check();
        }

        $connection->beginTransaction();
        try {
            // Do not relax a stricter caller setting. Rollback restores it even after a swallowed timeout.
            // @infection-ignore-all equivalent on what this read can return: `pg_settings` renders an
            // integer setting as digits in its base unit, the unit in a column of its own, and a missing
            // row reads `false`; the comparison, `min()` and the `%d` below read such digits exactly as
            // their integer, and `false` as zero
            $priorMs = (int) $connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'statement_timeout'");
            $boundMs = $priorMs > 0 ? min($priorMs, $timeoutMs) : $timeoutMs;
            $connection->executeStatement(sprintf('SET LOCAL statement_timeout = %d', $boundMs));

            return $this->withinConnections($connections, $check, $timeoutMs);
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @param  array<string, HealthCheckResult>  $results
     */
    private function put(array &$results, string $key, HealthCheckResult $result): void
    {
        while (isset($results[$key])) {
            $key .= '+'; // two anonymous classes can share a FQCN-ish key; never lose an entry
        }

        $results[$key] = $result;
    }

    private function worstOf(HealthStatus $a, HealthStatus $b): HealthStatus
    {
        return match (true) {
            $a === HealthStatus::Down || $b === HealthStatus::Down => HealthStatus::Down,
            $a === HealthStatus::Degraded || $b === HealthStatus::Degraded => HealthStatus::Degraded,
            default => HealthStatus::Ok,
        };
    }
}
