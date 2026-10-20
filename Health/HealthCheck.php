<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Storm\Telemetry\HealthChecker;

/**
 * One health probe: a small, named check with few side effects that an operator runs on demand
 * through `/_storm/health`.
 *
 * The framework ships a few generic checks such as a DBAL ping, and the app adds its own, for example
 * RabbitMQ, Redis, a third-party API or a business invariant.
 *
 * The bundle's `loadExtension` registers `storm.health_check` as the autoconfigure tag of every class
 * implementing this interface, the convention of Saga's `Activity` and `Workflow`: `HealthChecker`
 * picks up an implementing service with no manual DI declaration.
 *
 * Implementation rules:
 *
 * - `name()` must be stable and URL-safe: it keys the JSON response, and operators grep it.
 *
 * - `check()` must be bounded, with a timeout on any I/O, since the controller is synchronous; a
 *   check that takes 30s is itself a bug.
 *
 * - `check()` must not throw: catch every `Throwable` and return `HealthCheckResult::down()` with
 *   the message. The checker has a backstop catch, but the implementation is the cleaner place.
 *
 * @see HealthChecker
 */
interface HealthCheck
{
    /**
     * Stable identifier used as the key in the JSON response: `{"checks": {"<name>": ...}}`.
     */
    public function name(): string;

    /**
     * Probe the underlying component. Must not throw; wrap IO in a try/catch and return a `Down`
     * result with the failure message.
     */
    public function check(): HealthCheckResult;
}
