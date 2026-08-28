<?php

declare(strict_types=1);

namespace Storm\Telemetry;

use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Health\HealthCheckResult;
use Storm\Telemetry\Health\HealthStatus;
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
     */
    public function __construct(
        #[AutowireIterator('storm.health_check')]
        private iterable $checks,
    ) {}

    /**
     * Run every registered check, return the aggregate.
     *
     * @return array{status: HealthStatus, checks: array<string, HealthCheckResult>}
     */
    public function runAll(): array
    {
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
                $result = $check->check();
            } catch (Throwable $e) {
                // class only, never the message: this result is serialized into an HTTP body
                $result = HealthCheckResult::down('unhandled '.$e::class);
            }

            $results[$name] = $result;
            $overall = $this->worstOf($overall, $result->status);
        }

        return ['status' => $overall, 'checks' => $results];
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
