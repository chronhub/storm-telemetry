<?php

declare(strict_types=1);

namespace Storm\Telemetry\FailureRegistry;

/**
 * Carries descriptive references; none of its fields authorize execution.
 */
final readonly class Stage
{
    /**
     * @param  array<string, string|int>  $details
     */
    public function __construct(public StageState $state, public array $details) {}
}
