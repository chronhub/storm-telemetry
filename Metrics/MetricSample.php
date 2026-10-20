<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use InvalidArgumentException;

/**
 * One sample of a metric family: a label set and a value.
 *
 * The collector bounds its labels by construction, never a correlation id or another unbounded
 * dimension. The renderer escapes label values, which may carry any text, but label names are
 * grammar and never escaped, so they are validated at construction: the exposition's per-collector
 * backstop then turns an app collector's bad name into an error sample instead of a body the
 * Prometheus parser rejects whole. The `__` prefix, reserved by Prometheus, is refused too.
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
        $invalid = array_find(
            array_keys($labels),
            static fn (string|int $name): bool => preg_match(self::LABEL_GRAMMAR, (string) $name) !== 1 || str_starts_with((string) $name, '__'),
        );

        if ($invalid !== null) {
            throw new InvalidArgumentException(sprintf(
                'A label name must match the Prometheus grammar %s and not start with the reserved "__", got "%s".',
                self::LABEL_GRAMMAR,
                $invalid,
            ));
        }
    }
}
