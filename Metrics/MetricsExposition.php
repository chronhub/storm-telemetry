<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Composes every tagged `MetricsCollector` into one text exposition, the single body the console
 * twin prints and the HTTP surface serves.
 *
 * Backstop catch around each collector: a scrape must never fail whole on one bad block, so a
 * throwing collector is dropped from the output and counted in `storm_telemetry_collector_errors`,
 * labeled by collector short name. Only the class is recorded, never the message, which may carry
 * hosts or SQL and ends up in an HTTP body; the healthy blocks keep reporting, and the error gauge
 * is the honest trace that a block went dark, where silence would read as "nothing to report".
 *
 * Each collector runs inside its own short transaction carrying `SET LOCAL statement_timeout`:
 * the collectors are aggregates over live operational tables, some growing with throughput, and a
 * catch guards a throw, not a slow query; without the bound a frequent scrape against a large
 * table becomes standing load on the production database while each scrape holds a PHP worker. A
 * timeout surfaces through the same error gauge, the honest signal that a block is too expensive.
 * The bound covers this connection only; a collector reading elsewhere owns its own bound.
 *
 * Two collectors emitting one family name would produce two `# HELP` blocks for one metric, which
 * the Prometheus parser rejects for the whole body; the first family keeps the name, later ones
 * are dropped and counted under the `duplicate_family` error token.
 */
final readonly class MetricsExposition
{
    /** The errors gauge's own family name, reserved against collector collisions. */
    public const string ERRORS_FAMILY = 'storm_telemetry_collector_errors';

    /** The `error` label value counting a family dropped for reusing an already-emitted name. */
    public const string DUPLICATE_FAMILY = 'duplicate_family';

    public function __construct(
        /** @var iterable<MetricsCollector> */
        #[AutowireIterator('storm.metrics_collector')]
        private iterable $collectors,
        private PrometheusTextRenderer $renderer,
        private Connection $connection,
        /** Per-collector statement bound in milliseconds; 0 disables it. */
        private int $statementTimeoutMs = 5000,
    ) {}

    public function render(): string
    {
        return $this->renderer->render($this->collectFamilies());
    }

    /**
     * Every family this scrape yields, bounded and de-duplicated, ahead of any rendering.
     *
     * Separate from `render()` because more than one surface answers from these numbers and only
     * one of them speaks the text exposition format. What a second reader must NOT do is walk the
     * collectors itself: the per-collector statement bound and the swallow-into-an-error-sample
     * both live here, and a copy of them is free to drift into an ops read that hangs, or into one
     * that loses a whole block in silence.
     *
     * @return list<MetricFamily>
     */
    public function collectFamilies(): array
    {
        $families = [];
        $errors = [];
        // @infection-ignore-all equivalent: $seen is an isset()-tested key set; the values are
        // never read, so any value under the key behaves identically
        $seen = [self::ERRORS_FAMILY => true];

        foreach ($this->collectors as $collector) {
            $short = substr(strrchr($collector::class, '\\') ?: '\\'.$collector::class, 1);

            try {
                $collected = $this->collect($collector);
            } catch (Throwable $e) {
                $errors[] = new MetricSample(['collector' => $short, 'error' => $e::class], 1);

                continue;
            }

            foreach ($collected as $family) {
                if (isset($seen[$family->name])) {
                    $errors[] = new MetricSample(['collector' => $short, 'error' => self::DUPLICATE_FAMILY], 1);

                    continue;
                }

                // @infection-ignore-all equivalent: an isset()-tested key set, the value is never read
                $seen[$family->name] = true;
                $families[] = $family;
            }
        }

        if ($errors !== []) {
            $families[] = MetricFamily::gauge(
                self::ERRORS_FAMILY,
                'Collectors that threw during this scrape, or emitted a family name already taken; the block or family is missing from the output',
                $errors,
            );
        }

        return $families;
    }

    /**
     * @return list<MetricFamily>
     *
     * @throws Throwable whatever the collector or the timeout raises, for the caller's backstop
     */
    private function collect(MetricsCollector $collector): array
    {
        if ($this->statementTimeoutMs < 1) {
            return $collector->families();
        }

        return $this->connection->transactional(function () use ($collector): array {
            // SET LOCAL scopes the bound to this transaction; commit and rollback both revert it
            $this->connection->executeStatement(sprintf('SET LOCAL statement_timeout = %d', $this->statementTimeoutMs));

            return $collector->families();
        });
    }
}
