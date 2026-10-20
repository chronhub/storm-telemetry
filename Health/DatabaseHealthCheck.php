<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Pings the DBAL connection with a `SELECT 1`.
 *
 * It answers whether Postgres is reachable at all, the credentials work and the wire is up, and
 * nothing more. Replication lag, connection pool saturation or autovacuum statistics belong in
 * app-specific checks built on top.
 *
 * It ships out of the box because every Storm consumer uses DBAL, and registers through the
 * `storm.health_check` autoconfigure tag of the `HealthCheck` interface.
 */
final readonly class DatabaseHealthCheck implements SqlHealthCheck
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function connections(): iterable
    {
        return [$this->connection];
    }

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
            // class only, never the driver message: it carries host, port, database and SQL details
            // into the HTTP body; the full exception belongs to the failing component's own logs
            return HealthCheckResult::down('database unavailable ('.$e::class.')');
        }
    }
}
