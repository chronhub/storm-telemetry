<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Telemetry\Health\ClockSkew;
use Storm\Telemetry\Tests\Fixture\BracketingClock;

final class ClockSkewTest extends TestCase
{
    #[Test]
    #[DataProvider('databaseInstants')]
    public function the_skew_rounds_the_distance_from_the_middle_of_the_bracket_to_the_database(string $database, int $skew): void
    {
        // the application reads ten o'clock before the query and two seconds later after it, so the
        // database instant is compared to the one second in between; fractional distances tell the
        // rounding apart from a floor or a ceiling
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($database);

        self::assertSame($skew, ClockSkew::read($connection, new BracketingClock));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function databaseInstants(): iterable
    {
        yield 'a database behind by 1.6 seconds' => ['2026-09-25 10:00:59.400000+00', 2];
        yield 'a database behind by 1.4 seconds' => ['2026-09-25 10:00:59.600000+00', 1];
        yield 'a database ahead by 1.4 seconds' => ['2026-09-25 10:01:02.400000+00', -1];
    }

    #[Test]
    public function a_database_that_answers_no_instant_is_refused_by_the_parser(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $this->expectException(InvalidDateTimeException::class);

        ClockSkew::read($connection, new BracketingClock);
    }
}
