<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tracing;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanLimitsBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Override;
use RuntimeException;
use Symfony\Component\HttpClient\CurlHttpClient;

final class TracingFactory
{
    /** @param Closure(): bool $safeToExport */
    public static function processor(string $endpoint, Closure $safeToExport, int $capacity, int $batchSize, int $budgetMs): DeferredSpanProcessor
    {
        // @infection-ignore-all; equivalent wherever this suite runs: every runner carries curl with
        // asynchronous DNS, so the check this call makes always passes
        self::assertTransportAvailable();
        $budget = new ExportBudget($budgetMs);
        $http = new DeadlineHttpClient(new CurlHttpClient, $budget);
        $factory = new Psr17Factory;
        // the transport gives up once its attempts reach `maxRetries`, so -1 retries exactly like 0: that
        // decrement is an equivalent mutant the gate's configuration leaves out, the increment beside it
        // being killed
        $transport = new PsrTransportFactory($http, $factory, $factory)->create(
            $endpoint, 'application/x-protobuf', maxRetries: 0,
        );

        return new DeferredSpanProcessor(new SpanExporter($transport), $safeToExport, $budget, $capacity, $batchSize);
    }

    public static function assertTransportAvailable(): void
    {
        // the arms that fire only without curl or without asynchronous DNS cannot run on any runner of
        // this suite: the gate's configuration leaves their mutants out on this line, and the negations
        // the suite does reach stay killed
        if (! extension_loaded('curl') || ((curl_version()['features'] ?? 0) & CURL_VERSION_ASYNCHDNS) === 0) {
            throw new RuntimeException('storm.tracing requires ext-curl with asynchronous DNS support for bounded HTTP export.');
        }
    }

    /**
     * The SDK span processor over `$processor`, declared only when this runs, so an install without
     * the optional SDK still loads every class of the package.
     */
    public static function spanProcessor(DeferredSpanProcessor $processor): SpanProcessorInterface
    {
        return new class($processor) implements SpanProcessorInterface
        {
            public function __construct(private readonly DeferredSpanProcessor $processor) {}

            // the processor buffers ended spans only; a started span has nothing to record yet
            #[Override]
            public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void {}

            #[Override]
            public function onEnd(ReadableSpanInterface $span): void
            {
                $this->processor->onEnd($span);
            }

            #[Override]
            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return $this->processor->forceFlush($cancellation);
            }

            #[Override]
            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return $this->processor->shutdown($cancellation);
            }
        };
    }

    public static function provider(DeferredSpanProcessor $processor, string $serviceName, float $sampleRatio): TracerProvider
    {
        return new TracerProvider(
            self::spanProcessor($processor),
            new ParentBased(new TraceIdRatioBasedSampler($sampleRatio)),
            ResourceInfo::create(Attributes::create(['service.name' => $serviceName])),
            new SpanLimitsBuilder()
                ->setAttributeCountLimit(64)
                ->setAttributeValueLengthLimit(256)
                ->setEventCountLimit(32)
                ->setLinkCountLimit(128)
                ->setAttributePerEventCountLimit(16)
                ->setAttributePerLinkCountLimit(8)
                ->build(),
        );
    }
}
