<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Storm\Telemetry\HealthChecker;

/**
 * A read-only probe whose declared PostgreSQL connections are bounded by `HealthChecker`.
 *
 * The checker rolls back each probe scope even when the probe catches a database exception.
 * Writes inside that scope are discarded. Other connections and external I/O own their bounds.
 */
interface SqlHealthCheck extends HealthCheck
{
    /**
     * Declare every connection the probe reads without executing SQL.
     *
     * @return iterable<Connection>
     */
    public function connections(): iterable;
}
