<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Metrics;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\MetricsCollector;
use Storm\Telemetry\Metrics\MetricsExposition;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;
use Storm\Telemetry\Tests\Fixture\ThrowingMetricsCollector;

/**
 * The exposition's one guarantee: a scrape never fails whole. Healthy blocks concatenate; a
 * throwing collector is dropped and surfaces as an error gauge naming the block, never as a broken
 * body and never as silence.
 */
final class MetricsExpositionTest extends TestCase
{
    #[Test]
    public function healthy_collectors_concatenate_their_families(): void
    {
        $text = $this->expose([
            $this->collector([MetricFamily::gauge('storm_a', 'a', [new MetricSample([], 1)])]),
            $this->collector([MetricFamily::gauge('storm_b', 'b', [new MetricSample([], 2)])]),
        ]);

        self::assertStringContainsString("storm_a 1\n", $text);
        self::assertStringContainsString("storm_b 2\n", $text);
        self::assertStringNotContainsString('storm_telemetry_collector_errors', $text);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_throwing_collector_becomes_an_error_gauge_and_the_others_survive(): void
    {
        $text = $this->expose([
            new class() implements MetricsCollector
            {
                public function families(): array
                {
                    throw new RuntimeException('connection refused: db-host:5432 password=hunter2');
                }
            },
            $this->collector([MetricFamily::gauge('storm_b', 'b', [new MetricSample([], 2)])]),
        ]);

        self::assertStringContainsString("storm_b 2\n", $text);
        self::assertStringContainsString('storm_telemetry_collector_errors', $text);
        self::assertStringContainsString('error="RuntimeException"', $text);
        // only the class ever surfaces: the message may carry hosts, SQL or credentials, and this
        // body ends up served over HTTP
        self::assertStringNotContainsString('hunter2', $text);
    }

    #[Test]
    public function the_error_gauge_names_the_failed_block_and_counts_it_once(): void
    {
        // the whole line, verbatim: this is what Prometheus ingests, and each piece of it answers an
        // operator question. The label is the SHORT name, so an alert reads as the block that went
        // dark rather than a namespace; the value is one occurrence, so a sum over the family counts
        // failing collectors. A label reduced to a namespace, widened to the FQCN, or shifted by a
        // character still renders a plausible gauge, which is how a broken instrument stays quiet.
        $text = $this->expose([new ThrowingMetricsCollector(new RuntimeException('connection refused'))]);

        self::assertStringContainsString(
            'storm_telemetry_collector_errors{collector="ThrowingMetricsCollector",error="RuntimeException"} 1'."\n",
            $text,
        );
    }

    #[Test]
    #[Group('adversarial')]
    public function a_family_name_already_taken_is_dropped_and_counted_never_emitted_twice(): void
    {
        // two # HELP blocks for one name invalidate the WHOLE body for the Prometheus parser; the
        // first family keeps the name, the imitator is dropped and the error gauge says so
        $text = $this->expose([
            $this->collector([MetricFamily::gauge('storm_saga_instances', 'the real one', [new MetricSample([], 1)])]),
            $this->collector([
                MetricFamily::gauge('storm_saga_instances', 'the imitator', [new MetricSample([], 99)]),
                MetricFamily::gauge('storm_app_own', 'its own block survives', [new MetricSample([], 7)]),
            ]),
        ]);

        self::assertSame(1, substr_count($text, '# HELP storm_saga_instances '));
        self::assertStringContainsString("storm_saga_instances 1\n", $text);
        self::assertStringNotContainsString('storm_saga_instances 99', $text);
        self::assertStringContainsString("storm_app_own 7\n", $text, 'only the colliding family is dropped, not the collector');
        // the whole error sample: it NAMES the guilty collector, carries the token, counts one
        self::assertMatchesRegularExpression('/storm_telemetry_collector_errors\{collector="[^"]+",error="duplicate_family"\} 1\n/', $text);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_errors_family_name_itself_is_reserved_against_a_colliding_collector(): void
    {
        $text = $this->expose([
            new class() implements MetricsCollector
            {
                public function families(): array
                {
                    throw new RuntimeException('down');
                }
            },
            $this->collector([MetricFamily::gauge(MetricsExposition::ERRORS_FAMILY, 'a spoofed error gauge', [new MetricSample([], 0)])]),
        ]);

        self::assertSame(1, substr_count($text, '# HELP '.MetricsExposition::ERRORS_FAMILY.' '));
        self::assertStringContainsString('error="RuntimeException"', $text, 'the real error gauge survives the spoof');
    }

    #[Test]
    public function each_collector_runs_under_its_own_transaction_carrying_the_statement_timeout(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))->method('transactional')
            ->willReturnCallback(static fn (callable $fn): mixed => $fn());
        $connection->expects($this->exactly(2))->method('executeStatement')
            ->with('SET LOCAL statement_timeout = 250')->willReturn(0);

        $text = new MetricsExposition([
            $this->collector([MetricFamily::gauge('storm_a', 'a', [new MetricSample([], 1)])]),
            $this->collector([MetricFamily::gauge('storm_b', 'b', [new MetricSample([], 2)])]),
        ], new PrometheusTextRenderer, $connection, statementTimeoutMs: 250)->render();

        self::assertStringContainsString("storm_a 1\n", $text);
        self::assertStringContainsString("storm_b 2\n", $text);
    }

    #[Test]
    public function the_default_bound_is_five_seconds_and_a_bound_of_one_still_binds(): void
    {
        // the default is a wired fact: autowiring fills nothing, so the shipped value IS the scrape
        // budget every deployment gets; and 1 is the boundary the disable-check must not eat
        foreach ([[null, 'SET LOCAL statement_timeout = 5000'], [1, 'SET LOCAL statement_timeout = 1']] as [$timeout, $statement]) {
            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())->method('transactional')
                ->willReturnCallback(static fn (callable $fn): mixed => $fn());
            $connection->expects($this->once())->method('executeStatement')->with($statement)->willReturn(0);

            $collectors = [$this->collector([MetricFamily::gauge('storm_a', 'a', [new MetricSample([], 1)])])];
            $exposition = $timeout === null
                ? new MetricsExposition($collectors, new PrometheusTextRenderer, $connection)
                : new MetricsExposition($collectors, new PrometheusTextRenderer, $connection, statementTimeoutMs: $timeout);

            self::assertStringContainsString("storm_a 1\n", $exposition->render());
        }
    }

    #[Test]
    public function a_zero_timeout_disables_the_transactional_bound(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('transactional');
        $connection->expects($this->never())->method('executeStatement');

        $text = new MetricsExposition(
            [$this->collector([MetricFamily::gauge('storm_a', 'a', [new MetricSample([], 1)])])],
            new PrometheusTextRenderer,
            $connection,
            statementTimeoutMs: 0,
        )->render();

        self::assertStringContainsString("storm_a 1\n", $text);
    }

    /**
     * @param  list<MetricFamily>  $families
     */
    private function collector(array $families): MetricsCollector
    {
        return new readonly class($families) implements MetricsCollector
        {
            /**
             * @param  list<MetricFamily>  $families
             */
            public function __construct(
                private array $families,
            ) {}

            public function families(): array
            {
                return $this->families;
            }
        };
    }

    /**
     * @param  list<MetricsCollector>  $collectors
     */
    private function expose(array $collectors): string
    {
        // timeout 0: the unit surface tests composition; the transactional bound has its own tests
        return new MetricsExposition($collectors, new PrometheusTextRenderer, $this->createStub(Connection::class), statementTimeoutMs: 0)->render();
    }
}
