<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tracing;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;

/**
 * Starts bounded spans before work begins; completion only queues their data.
 */
final class ExecutionTracer
{
    private ?TracerInterface $tracer = null;

    public function __construct(private readonly TracerProviderInterface $provider) {}

    /**
     * Null parent inherits the active operation; an empty carrier starts a root.
     *
     * @param  non-empty-string  $name
     * @param  array<non-empty-string, string|int|bool>  $attributes
     * @param  array<string, string>|null  $parent
     * @param  iterable<array<string, string>>  $links
     * @param  0|1|2|3|4  $kind
     */
    public function start(string $name, array $attributes = [], ?array $parent = null, iterable $links = [], int $kind = SpanKind::KIND_INTERNAL): TraceOperation
    {
        $span = null;
        try {
            $tracer = $this->provider instanceof TracerProvider
                ? ($this->tracer ??= $this->provider->getTracer('storm'))
                : $this->provider->getTracer('storm');
            $builder = $tracer->spanBuilder($name)->setSpanKind($kind);
            if ($parent !== null) {
                $builder->setParent(TraceContextPropagator::getInstance()->extract($parent, context: Context::getRoot()));
            }
            $count = 0;
            $omitted = 0;
            foreach ($links as $carrier) {
                if ($count >= 128) {
                    $omitted++;
                    continue;
                }
                $context = Span::fromContext(TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot()))->getContext();
                if ($context->isValid()) {
                    $builder->addLink($context);
                    $count++;
                }
            }
            foreach ($attributes as $key => $value) {
                $builder->setAttribute($key, is_string($value) ? substr($value, 0, 256) : $value);
            }
            if ($omitted > 0) {
                $builder->setAttribute('storm.links.omitted', $omitted);
            }
            $span = $builder->startSpan();

            return new TraceOperation($span, $span->activate());
        } catch (Throwable) {
            try {
                // on a span never started, `->` throws inside this very `try`: the nullsafe is an
                // equivalent mutant the gate's configuration leaves out, removing the call being killed
                $span?->end();
            } catch (Throwable) {
            }

            return new TraceOperation;
        }
    }
}
