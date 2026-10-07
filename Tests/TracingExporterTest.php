<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\Tracing\DeferredSpanProcessor;
use Storm\Telemetry\Tracing\TracingFactory;

final class TracingExporterTest extends TestCase
{
    #[Test]
    public function batch_full_does_not_trigger_inline_io(): void
    {
        $exporter = new InMemoryExporter;
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true, batchSize: 1);
        $provider = new TracerProvider(TracingFactory::spanProcessor($processor));
        $provider->getTracer('storm-test')->spanBuilder('handler')->startSpan()->end();

        self::assertCount(0, $exporter->getSpans(), 'Ending a span inside business execution must never call the exporter.');
    }

    #[Test]
    public function queue_saturation_drops_without_exporting_and_flushes_bounded_batches(): void
    {
        $exporter = new InMemoryExporter;
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true, capacity: 2, batchSize: 1);
        $tracer = new TracerProvider(TracingFactory::spanProcessor($processor))->getTracer('storm-test');
        foreach (['first', 'second', 'overflow'] as $name) {
            $tracer->spanBuilder($name)->startSpan()->end();
        }
        self::assertSame(2, $processor->queued());
        self::assertSame(1, $processor->dropped());
        self::assertCount(0, $exporter->getSpans());
        self::assertTrue($processor->forceFlush());
        self::assertSame(['first', 'second'], array_map(static fn ($span) => $span->getName(), $exporter->getSpans()));
    }

    #[Test]
    public function shutdown_under_transaction_discards_without_exporting(): void
    {
        $exporter = new InMemoryExporter;
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => false);
        new TracerProvider(TracingFactory::spanProcessor($processor))->getTracer('storm-test')->spanBuilder('pending')->startSpan()->end();
        self::assertFalse($processor->shutdown());
        self::assertSame(0, $processor->queued());
        self::assertSame(1, $processor->dropped());
        self::assertCount(0, $exporter->getSpans());
        self::assertFalse($processor->forceFlush());
        self::assertFalse($processor->shutdown());
    }

    #[Test]
    public function failed_export_does_not_escape_or_retry_the_batch(): void
    {
        $exporter = new class() extends InMemoryExporter
        {
            public int $calls = 0;

            protected function doExport(iterable $spans): bool
            {
                $this->calls++;
                throw new RuntimeException('unavailable');
            }
        };
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true);
        new TracerProvider(TracingFactory::spanProcessor($processor))->getTracer('storm-test')->spanBuilder('pending')->startSpan()->end();
        self::assertFalse($processor->forceFlush());
        self::assertTrue($processor->forceFlush());
        self::assertSame(1, $exporter->calls);
        self::assertSame(1, $processor->dropped());
    }

    #[Test]
    public function a_new_transaction_between_batches_stops_the_drain(): void
    {
        $checks = 0;
        $exporter = new InMemoryExporter;
        $processor = new DeferredSpanProcessor($exporter, static function () use (&$checks): bool {
            return ++$checks === 1;
        }, capacity: 2, batchSize: 1);
        $tracer = new TracerProvider(TracingFactory::spanProcessor($processor))->getTracer('storm-test');
        $tracer->spanBuilder('first')->startSpan()->end();
        $tracer->spanBuilder('second')->startSpan()->end();
        self::assertFalse($processor->forceFlush());
        self::assertCount(1, $exporter->getSpans());
        self::assertSame(1, $processor->queued());
    }
}
