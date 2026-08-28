<?php

declare(strict_types=1);

namespace Storm\Telemetry;

use Override;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * The no-throw boundary every Telemetry emission crosses: delegates to the wrapped PSR-3 logger and
 * absorbs anything it throws. Observability's cardinal rule, that the observer must not break the
 * observed, cannot be delegated to Monolog handler configuration: `StormObservability` logs inside
 * the event store's append transaction, and the saga history subscriber's backstop logs inside the
 * engine's post-commit dispatch, so a throwing logger would roll back a valid append or re-enter a
 * committed saga. This is the implementation-side half of the observability ports' FAIL-OPEN
 * contract; the ports already declare it, this class makes it true.
 *
 * A swallowed failure is deliberately NOT re-logged: the only channel available is the one that just
 * failed, and recursing into it would recreate the exact hazard this class removes.
 */
final class BestEffortLogger extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $inner,
    ) {}

    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        try {
            $this->inner->log($level, $message, $context);
        } catch (Throwable) {
            // dropped on purpose: no recursion into the failed channel
        }
    }
}
