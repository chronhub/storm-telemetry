<?php

declare(strict_types=1);

namespace Storm\Telemetry\FailureRegistry;

/**
 * Distinguishes documented capabilities from explicit operational gaps.
 */
enum StageState: string
{
    case Available = 'available';
    case Hole = 'hole';
    case NotApplicable = 'not_applicable';
}
