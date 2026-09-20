<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Metrics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\OutboxMetricsCollector;
use Stringable;

/**
 * The inbox row gauge when the statistics collector holds no row for the table. A real cluster
 * does not produce that answer on demand, so the connection is doubled and the contract of the
 * branch is what gets proven: an absent family rather than a zero, and a log line that says so.
 */
final class OutboxMetricsCollectorTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_inbox_without_its_statistics_row_exposes_no_row_gauge_and_warns(): void
    {
        $logger = new class() extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: mixed[]}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $names = array_map(
            static fn (MetricFamily $family): string => $family->name,
            new OutboxMetricsCollector($this->inboxWithoutStatistics(), $logger)->families(),
        );

        self::assertNotContains('storm_inbox_rows', $names, 'a zero would read as an empty inbox');
        self::assertContains('storm_inbox_duplicates_skipped', $names);
        self::assertContains('storm_inbox_duplicate_rows', $names);
        self::assertSame(
            [['level' => 'warning', 'message' => 'storm.telemetry.inbox_rows_gauge_absent', 'context' => ['schema' => 'tenant_a']]],
            $logger->records,
        );
    }

    private function inboxWithoutStatistics(): Connection
    {
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturnCallback(static fn (array $names): bool => $names === ['es_inbox']);

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql): string|false => str_contains($sql, 'pg_stat_user_tables') ? false : 'tenant_a',
        );
        $connection->method('fetchAssociative')->willReturn(['skipped' => 0, 'touched' => 0]);

        return $connection;
    }
}
