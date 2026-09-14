<?php

declare(strict_types=1);

namespace Storm\Telemetry\FailureRegistry;

/**
 * Groups the four operational stages of one failure class.
 */
final readonly class FailureClass
{
    /**
     * @param  array<string, Stage>  $stages
     */
    public function __construct(public string $id, public string $title, public array $stages) {}
}
