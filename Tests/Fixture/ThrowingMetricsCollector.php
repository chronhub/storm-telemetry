<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Fixture;

use Storm\Telemetry\Metrics\MetricsCollector;
use Throwable;

/**
 * A collector whose block always fails, carrying a stable class name so the error gauge's label can
 * be asserted verbatim; an anonymous class names itself after the file and a counter, which is the
 * one thing that label must never become.
 */
final readonly class ThrowingMetricsCollector implements MetricsCollector
{
    public function __construct(
        private Throwable $failure,
    ) {}

    public function families(): array
    {
        throw $this->failure;
    }
}
