<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

/**
 * Renders metric families to the Prometheus text exposition format, version 0.0.4: per family one
 * `# HELP` and one `# TYPE` line, then one `name{label="value"} 123` line per sample. Hand-written
 * on purpose: the format is a handful of lines and emitting it directly keeps the package free of
 * a client-library dependency.
 *
 * A family with no samples is skipped whole, so an empty block never emits orphan headers. Label
 * values are escaped per the spec:
 *
 * - Backslash to `\\`
 * - Double quote to `\"`
 * - Newline to `\n`
 */
final readonly class PrometheusTextRenderer
{
    /**
     * @param  list<MetricFamily>  $families
     */
    public function render(array $families): string
    {
        $out = '';

        foreach ($families as $family) {
            if ($family->samples === []) {
                continue;
            }

            $out .= sprintf("# HELP %s %s\n", $family->name, $family->help);
            $out .= sprintf("# TYPE %s %s\n", $family->name, $family->type);

            foreach ($family->samples as $sample) {
                $out .= $family->name.$this->labels($sample->labels).' '.$this->value($sample->value)."\n";
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $name => $value) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
            $pairs[] = sprintf('%s="%s"', $name, $escaped);
        }

        return '{'.implode(',', $pairs).'}';
    }

    private function value(int|float $value): string
    {
        // the format spells non-finite values as Go tokens; PHP's INF and NAN casts would emit
        // words the parser rejects, killing the whole body over one sample
        return match (true) {
            is_float($value) && is_nan($value) => 'NaN',
            is_float($value) && is_infinite($value) => $value === INF ? '+Inf' : '-Inf',
            default => (string) $value,
        };
    }
}
