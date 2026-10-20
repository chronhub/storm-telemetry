<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;

/**
 * The application's now minus the database's, in whole seconds, positive when the application runs
 * ahead.
 *
 * One read of `clock_timestamp()` sits between two application `now()` readings, and the database
 * instant is compared to their midpoint, so the round trip cancels rather than adds. A slow round
 * trip still widens what a whole second can hide, but never shifts it.
 */
final readonly class ClockSkew
{
    /**
     * @param  Clock<PointInTime>  $clock
     *
     * @throws Exception on a DBAL failure reading the database clock
     * @throws InvalidDateTimeException on a database instant the canonical parser refuses
     */
    public static function read(Connection $connection, Clock $clock): int
    {
        // equivalent: `format('U.u')` is a numeric string, and the sum below reads
        // it as the very float the cast produces
        // @infection-ignore-all
        $before = (float) $clock->now()->format('U.u');
        $database = PointInTime::fromStorage((string) $connection->fetchOne("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US+00')"));
        // equivalent: `format('U.u')` is a numeric string, and the sum below reads
        // it as the very float the cast produces
        // @infection-ignore-all
        $after = (float) $clock->now()->format('U.u');

        // the same holds for the database instant's cast here, an equivalent mutant the gate's
        // configuration leaves out on this line, where the arithmetic and the rounding are killed
        return (int) round(($before + $after) / 2 - (float) $database->format('U.u'));
    }
}
