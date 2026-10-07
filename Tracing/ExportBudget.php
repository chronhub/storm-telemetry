<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tracing;

use Closure;
use InvalidArgumentException;

/** A monotonic deadline shared by all HTTP attempts in one explicit drain. */
final class ExportBudget
{
    private int $deadline = 0;

    private readonly Closure $now;

    /** @param (Closure(): int)|null $now Monotonic time in nanoseconds. */
    public function __construct(private readonly int $milliseconds = 200, ?Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => hrtime(true);
        if ($milliseconds < 1 || $milliseconds > 1000) {
            throw new InvalidArgumentException('Export budget must be between 1 and 1000 milliseconds.');
        }
    }

    public function start(): void
    {
        $this->deadline = ($this->now)() + $this->milliseconds * 1_000_000;
    }

    public function remainingSeconds(): float
    {
        return max(0.0, ($this->deadline - ($this->now)()) / 1_000_000_000);
    }
}
