<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\LinkInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Storm\Telemetry\Tests\Fixture\RecordingLogger;
use Storm\Telemetry\Tracing\DeferredSpanProcessor;
use Storm\Telemetry\Tracing\TracingFactory;

/**
 * The limits `provider()` sets, read off an exported span loaded past every one of them: each count
 * lands exactly on its bound, so a bound one off in either direction shows.
 */
final class TracingFactoryTest extends TestCase
{
    private RecordingLogger $sdkLog;

    protected function setUp(): void
    {
        // the SDK reports every drop on its internal log, STDERR by default under the CLI; the drops
        // below are provoked on purpose, so their reports are read here instead of leaking
        $this->sdkLog = new RecordingLogger;
        LoggerHolder::set($this->sdkLog);
        Logging::reset();
    }

    protected function tearDown(): void
    {
        LoggerHolder::unset();
        Logging::reset();
    }

    #[Test]
    public function the_provider_names_the_service_on_every_span(): void
    {
        $span = $this->exportedSpan(static function (SpanInterface $span): void {});

        self::assertSame('storm-test', $span->getResource()->getAttributes()->get('service.name'));
    }

    #[Test]
    public function a_span_keeps_sixty_four_attributes_cut_to_two_hundred_fifty_six_characters(): void
    {
        $span = $this->exportedSpan(static function (SpanInterface $span): void {
            $span->setAttribute('storm.long', str_repeat('a', 300));
            for ($i = 1; $i < 70; $i++) {
                $span->setAttribute('storm.attribute.'.$i, $i);
            }
        });

        self::assertSame(64, $span->getAttributes()->count());
        self::assertSame(str_repeat('a', 256), $span->getAttributes()->get('storm.long'));
        self::assertNotSame([], $this->sdkLog->messagesAt(LogLevel::WARNING), 'the drop is reported, never silent');
    }

    #[Test]
    public function a_span_keeps_thirty_two_events_of_sixteen_attributes_each(): void
    {
        $span = $this->exportedSpan(static function (SpanInterface $span): void {
            for ($i = 0; $i < 40; $i++) {
                $span->addEvent('event-'.$i, self::attributes(20));
            }
        });

        self::assertCount(32, $span->getEvents());
        self::assertSame([16], array_values(array_unique(array_map(
            static fn (EventInterface $event): int => $event->getAttributes()->count(),
            $span->getEvents(),
        ))));
    }

    #[Test]
    public function a_span_keeps_one_hundred_twenty_eight_links_of_eight_attributes_each(): void
    {
        $span = $this->exportedSpan(static function (SpanInterface $span): void {}, links: 130);

        self::assertCount(128, $span->getLinks());
        self::assertSame([8], array_values(array_unique(array_map(
            static fn (LinkInterface $link): int => $link->getAttributes()->count(),
            $span->getLinks(),
        ))));
    }

    /**
     * @param  callable(SpanInterface): void  $load
     */
    private function exportedSpan(callable $load, int $links = 0): SpanDataInterface
    {
        $exporter = new InMemoryExporter;
        $processor = new DeferredSpanProcessor($exporter, static fn (): bool => true);
        $builder = TracingFactory::provider($processor, 'storm-test', 1.0)->getTracer('storm-test')->spanBuilder('loaded');
        for ($i = 0; $i < $links; $i++) {
            $builder->addLink(SpanContext::create(bin2hex(random_bytes(16)), bin2hex(random_bytes(8))), self::attributes(10));
        }
        $span = $builder->startSpan();
        $load($span);
        $span->end();

        self::assertTrue($processor->forceFlush());
        $spans = $exporter->getSpans();
        self::assertCount(1, $spans);

        return $spans[0];
    }

    /**
     * @return array<string, int>
     */
    private static function attributes(int $count): array
    {
        $attributes = [];
        for ($i = 0; $i < $count; $i++) {
            $attributes['attribute.'.$i] = $i;
        }

        return $attributes;
    }
}
