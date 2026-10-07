<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use InvalidArgumentException;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\Tracing\DeferredSpanProcessor;
use Storm\Telemetry\Tracing\ExportBudget;
use Storm\Telemetry\Tracing\TracingFactory;

/**
 * The queue's bounds, its budget and its reentrancy guards; the drain-boundary behavior itself is
 * pinned in `TracingExporterTest`.
 */
final class DeferredSpanProcessorTest extends TestCase
{
    #[Test]
    public function the_default_queue_holds_two_thousand_forty_eight_spans_and_drops_the_next(): void
    {
        $processor = new DeferredSpanProcessor(new InMemoryExporter, static fn (): bool => false);

        $this->endSpans($processor, 2049);

        self::assertSame([2048, 1], [$processor->queued(), $processor->dropped()]);
    }

    #[Test]
    public function the_default_batch_exports_two_hundred_fifty_six_spans_at_a_time(): void
    {
        $exporter = $this->recordingExporter();
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true);
        $this->endSpans($processor, 300);

        self::assertTrue($processor->forceFlush());
        self::assertSame([256, 44], $exporter->sizes);
    }

    #[Test]
    public function a_single_slot_queue_is_accepted_and_holds_one_span(): void
    {
        $processor = new DeferredSpanProcessor(new InMemoryExporter, static fn (): bool => false, capacity: 1, batchSize: 1);

        $this->endSpans($processor, 2);

        self::assertSame([1, 1], [$processor->queued(), $processor->dropped()]);
    }

    #[Test]
    public function a_batch_as_large_as_the_queue_is_accepted_and_exports_it_at_once(): void
    {
        $exporter = $this->recordingExporter();
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true, capacity: 2, batchSize: 2);
        $this->endSpans($processor, 2);

        self::assertTrue($processor->forceFlush());
        self::assertSame([2], $exporter->sizes);
    }

    #[Test]
    public function a_batch_larger_than_the_queue_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DeferredSpanProcessor(new InMemoryExporter, static fn (): bool => true, capacity: 2, batchSize: 3);
    }

    #[Test]
    public function an_empty_batch_is_refused_whatever_the_capacity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DeferredSpanProcessor(new InMemoryExporter, static fn (): bool => true, capacity: 10, batchSize: 0);
    }

    #[Test]
    public function a_span_ended_after_shutdown_is_dropped_not_queued(): void
    {
        $processor = new DeferredSpanProcessor(new InMemoryExporter, static fn (): bool => true);
        $tracer = new TracerProvider(TracingFactory::spanProcessor($processor))->getTracer('storm-test');
        $processor->shutdown();

        $tracer->spanBuilder('late')->startSpan()->end();

        self::assertSame([0, 1], [$processor->queued(), $processor->dropped()]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_span_ended_while_its_batch_exports_is_dropped_not_exported(): void
    {
        // the exporter's own instrumentation must never feed the drain it runs inside
        $exporter = new class() extends InMemoryExporter
        {
            public ?TracerInterface $tracer = null;

            protected function doExport(iterable $spans): bool
            {
                $tracer = $this->tracer;
                $this->tracer = null;
                $tracer?->spanBuilder('during-export')->startSpan()->end();

                return parent::doExport($spans);
            }
        };
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true);
        $tracer = new TracerProvider(TracingFactory::spanProcessor($processor))->getTracer('storm-test');
        $exporter->tracer = $tracer;
        $tracer->spanBuilder('first')->startSpan()->end();

        self::assertTrue($processor->forceFlush());
        self::assertSame(['first'], $this->names($exporter));
        self::assertSame(1, $processor->dropped());
    }

    #[Test]
    public function a_budget_spent_before_the_first_batch_exports_nothing(): void
    {
        // the clock stands at the start on its first reading and exactly on the deadline afterwards
        $readings = 0;
        $budget = new ExportBudget(1, static function () use (&$readings): int {
            return $readings++ === 0 ? 0 : 1_000_000;
        });
        $exporter = new InMemoryExporter;
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true, $budget);
        $this->endSpans($processor, 1);

        self::assertFalse($processor->forceFlush());
        self::assertSame([], $exporter->getSpans());
        self::assertSame(1, $processor->queued());
    }

    #[Test]
    public function a_refused_batch_adds_to_the_spans_already_dropped(): void
    {
        $exporter = new class() extends InMemoryExporter
        {
            protected function doExport(iterable $spans): bool
            {
                return false;
            }
        };
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true, capacity: 1, batchSize: 1);
        $this->endSpans($processor, 2);

        self::assertFalse($processor->forceFlush());
        self::assertSame(2, $processor->dropped());
    }

    #[Test]
    public function a_throwing_export_adds_to_the_spans_already_dropped(): void
    {
        $exporter = new class() extends InMemoryExporter
        {
            protected function doExport(iterable $spans): bool
            {
                throw new RuntimeException('unavailable');
            }
        };
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true, capacity: 1, batchSize: 1);
        $this->endSpans($processor, 2);

        self::assertFalse($processor->forceFlush());
        self::assertSame(2, $processor->dropped());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_shutdown_requested_during_a_drain_is_refused_and_the_drain_finishes(): void
    {
        // a shutdown hook firing mid-export must not close the queue under the drain and drop the
        // batches it has not reached yet
        $exporter = new class() extends InMemoryExporter
        {
            public ?DeferredSpanProcessor $processor = null;

            public ?bool $nested = null;

            protected function doExport(iterable $spans): bool
            {
                $this->nested ??= $this->processor?->shutdown();

                return parent::doExport($spans);
            }
        };
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true, capacity: 2, batchSize: 1);
        $exporter->processor = $processor;
        $this->endSpans($processor, 2);

        self::assertTrue($processor->forceFlush());
        self::assertFalse($exporter->nested);
        self::assertCount(2, $exporter->getSpans());
        self::assertSame(0, $processor->dropped());
    }

    #[Test]
    public function an_unsampled_span_is_neither_queued_nor_counted_as_dropped(): void
    {
        $processor = new DeferredSpanProcessor(new InMemoryExporter, static fn (): bool => true);
        $span = $this->endedSpan(sampled: false);
        $span->expects(self::never())->method('toSpanData');

        $processor->onEnd($span);

        self::assertSame([0, 0], [$processor->queued(), $processor->dropped()]);
    }

    #[Test]
    public function a_span_whose_data_cannot_be_read_is_counted_as_dropped(): void
    {
        $processor = new DeferredSpanProcessor(new InMemoryExporter, static fn (): bool => true);
        $span = $this->endedSpan(sampled: true);
        $span->expects(self::once())->method('toSpanData')->willThrowException(new RuntimeException('span data unavailable'));

        $processor->onEnd($span);

        self::assertSame([0, 1], [$processor->queued(), $processor->dropped()]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_safety_probe_that_throws_fails_that_drain_and_leaves_the_next_one_free(): void
    {
        // the failed drain keeps its span queued and releases the draining flag, so the next drain
        // exports it rather than being refused as reentrant
        $probes = 0;
        $exporter = new InMemoryExporter;
        $processor = new DeferredSpanProcessor($exporter, static function () use (&$probes): bool {
            if ($probes++ === 0) {
                throw new RuntimeException('connection probe failed');
            }

            return true;
        });
        $this->endSpans($processor, 1);

        self::assertFalse($processor->forceFlush());
        self::assertSame([1, 0], [$processor->queued(), $processor->dropped()]);
        self::assertTrue($processor->forceFlush());
        self::assertSame(['span-0'], $this->names($exporter));
    }

    private function endedSpan(bool $sampled): ReadableSpanInterface&MockObject
    {
        $span = $this->createMock(ReadableSpanInterface::class);
        $span->method('getContext')->willReturn(SpanContext::create(
            '0af7651916cd43dd8448eb211c80319c',
            'b7ad6b7169203331',
            $sampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT,
        ));

        return $span;
    }

    private function endSpans(DeferredSpanProcessor $processor, int $count): void
    {
        $tracer = new TracerProvider(TracingFactory::spanProcessor($processor))->getTracer('storm-test');

        for ($i = 0; $i < $count; $i++) {
            $tracer->spanBuilder('span-'.$i)->startSpan()->end();
        }
    }

    /**
     * @return InMemoryExporter&object{sizes: list<int>}
     */
    private function recordingExporter(): InMemoryExporter
    {
        return new class() extends InMemoryExporter
        {
            /** @var list<int> */
            public array $sizes = [];

            protected function doExport(iterable $spans): bool
            {
                $this->sizes[] = count(is_array($spans) ? $spans : iterator_to_array($spans, false));

                return parent::doExport($spans);
            }
        };
    }

    /**
     * @return list<string>
     */
    private function names(InMemoryExporter $exporter): array
    {
        return array_values(array_map(static fn (SpanDataInterface $span): string => $span->getName(), $exporter->getSpans()));
    }
}
