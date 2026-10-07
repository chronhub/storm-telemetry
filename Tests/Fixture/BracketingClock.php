<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Fixture;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;

/**
 * A clock that brackets one call: it reads 10:01:00 first and 10:01:02 on every later reading.
 *
 * @implements Clock<PointInTime>
 */
final class BracketingClock implements Clock
{
    /** @var list<string> */
    private array $instants = ['2026-09-25 10:01:00.000000+00', '2026-09-25 10:01:02.000000+00'];

    public function now(): PointInTime
    {
        return PointInTime::fromStorage(array_shift($this->instants) ?? '2026-09-25 10:01:02.000000+00');
    }
}
