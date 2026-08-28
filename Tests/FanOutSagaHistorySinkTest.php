<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\History\FanOutSagaHistorySink;
use Storm\Telemetry\History\SagaHistoryEntry;
use Storm\Telemetry\History\SagaHistorySink;

final class FanOutSagaHistorySinkTest extends TestCase
{
    #[Test]
    public function forwards_the_entry_to_every_sink(): void
    {
        $a = new class() implements SagaHistorySink
        {
            public ?SagaHistoryEntry $entry = null;

            public function record(SagaHistoryEntry $entry): void
            {
                $this->entry = $entry;
            }
        };
        $b = new class() implements SagaHistorySink
        {
            public ?SagaHistoryEntry $entry = null;

            public function record(SagaHistoryEntry $entry): void
            {
                $this->entry = $entry;
            }
        };
        $entry = new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []);

        new FanOutSagaHistorySink([$a, $b])->record($entry);

        self::assertSame($entry, $a->entry);
        self::assertSame($entry, $b->entry);
    }

    #[Test]
    #[Group('adversarial')]
    public function does_not_catch_so_a_throwing_child_starves_its_later_siblings(): void
    {
        // the class's contract is a plain loop that does NOT catch: per-sink isolation belongs to
        // BestEffortSagaHistorySink around each child, and this pin is that decorator's entire
        // justification; a catch added here, or a reordered loop, must fail this test
        $thrower = new class() implements SagaHistorySink
        {
            public function record(SagaHistoryEntry $entry): void
            {
                throw new RuntimeException('table down');
            }
        };
        $after = new class() implements SagaHistorySink
        {
            public ?SagaHistoryEntry $entry = null;

            public function record(SagaHistoryEntry $entry): void
            {
                $this->entry = $entry;
            }
        };
        $entry = new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []);

        try {
            new FanOutSagaHistorySink([$thrower, $after])->record($entry);
            self::fail('the fan-out must relay the child throw, never swallow it');
        } catch (RuntimeException $e) {
            self::assertSame('table down', $e->getMessage());
        }

        self::assertNull($after->entry, 'the sibling AFTER the thrower was skipped, in registration order');
    }
}
