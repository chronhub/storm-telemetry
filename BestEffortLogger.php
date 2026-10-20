<?php

declare(strict_types=1);

namespace Storm\Telemetry;

use Override;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * The no-throw boundary every Telemetry emission crosses: it delegates to the wrapped PSR-3 logger
 * and absorbs anything that logger throws.
 *
 * The observer must not break the observed, and Monolog handler configuration cannot guarantee it:
 *
 * - `StormObservability` logs inside the event store's append transaction, where a throwing logger
 *   would roll back a valid append.
 * - The saga history subscriber's backstop logs inside the engine's post-commit dispatch, where a
 *   throwing logger would re-enter a committed saga.
 *
 * The observability ports declare their fail-open contract; this class makes it true.
 *
 * A swallowed failure is not logged again: the only channel available is the one that just failed,
 * and recursing into it would bring back the hazard this class removes.
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
            // dropped: logging it would recurse into the failed channel
        }
    }
}
