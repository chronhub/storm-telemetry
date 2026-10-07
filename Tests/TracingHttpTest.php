<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Storm\Telemetry\Tests\Fixture\RecordingLogger;
use Storm\Telemetry\Tracing\DeadlineHttpClient;
use Storm\Telemetry\Tracing\DeferredSpanProcessor;
use Storm\Telemetry\Tracing\ExportBudget;
use Storm\Telemetry\Tracing\TracingFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class TracingHttpTest extends TestCase
{
    private RecordingLogger $sdkLog;

    protected function setUp(): void
    {
        // the SDK reports a failed export on its internal log, STDERR by default under the CLI; the
        // failures below are provoked on purpose, so their reports are read here instead of leaking
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
    public function retryable_http_failure_is_attempted_once(): void
    {
        $server = new Process([PHP_BINARY, __DIR__.'/Fixture/SlowTraceServer.php', 'retry']);
        $server->start();
        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY ')));
            preg_match('/READY ([0-9]+)/', $server->getOutput(), $match);
            self::assertArrayHasKey(1, $match);
            $processor = TracingFactory::processor('http://127.0.0.1:'.($match[1] ?? throw new RuntimeException('Server did not report a port.')).'/v1/traces', static fn (): bool => true, 2, 1, 200);
            TracingFactory::provider($processor, 'retry-test', 1.0)->getTracer('test')->spanBuilder('attempt')->startSpan()->end();
            self::assertFalse($processor->forceFlush());
            self::assertSame(1, substr_count($server->getOutput(), 'REQUEST'));
            self::assertSame(1, $processor->dropped());
            self::assertNotSame([], $this->sdkLog->messagesAt(LogLevel::ERROR), 'The failed export is reported, never swallowed.');
        } finally {
            $server->stop(0);
        }
    }

    #[Test]
    public function multiple_batches_share_the_same_deadline(): void
    {
        $durations = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$durations): MockResponse {
            $durations[] = $options['max_duration'];
            usleep(20000);

            return new MockResponse('');
        });
        $budget = new ExportBudget(100);
        $factory = new Psr17Factory;
        $transport = new PsrTransportFactory(new DeadlineHttpClient($client, $budget), $factory, $factory)
            ->create('http://localhost/v1/traces', 'application/x-protobuf', maxRetries: 0);
        $processor = new DeferredSpanProcessor(new SpanExporter($transport), static fn (): bool => true, $budget, capacity: 2, batchSize: 1);
        $tracer = new TracerProvider(TracingFactory::spanProcessor($processor))->getTracer('test');
        $tracer->spanBuilder('one')->startSpan()->end();
        $tracer->spanBuilder('two')->startSpan()->end();
        self::assertTrue($processor->forceFlush());
        self::assertCount(2, $durations);
        self::assertLessThan($durations[0] - 0.015, $durations[1]);
        self::assertLessThanOrEqual(0.1, $durations[0]);
    }

    #[Test]
    public function real_slow_http_server_cannot_extend_shutdown_per_batch(): void
    {
        $server = new Process([PHP_BINARY, __DIR__.'/Fixture/SlowTraceServer.php']);
        $server->start();
        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY ')));
            self::assertMatchesRegularExpression('/READY ([0-9]+)/', $server->getOutput());
            preg_match('/READY ([0-9]+)/', $server->getOutput(), $match);
            self::assertArrayHasKey(1, $match);
            $processor = TracingFactory::processor('http://127.0.0.1:'.($match[1] ?? throw new RuntimeException('Server did not report a port.')).'/v1/traces', static fn (): bool => true, 4, 1, 50);
            $tracer = TracingFactory::provider($processor, 'deadline-test', 1.0)->getTracer('test');
            foreach (range(1, 4) as $number) {
                $tracer->spanBuilder('pending-'.$number)->startSpan()->end();
            }
            $start = hrtime(true);
            self::assertFalse($processor->shutdown());
            $seconds = (hrtime(true) - $start) / 1_000_000_000;
            self::assertLessThan(0.3, $seconds, 'A two-second HTTP response must be bounded by one shared export budget.');
            self::assertSame(0, $processor->queued());
            self::assertSame(4, $processor->dropped());
            self::assertNotSame([], $this->sdkLog->messagesAt(LogLevel::ERROR), 'The failed export is reported, never swallowed.');
        } finally {
            $server->stop(0);
        }
    }

    #[Test]
    public function a_sub_millisecond_budget_cannot_be_rounded_to_unlimited_by_curl(): void
    {
        $now = 0;
        $budget = new ExportBudget(1, static function () use (&$now): int {
            return $now;
        });
        $budget->start();
        $now = 500000;
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse('');
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trace export budget exhausted.');
        try {
            new DeadlineHttpClient($client, $budget)->sendRequest(new Psr17Factory()->createRequest('POST', 'http://localhost/v1/traces'));
        } finally {
            self::assertSame(0, $calls);
        }
    }
}
