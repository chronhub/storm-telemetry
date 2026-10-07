<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tracing;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * A live operation whose observation cannot replace the business outcome.
 */
final class TraceOperation
{
    private ?int $endedAt = null;

    public function __construct(private ?SpanInterface $span = null, private ?ScopeInterface $scope = null) {}

    /**
     * @param  non-empty-string  $key
     */
    public function attribute(string $key, string|int|bool $value): void
    {
        // on an operation without a span, `->` throws inside this `try` and the catch swallows it: the
        // nullsafe is an equivalent mutant the gate's configuration leaves out on this line
        try {
            $this->span?->setAttribute($key, is_string($value) ? substr($value, 0, 256) : $value);
        } catch (Throwable) {
        }
    }

    /**
     * Detaches at the execution boundary while its outcome is still being classified.
     */
    public function pause(): void
    {
        try {
            $this->endedAt ??= Clock::getDefault()->now();
        } catch (Throwable) {
        }
        // the catch swallows every failure, so the `finally` reads the same as a plain statement after
        // it, and the nullsafe on an absent scope throws only into that catch: both are equivalent
        // mutants the gate's configuration leaves out, while dropping the `finally` or the call is killed
        try {
            $this->scope?->detach();
        } catch (Throwable) {
        } finally {
            $this->scope = null;
        }
    }

    /**
     * Adds origins discovered during execution, bounded independently of batch size.
     *
     * @param  iterable<array<string, string>>  $carriers
     */
    public function links(iterable $carriers): void
    {
        try {
            $count = 0;
            $omitted = 0;
            foreach ($carriers as $carrier) {
                if ($count >= 128) {
                    $omitted++;
                    continue;
                }
                $context = Span::fromContext(TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot()))->getContext();
                if ($context->isValid()) {
                    $this->span?->addLink($context);
                    $count++;
                }
            }
            if ($omitted > 0) {
                $this->attribute('storm.links.omitted', $omitted);
            }
        } catch (Throwable) {
        }
    }

    public function finish(?Throwable $failure = null): void
    {
        $this->pause();
        // every call on the span below runs inside a `try` whose catch swallows any failure, so each
        // nullsafe on an absent span, and the `finally` of the second block, are equivalent mutants the
        // gate's configuration leaves out line by line; the calls themselves are killed
        try {
            if ($failure !== null) {
                $this->span?->setAttribute('error.type', $failure::class);
                if ($failure->getPrevious() !== null) {
                    $this->span?->setAttribute('error.cause.type', $failure->getPrevious()::class);
                }
                $this->span?->setStatus(StatusCode::STATUS_ERROR);
            }
        } catch (Throwable) {
        }
        try {
            $this->span?->end($this->endedAt);
        } catch (Throwable) {
        } finally {
            $this->span = null;
        }
    }
}
