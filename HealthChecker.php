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
 * Runs every tagged `HealthCheck` on demand and folds their results into one overall status.
 *
 * The worst status wins, `Ok` below `Degraded` below `Down`:
 *
 * - Any `Down` makes the overall `Down`
 * - Any `Degraded` without a `Down` makes it `Degraded`
 * - Only all-`Ok` makes it `Ok`
 *
 * An app with no check returns `Ok` by vacuous truth: `{"status": "ok", "checks": {}}`.
 *
 * Each check runs under a backstop catch, `name()` included. A check that throws despite the
 * interface's must-not-throw rule is marked `Down` and the others keep running, so one bad probe
 * never yields a partial response. Only the exception class is recorded, never its message, which may
 * carry hosts, SQL or credentials and ends up in an HTTP body.
 *
 * Time is bounded at two levels:
 *
 * - An SQL probe declares its connections through `SqlHealthCheck`, and each statement is capped at
 *   250 ms by default, inside a scope that is always rolled back so caller transactions survive.
 *
 * - The 1000 ms collection budget stops admitting further probes and caps their SQL timeout to what
 *   remains. It cannot interrupt PHP callbacks, connection establishment or a sequence of
 *   individually bounded statements, so an external probe must bound its own I/O.
 *
 * A probe that finds the budget exhausted reports `Down`, never an unobserved `Ok`.
 *
 * A blank name, a name outside the stable, URL-safe `^[a-z][a-z0-9_-]*$` grammar of the contract,
 * or a duplicate is a wiring bug, reported as a `Down` entry under a disambiguated key. A duplicate
 * must never overwrite an earlier result in silence, which could hide a `Down` behind a later `Ok`
 * while the overall status still said `Down`.
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

            // equivalent: trim() above already strips a trailing newline, so
            // the D flag closing the `$`-before-newline hole is stated defense, not reachable behavior
            // @infection-ignore-all
            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
                // "db status", "db/primary" or a control character is a legal JSON key, but not the
                // stable, URL-safe name dashboards, metrics and greps need; refused like a blank one
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

        // equivalent: `array_shift` below takes the first connection whatever
        // its key, so the list exists for the analyzer, not for the recursion
        // @infection-ignore-all
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
            // equivalent on what this read can return: `pg_settings` renders an
            // integer setting as digits in its base unit, the unit in a column of its own, and a missing
            // row reads `false`; the comparison, `min()` and the `%d` below read such digits exactly as
            // their integer, and `false` as zero
            // @infection-ignore-all
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
