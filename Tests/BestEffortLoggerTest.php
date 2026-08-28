<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Storm\Telemetry\BestEffortLogger;
use Stringable;

/**
 * The no-throw boundary of every Telemetry emission: delegation is transparent when the channel is
 * healthy, and a channel failure is absorbed: never rethrown, never re-logged into the channel that
 * just failed. This is the implementation-side half of the observability ports' fail-open contract.
 */
final class BestEffortLoggerTest extends TestCase
{
    #[Test]
    public function delegates_level_message_and_context_untouched(): void
    {
        $recorder = new class() extends AbstractLogger
        {
            /** @var list<array{mixed, string, array<string, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        new BestEffortLogger($recorder)->warning('storm.test', ['k' => 'v']);

        self::assertSame([['warning', 'storm.test', ['k' => 'v']]], $recorder->records);
    }

    #[Test]
    public function a_throwing_channel_is_absorbed_never_rethrown(): void
    {
        $hostile = new class() extends AbstractLogger
        {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        };

        new BestEffortLogger($hostile)->error('storm.test');

        $this->expectNotToPerformAssertions();
    }
}
