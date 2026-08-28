<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use InvalidArgumentException;

/**
 * One sample of a metric family: a label set and a value. Labels are bounded by construction on
 * the collector side, never a correlation id or another unbounded dimension. Label VALUES are
 * escaped by the renderer and may carry any text; label NAMES are grammar, never escaped, so
 * they are validated at construction, where the exposition's per-collector backstop turns an
 * app collector's bad name into an error sample instead of a body the Prometheus parser
 * rejects whole. The `__` prefix is reserved by Prometheus and refused too.
 */
final readonly class MetricSample
{
    /** The Prometheus label-name grammar; a name outside it invalidates the whole scrape body. */
    public const string LABEL_GRAMMAR = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

    /**
     * @param  array<string, string>  $labels
     *
     * @throws InvalidArgumentException when a label name is outside the Prometheus grammar or reserved
     */
    public function __construct(
        public array $labels,
        public int|float $value,
    ) {
        foreach (array_keys($labels) as $name) {
            if (preg_match(self::LABEL_GRAMMAR, (string) $name) !== 1 || str_starts_with((string) $name, '__')) {
                throw new InvalidArgumentException(sprintf(
                    'A label name must match the Prometheus grammar %s and not start with the reserved "__", got "%s".',
                    self::LABEL_GRAMMAR,
                    $name,
                ));
            }
        }
    }
}
