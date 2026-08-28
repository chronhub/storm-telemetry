<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Pings the DBAL connection with a `SELECT 1`. Stays minimal on purpose; the goal is "can we reach
 * Postgres at all, are credentials OK, is the wire up". Anything fancier such as replication lag,
 * connection pool saturation, or autovacuum stats belongs in app-specific health checks built on top.
 *
 * Ships out of the box because every Storm consumer uses DBAL; auto-registered via the
 * `storm.health_check` autoconfigure tag on the `HealthCheck` interface.
 */
final readonly class DatabaseHealthCheck implements HealthCheck
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function name(): string
    {
        return 'database';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body, proven against a real Postgres in the integration suite.
     *                       The unit suite reaches it only through a kernel boot whose DSN names a
     *                       closed port, so every run here takes the catch and no test asserts which
     *                       verdict came back; the probe's own logic is unobservable from there.
     */
    public function check(): HealthCheckResult
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();

            return HealthCheckResult::ok();
        } catch (Throwable $e) {
            // class only, never the driver message: it carries host, port, database and SQL details,
            // and this result is serialized into the health endpoint's HTTP body. The full exception
            // belongs to the failing component's own logs.
            return HealthCheckResult::down('database unavailable ('.$e::class.')');
        }
    }
}
