<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use InvalidArgumentException;

/**
 * A named metric family and its samples, the unit the text renderer writes: one `# HELP` and
 * `# TYPE` header, then one line per sample.
 *
 * The name is validated against the Prometheus grammar at construction. An out-of-grammar name from
 * an app collector would make the parser reject the whole exposition body, so the refusal happens
 * here, where the exposition's per-collector backstop turns it into an error sample instead of a dead
 * endpoint.
 */
final readonly class MetricFamily
{
    /** The Prometheus metric-name grammar; a name outside it invalidates the whole scrape body. */
    public const string NAME_GRAMMAR = '/^[a-zA-Z_:][a-zA-Z0-9_:]*$/D';

    /**
     * @param  'gauge'|'counter'  $type
     * @param  list<MetricSample>  $samples
     *
     * @throws InvalidArgumentException when the name is outside the Prometheus grammar, or the type
     *                                  is neither `gauge` nor `counter`
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $help,
        public array $samples,
    ) {
        if (preg_match(self::NAME_GRAMMAR, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A metric family name must match the Prometheus grammar %s, got "%s".',
                self::NAME_GRAMMAR,
                $name,
            ));
        }

        // refused like the name: an out-of-union type would reach the renderer as an invalid
        // Prometheus `TYPE` line and invalidate the whole scrape body; the PHPDoc union is no runtime
        // guarantee, and this gate serves the callers the analyzer cannot see
        // @phpstan-ignore booleanAnd.alwaysFalse, notIdentical.alwaysFalse
        if ($type !== 'gauge' && $type !== 'counter') {
            throw new InvalidArgumentException(sprintf('A metric family type must be "gauge" or "counter", got "%s".', $type));
        }
    }

    /**
     * @param  list<MetricSample>  $samples
     */
    public static function gauge(string $name, string $help, array $samples): self
    {
        return new self($name, 'gauge', $help, $samples);
    }

    /**
     * A counter derived from an at-rest count may reset when its source is pruned; Prometheus
     * counter semantics absorb resets, so `rate()` and `increase()` stay correct.
     *
     * @param  list<MetricSample>  $samples
     */
    public static function counter(string $name, string $help, array $samples): self
    {
        return new self($name, 'counter', $help, $samples);
    }
}
