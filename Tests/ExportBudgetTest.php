<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\Tracing\ExportBudget;

final class ExportBudgetTest extends TestCase
{
    #[Test]
    public function a_started_default_budget_leaves_exactly_two_hundred_milliseconds(): void
    {
        // a frozen clock makes the window exact: a drift in the default, in the scale to nanoseconds or
        // in the scale back to seconds shows in the last digit
        $budget = new ExportBudget(now: static fn (): int => 5_000_000_000);
        $budget->start();

        self::assertSame(0.2, $budget->remainingSeconds());
    }

    #[Test]
    public function the_budget_reads_its_injected_clock_rather_than_the_system_one(): void
    {
        $now = 5_000_000_000;
        $budget = new ExportBudget(100, static function () use (&$now): int {
            return $now;
        });
        $budget->start();
        $now += 60_000_000;

        self::assertSame(0.04, $budget->remainingSeconds());
    }

    #[Test]
    public function one_second_is_the_largest_budget_accepted(): void
    {
        $budget = new ExportBudget(1000, static fn (): int => 0);
        $budget->start();

        self::assertSame(1.0, $budget->remainingSeconds());
    }

    #[Test]
    public function a_budget_beyond_one_second_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExportBudget(1001);
    }
}
