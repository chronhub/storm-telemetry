<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

/**
 * Three-level health status that `HealthCheck` implementations report and `HealthChecker` folds into
 * the overall `/_storm/health` response.
 *
 * The level order drives that aggregation:
 *
 * - Any `Down` check forces the overall to `Down`, and Kubernetes pulls the pod.
 * - Any `Degraded` check without a `Down` keeps the overall `Degraded`, a 200 that surfaces a warning.
 * - All `Ok` checks make the overall `Ok`.
 *
 * @see HealthCheck
 */
enum HealthStatus: string
{
    /** Fully operational. */
    case Ok = 'ok';

    /** Partially operational; the service still serves but a sub-component is slow or lagging. */
    case Degraded = 'degraded';

    /** Not operational; the service should be considered unhealthy and removed from rotation. */
    case Down = 'down';
}
