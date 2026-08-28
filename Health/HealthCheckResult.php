<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

/**
 * The result of one `HealthCheck::check()` call: a status and an optional human-readable detail.
 * Persists no state itself; each invocation builds a fresh result.
 *
 * @see HealthCheck::check()
 */
final readonly class HealthCheckResult
{
    public function __construct(
        public HealthStatus $status,
        /** Free-text detail for ops: the failure message on `Down`, a hint on `Degraded`. */
        public ?string $message = null,
    ) {}

    public static function ok(?string $message = null): self
    {
        return new self(HealthStatus::Ok, $message);
    }

    public static function degraded(string $message): self
    {
        return new self(HealthStatus::Degraded, $message);
    }

    public static function down(string $message): self
    {
        return new self(HealthStatus::Down, $message);
    }
}
