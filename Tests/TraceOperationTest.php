<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use Generator;
use LogicException;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\Tracing\TraceOperation;

final class TraceOperationTest extends TestCase
{
    #[Test]
    public function a_string_attribute_is_cut_to_two_hundred_fifty_six_characters(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects(self::once())->method('setAttribute')->with('storm.key', str_repeat('a', 256));

        new TraceOperation($span)->attribute('storm.key', str_repeat('a', 300));
    }

    #[Test]
    public function finishing_after_a_pause_detaches_the_scope_only_once(): void
    {
        // the execution boundary pauses the operation, and finishing pauses again on its way out
        $scope = $this->createMock(ScopeInterface::class);
        $scope->expects(self::once())->method('detach');
        $operation = new TraceOperation(null, $scope);

        $operation->pause();
        $operation->finish();
    }

    #[Test]
    public function a_second_finish_does_not_end_the_span_again(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects(self::once())->method('end');
        $operation = new TraceOperation($span);

        $operation->finish();
        $operation->finish();
    }

    #[Test]
    public function a_failure_records_its_type_and_the_type_of_its_cause(): void
    {
        $recorded = [];
        $span = $this->createMock(SpanInterface::class);
        $span->method('setAttribute')->willReturnCallback(static function (string $key, mixed $value) use (&$recorded, $span): SpanInterface {
            $recorded[$key] = $value;

            return $span;
        });
        $span->expects(self::once())->method('setStatus')->with(StatusCode::STATUS_ERROR);

        new TraceOperation($span)->finish(new RuntimeException('outer', previous: new LogicException('inner')));

        self::assertSame(['error.type' => RuntimeException::class, 'error.cause.type' => LogicException::class], $recorded);
    }

    #[Test]
    public function an_attribute_the_span_refuses_never_reaches_the_caller(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects(self::once())->method('setAttribute')->willThrowException(new RuntimeException('span gone'));

        new TraceOperation($span)->attribute('storm.key', 'value');
    }

    #[Test]
    public function a_clock_that_fails_leaves_the_end_time_to_the_span(): void
    {
        // the pause records no end time of its own, so the span ends at the time it reads itself
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willThrowException(new RuntimeException('clock unavailable'));
        $span = $this->createMock(SpanInterface::class);
        $span->expects(self::once())->method('end')->with(null);
        Clock::setDefault($clock);

        try {
            new TraceOperation($span)->finish();
        } finally {
            Clock::reset();
        }
    }

    #[Test]
    public function a_scope_that_fails_to_detach_is_released_all_the_same(): void
    {
        // the second pause finds no scope left to detach: the failed detach still dropped it
        $scope = $this->createMock(ScopeInterface::class);
        $scope->expects(self::once())->method('detach')->willThrowException(new RuntimeException('scope gone'));
        $operation = new TraceOperation(null, $scope);

        $operation->pause();
        $operation->pause();
    }

    #[Test]
    public function a_link_the_span_refuses_ends_the_linking_without_reaching_the_caller(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects(self::once())->method('addLink')->willThrowException(new RuntimeException('span gone'));

        new TraceOperation($span)->links([
            ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
            ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
        ]);
    }

    #[Test]
    public function an_operation_without_a_span_still_consumes_every_origin(): void
    {
        $origins = $this->origins(2);
        $carriers = (static function () use ($origins): Generator {
            yield from $origins;
        })();

        new TraceOperation()->links($carriers);

        self::assertFalse($carriers->valid());
    }

    #[Test]
    public function origins_under_the_cap_are_all_linked_and_none_is_counted_as_omitted(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects(self::exactly(2))->method('addLink');
        $span->expects(self::never())->method('setAttribute');

        new TraceOperation($span)->links($this->origins(2));
    }

    #[Test]
    public function the_first_one_hundred_twenty_eight_origins_are_linked_and_every_later_one_is_counted(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects(self::exactly(128))->method('addLink');
        $span->expects(self::once())->method('setAttribute')->with('storm.links.omitted', 2);

        new TraceOperation($span)->links($this->origins(130));
    }

    #[Test]
    public function a_failure_the_span_cannot_record_still_ends_the_span(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('setAttribute')->willThrowException(new RuntimeException('span gone'));
        $span->expects(self::once())->method('end');

        new TraceOperation($span)->finish(new RuntimeException('business failure'));
    }

    #[Test]
    public function an_end_the_span_refuses_is_not_attempted_twice(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->expects(self::once())->method('end')->willThrowException(new RuntimeException('span gone'));
        $operation = new TraceOperation($span);

        $operation->finish();
        $operation->finish();
    }

    /**
     * @return list<array{traceparent: string}>
     */
    private function origins(int $count): array
    {
        return array_map(static fn (int $i): array => ['traceparent' => sprintf('00-%032x-%016x-01', $i, $i)], range(1, $count));
    }
}
