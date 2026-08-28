<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Storm\Telemetry\HealthChecker;

/**
 * One health probe: a small, named, side-effect-light check that the operator can run on demand via
 * `/_storm/health`. The framework ships a couple of generic checks such as a DBAL ping; the app adds
 * its own, for example RabbitMQ, Redis, a third-party API, or business invariants.
 *
 * Auto-tagged. The bundle's `loadExtension` registers `storm.health_check` as the autoconfigure tag
 * for every class implementing this interface, the same convention as Saga's `Activity` and
 * `Workflow`. Implement the interface and the `HealthChecker` picks the service up; no manual DI
 * declaration.
 *
 * Implementation rules:
 *
 * - `name()` must be stable and URL-safe; it is used as the key in the JSON response and operators
 *   grep it.
 *
 * - `check()` must be bounded; set a timeout if it hits IO, since the controller is sync. A check
 *   that takes 30s is itself a bug.
 *
 * - `check()` must not throw; catch every `Throwable` and return `HealthCheckResult::down()` with
 *   the message. The checker has a backstop catch, but doing it in the implementation is cleaner.
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
