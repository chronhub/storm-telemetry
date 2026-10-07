<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactoryInterface;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\TracerConfig;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\Tracing\ExecutionTracer;

final class ExecutionTracerTest extends TestCase
{
    #[Test]
    public function sdk_scope_is_resolved_once_for_repeated_operations(): void
    {
        $factory = $this->createMock(InstrumentationScopeFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('storm', null, null, [])
            ->willReturn(new InstrumentationScopeFactory(Attributes::factory())->create('storm'));
        $provider = new TracerProvider(sampler: new AlwaysOffSampler, instrumentationScopeFactory: $factory);
        $execution = new ExecutionTracer($provider);
        for ($i = 0; $i < 3; $i++) {
            $operation = $execution->start('operation', parent: []);
            self::assertTrue(Span::getCurrent()->getContext()->isValid());
            self::assertFalse(Span::getCurrent()->isRecording());
            $operation->finish();
            self::assertFalse(Span::getCurrent()->getContext()->isValid());
        }
        $provider->shutdown();
    }

    #[Test]
    public function failed_sdk_resolution_can_be_retried(): void
    {
        $factory = $this->createMock(InstrumentationScopeFactoryInterface::class);
        $attempts = 0;
        $factory->expects(self::exactly(2))->method('create')->willReturnCallback(static function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new RuntimeException('temporary failure');
            }

            return new InstrumentationScopeFactory(Attributes::factory())->create('storm');
        });
        $provider = new TracerProvider(instrumentationScopeFactory: $factory);
        $execution = new ExecutionTracer($provider);
        $execution->start('failed')->finish();
        for ($i = 0; $i < 2; $i++) {
            $operation = $execution->start('recovered');
            self::assertTrue(Span::getCurrent()->isRecording());
            $operation->finish();
        }
        $provider->shutdown();
    }

    #[Test]
    public function cached_sdk_tracer_observes_shutdown(): void
    {
        $provider = new TracerProvider;
        $execution = new ExecutionTracer($provider);
        $operation = $execution->start('before');
        self::assertTrue(Span::getCurrent()->isRecording());
        $operation->finish();
        $provider->shutdown();
        $operation = $execution->start('after');
        self::assertFalse(Span::getCurrent()->isRecording());
        $operation->finish();
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    #[Test]
    public function cached_sdk_tracer_observes_reconfiguration(): void
    {
        $provider = new TracerProvider;
        $execution = new ExecutionTracer($provider);
        $execution->start('before')->finish();
        $config = new TracerConfig;
        $config->setDisabled(true);
        $provider->updateConfigurator(new Configurator(static fn () => $config));
        $operation = $execution->start('disabled');
        self::assertFalse(Span::getCurrent()->isRecording());
        $operation->finish();
        $provider->updateConfigurator(new Configurator(static fn () => new TracerConfig));
        $operation = $execution->start('enabled');
        self::assertTrue(Span::getCurrent()->isRecording());
        $operation->finish();
        $provider->shutdown();
    }

    #[Test]
    public function custom_provider_keeps_its_resolution_behavior(): void
    {
        $sdk = new TracerProvider;
        $provider = $this->createMock(TracerProviderInterface::class);
        $provider->expects(self::exactly(2))->method('getTracer')->with('storm')->willReturn($sdk->getTracer('custom'));
        $execution = new ExecutionTracer($provider);
        $execution->start('first')->finish();
        $execution->start('second')->finish();
        $sdk->shutdown();
    }

    #[Test]
    public function a_string_attribute_is_cut_to_two_hundred_fifty_six_characters_and_no_link_count_is_added(): void
    {
        // with no link to omit, the only attribute on the builder is the one the caller passed, cut to size
        $recorded = [];
        $builder = $this->builder(Span::getInvalid(), $recorded);

        new ExecutionTracer($this->providerOf($builder))->start('operation', ['storm.key' => str_repeat('a', 300)])->finish();

        self::assertSame(['storm.key' => str_repeat('a', 256)], $recorded);
    }

    #[Test]
    public function a_span_that_cannot_be_activated_is_still_ended(): void
    {
        // the operation degrades to a silent one, and the span already started does not leak open
        $span = $this->createMock(SpanInterface::class);
        $span->method('activate')->willThrowException(new RuntimeException('no context storage'));
        $span->expects(self::once())->method('end');
        $recorded = [];

        new ExecutionTracer($this->providerOf($this->builder($span, $recorded)))->start('operation')->finish();
    }

    /**
     * @param  array<string, mixed>  $recorded
     */
    private function builder(SpanInterface $span, array &$recorded): SpanBuilderInterface
    {
        $builder = $this->createStub(SpanBuilderInterface::class);
        $builder->method('setSpanKind')->willReturnSelf();
        $builder->method('setAttribute')->willReturnCallback(static function (string $key, mixed $value) use (&$recorded, $builder): SpanBuilderInterface {
            $recorded[$key] = $value;

            return $builder;
        });
        $builder->method('startSpan')->willReturn($span);

        return $builder;
    }

    private function providerOf(SpanBuilderInterface $builder): TracerProviderInterface
    {
        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($builder);
        $provider = $this->createStub(TracerProviderInterface::class);
        $provider->method('getTracer')->willReturn($tracer);

        return $provider;
    }
}
