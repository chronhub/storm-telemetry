<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Health\HealthCheckResult;
use Storm\Telemetry\Health\HealthStatus;
use Storm\Telemetry\HealthChecker;
use Throwable;

final class HealthCheckerTest extends TestCase
{
    #[Test]
    public function returns_ok_with_no_registered_checks(): void
    {
        $aggregate = new HealthChecker([])->runAll();

        self::assertSame(HealthStatus::Ok, $aggregate['status']);
        self::assertSame([], $aggregate['checks']);
    }

    #[Test]
    public function aggregates_all_ok_to_ok(): void
    {
        $aggregate = new HealthChecker([
            $this->stub('db', HealthCheckResult::ok()),
            $this->stub('cache', HealthCheckResult::ok('warm')),
        ])->runAll();

        self::assertSame(HealthStatus::Ok, $aggregate['status']);
        self::assertSame(['db', 'cache'], array_keys($aggregate['checks']));
    }

    #[Test]
    public function any_degraded_makes_the_aggregate_degraded(): void
    {
        $aggregate = new HealthChecker([
            $this->stub('db', HealthCheckResult::ok()),
            $this->stub('queue', HealthCheckResult::degraded('lag 12s')),
        ])->runAll();

        self::assertSame(HealthStatus::Degraded, $aggregate['status']);
    }

    #[Test]
    public function any_down_makes_the_aggregate_down(): void
    {
        $aggregate = new HealthChecker([
            $this->stub('db', HealthCheckResult::ok()),
            $this->stub('queue', HealthCheckResult::down('broker unreachable')),
        ])->runAll();

        self::assertSame(HealthStatus::Down, $aggregate['status']);
    }

    #[Test]
    public function down_dominates_degraded(): void
    {
        $aggregate = new HealthChecker([
            $this->stub('queue', HealthCheckResult::degraded('lag')),
            $this->stub('db', HealthCheckResult::down('connection refused')),
        ])->runAll();

        self::assertSame(HealthStatus::Down, $aggregate['status']);
    }

    #[Test]
    public function a_throwing_check_becomes_down_without_leaking_its_message(): void
    {
        $aggregate = new HealthChecker([
            $this->stub('db', HealthCheckResult::ok()),
            $this->throwing('flaky', new RuntimeException('SQLSTATE[08006] host=prod-db user=secret')),
        ])->runAll();

        self::assertSame(HealthStatus::Down, $aggregate['status']);
        self::assertSame(HealthStatus::Down, $aggregate['checks']['flaky']->status);
        // class only: this message is serialized into an HTTP body, the raw text may carry secrets
        self::assertSame('unhandled RuntimeException', $aggregate['checks']['flaky']->message);
        self::assertStringNotContainsString('SQLSTATE', (string) $aggregate['checks']['flaky']->message);
        // The healthy check is still reported, with no short-circuit.
        self::assertSame(HealthStatus::Ok, $aggregate['checks']['db']->status);
    }

    #[Test]
    public function a_duplicate_name_never_overwrites_and_turns_the_endpoint_red(): void
    {
        // the trap: the first check is Down; a silent overwrite by the later Ok would mask it while
        // the overall status still said down, an incoherent response: a 503 with one green check
        $aggregate = new HealthChecker([
            $this->stub('duplicate', HealthCheckResult::down('poison')),
            $this->stub('duplicate', HealthCheckResult::ok()),
        ])->runAll();

        self::assertSame(HealthStatus::Down, $aggregate['status']);
        self::assertSame(HealthStatus::Down, $aggregate['checks']['duplicate']->status, 'the FIRST result stays');
        self::assertCount(2, $aggregate['checks'], 'the duplicate is exposed, never dropped');
        $other = array_values(array_filter(array_keys($aggregate['checks']), fn (string $k) => $k !== 'duplicate'))[0];
        self::assertStringContainsString('duplicate health check name', (string) $aggregate['checks'][$other]->message);
    }

