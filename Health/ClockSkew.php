<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;

/**
 * The application's now minus the database's, in whole seconds, positive when the application
 * runs ahead: one read of `clock_timestamp()` bracketed by two `now()`, the database instant
 * compared to the midpoint of the bracket so the round trip cancels rather than adds; a slow
 * round trip still widens what a whole second can hide, never shifts it.
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
        $before = (float) $clock->now()->format('U.u');
        $database = PointInTime::fromStorage((string) $connection->fetchOne("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US+00')"));
        $after = (float) $clock->now()->format('U.u');

        return (int) round(($before + $after) / 2 - (float) $database->format('U.u'));
    }
}
