<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tracing;

use Closure;
use InvalidArgumentException;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Throwable;

/**
 * Buffers sampled spans without calling the exporter from `onEnd`.
 *
 * It is not itself the SDK's span processor: `TracingFactory::spanProcessor()` adapts it, so the
 * class loads in an install that left the optional OpenTelemetry SDK out.
 *
 * Explicit drains discard failed batches. Shutdown discards any remainder, including spans
 * blocked by an open transaction. Tracing never retries business work or keeps a process alive.
 */
final class DeferredSpanProcessor
{
    /** @var list<SpanDataInterface> */
    private array $queue = [];

    private bool $closed = false;

    private bool $draining = false;

    private int $dropped = 0;

    /** @param Closure(): bool $safeToExport Checks every connection owned by the application. */
    public function __construct(
        private readonly SpanExporterInterface $exporter,
        private readonly Closure $safeToExport,
        private readonly ExportBudget $budget = new ExportBudget,
        private readonly int $capacity = 2048,
        private readonly int $batchSize = 256,
    ) {
        if ($capacity < 1 || $batchSize < 1 || $batchSize > $capacity) {
            throw new InvalidArgumentException('Trace queue capacity and batch size must be positive; batch size cannot exceed capacity.');
        }
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        if (! $span->getContext()->isSampled()) {
            return;
        }
        if ($this->closed || $this->draining || count($this->queue) >= $this->capacity) {
            $this->dropped++;

            return;
        }
        try {
            $this->queue[] = $span->toSpanData();
        } catch (Throwable) {
            $this->dropped++;
        }
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed || $this->draining) {
            return false;
        }
        $this->draining = true;
        $this->budget->start();
        $success = true;
        try {
            while ($this->queue !== []) {
                if ($this->budget->remainingSeconds() <= 0 || ! ($this->safeToExport)()) {
                    return false;
                }
                $batch = array_splice($this->queue, 0, $this->batchSize);
                try {
                    if (! $this->exporter->export($batch, $cancellation)->await()) {
                        $this->dropped += count($batch);
                        $success = false;
                    }
                } catch (Throwable) {
                    $this->dropped += count($batch);
                    $success = false;
                }
            }

            return $success;
        } catch (Throwable) {
            return false;
        } finally {
            $this->draining = false;
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed || $this->draining) {
            return false;
        }
        $success = $this->forceFlush($cancellation);
        $this->closed = true;
        $this->dropped += count($this->queue);
        $this->queue = [];

        return $success;
    }

    public function queued(): int
    {
        return count($this->queue);
    }

    public function dropped(): int
    {
        return $this->dropped;
    }
}
