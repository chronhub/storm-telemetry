<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

/**
 * Three-level health status used by `HealthCheck` implementations and aggregated by the
 * `HealthChecker` into the overall `/_storm/health` response. The level ordering matters for
 * aggregation:
 *
 * - Any `Down` check forces the overall to `Down`; Kubernetes will pull the pod.
 * - Any `Degraded` check without a `Down` keeps the overall `Degraded`; a 200 with a warning surface.
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