    #[Test]
    public function a_name_that_throws_is_contained_and_the_others_still_run(): void
    {
        $badName = new readonly class() implements HealthCheck
        {
            public function name(): string
            {
                throw new RuntimeException('name exploded');
            }

            public function check(): HealthCheckResult
            {
                return HealthCheckResult::ok();
            }
        };

        $aggregate = new HealthChecker([$badName, $this->stub('db', HealthCheckResult::ok())])->runAll();

        self::assertSame(HealthStatus::Down, $aggregate['status']);
        self::assertCount(2, $aggregate['checks'], 'the endpoint answers completely — never a 500 for one bad probe');
        self::assertSame(HealthStatus::Ok, $aggregate['checks']['db']->status);
    }

    #[Test]
    public function a_blank_name_is_refused_as_down(): void
    {
        $blank = $this->stub('  ', HealthCheckResult::ok());

        $aggregate = new HealthChecker([$blank, $this->stub('db', HealthCheckResult::ok())])->runAll();

        self::assertSame(HealthStatus::Down, $aggregate['status']);
        self::assertSame(HealthStatus::Ok, $aggregate['checks']['db']->status);
        // the refusal is REPORTED, keyed by class since the name it offered is unusable: a body that
        // turns red without naming the offender leaves the operator to guess which probe to fix
        self::assertSame('blank health check name', (string) $aggregate['checks'][$blank::class]->message);
    }

    #[Test]
    public function a_name_that_is_not_url_safe_is_refused_as_down(): void
    {
        // a space, a slash, or a control character makes a legal JSON key but an unstable one for
        // the dashboards and greps the name contract exists for; each is refused, siblings still run
        foreach (['db status', 'db/primary', "db\nprimary", 'Db'] as $name) {
            $refused = $this->stub($name, HealthCheckResult::ok());

            $aggregate = new HealthChecker([$refused, $this->stub('db', HealthCheckResult::ok())])->runAll();

            self::assertSame(HealthStatus::Down, $aggregate['status'], sprintf('"%s" must be refused', $name));
            self::assertSame(HealthStatus::Ok, $aggregate['checks']['db']->status);
            self::assertSame(
                'health check name is not URL-safe (expected ^[a-z][a-z0-9_-]*$)',
                (string) $aggregate['checks'][$refused::class]->message,
                sprintf('"%s" must be reported under the offender, not merely counted', $name),
            );
        }
    }

    #[Test]
    public function a_third_check_sharing_one_name_and_one_class_still_lands(): void
    {
        // the disambiguated key is `name@class`, so a THIRD duplicate of the same anonymous class
        // collides with the disambiguation itself; the suffix loop is what keeps it. Losing it here
        // would hide a misconfiguration behind a report that silently counts one entry short
        $checks = [
            $this->stub('dup', HealthCheckResult::ok()),
            $this->stub('dup', HealthCheckResult::ok()),
            $this->stub('dup', HealthCheckResult::ok()),
        ];

        $aggregate = new HealthChecker($checks)->runAll();

        $this->assertSame(HealthStatus::Down, $aggregate['status']);
        $this->assertCount(3, $aggregate['checks'], 'never lose an entry: three registrations, three lines');

        foreach (array_keys($aggregate['checks']) as $key) {
            // the suffix EXTENDS the colliding key, never replaces it: a line whose key no longer
            // carries the name is an orphan, counted in the total and traceable to no registration
            $this->assertStringContainsString('dup', $key, 'every line still names the check it came from');
        }
    }

    private function stub(string $name, HealthCheckResult $result): HealthCheck
    {
        return new readonly class($name, $result) implements HealthCheck
        {
            public function __construct(private string $n, private HealthCheckResult $r) {}

            public function name(): string
            {
                return $this->n;
            }

            public function check(): HealthCheckResult
            {
                return $this->r;
            }
        };
    }

    private function throwing(string $name, Throwable $e): HealthCheck
    {
        return new readonly class($name, $e) implements HealthCheck
        {
            public function __construct(private string $n, private Throwable $e) {}

            public function name(): string
            {
                return $this->n;
            }

            public function check(): HealthCheckResult
            {
                throw $this->e;
            }
        };
    }
}
