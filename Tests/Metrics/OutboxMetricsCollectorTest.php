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
use Storm\Telemetry\Metrics\MetricSample;
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

    #[Test]
    public function absent_outbox_tables_expose_no_outbox_family(): void
    {
        // the schema manager answers as DBAL does, an empty list existing trivially, so each probe has to
        // name its own table for the absence to hold
        $collector = new OutboxMetricsCollector($this->database(['es_inbox'], statistics: '42'));

        self::assertSame(
            ['storm_inbox_rows', 'storm_inbox_duplicates_skipped', 'storm_inbox_duplicate_rows'],
            $this->names($collector->families()),
        );
    }

    #[Test]
    public function the_outbox_gauges_read_the_one_pass_row(): void
    {
        $families = new OutboxMetricsCollector($this->database(['es_outbox']))->families();

        self::assertSame(
            ['storm_outbox_events', 'storm_outbox_events_pending_partitions', 'storm_outbox_events_cooling', 'storm_outbox_events_backing_off', 'storm_outbox_events_oldest_pending_age_seconds'],
            $this->names($families),
        );
        self::assertEquals(
            [
                [new MetricSample(['status' => 'pending'], 4), new MetricSample(['status' => 'failed'], 3)],
                [new MetricSample([], 2)],
                [new MetricSample([], 1)],
                [new MetricSample([], 1)],
                [new MetricSample([], 30)],
            ],
            array_map(static fn (MetricFamily $family): array => $family->samples, $families),
        );
    }

    #[Test]
    public function an_outbox_row_without_its_columns_reads_as_zeros(): void
    {
        $families = new OutboxMetricsCollector($this->database(['es_outbox'], outbox: []))->families();

        self::assertEquals(
            [
                [new MetricSample(['status' => 'pending'], 0), new MetricSample(['status' => 'failed'], 0)],
                [new MetricSample([], 0)],
                [new MetricSample([], 0)],
                [new MetricSample([], 0)],
                [new MetricSample([], 0)],
            ],
            array_map(static fn (MetricFamily $family): array => $family->samples, $families),
        );
    }

    #[Test]
    public function the_retained_row_estimate_becomes_the_rows_gauge(): void
    {
        $families = new OutboxMetricsCollector($this->database(['es_inbox'], statistics: '42'))->families();

        self::assertEquals([new MetricSample([], 42)], $families[0]->samples);
        self::assertSame('storm_inbox_rows', $families[0]->name);
    }

    #[Test]
    public function a_missing_statistics_row_without_a_logger_stays_silent(): void
    {
        $families = new OutboxMetricsCollector($this->database(['es_inbox'], statistics: false))->families();

        self::assertSame(['storm_inbox_duplicates_skipped', 'storm_inbox_duplicate_rows'], $this->names($families));
    }

    #[Test]
    public function the_duplicate_gauges_carry_the_inbox_sums(): void
    {
        $families = new OutboxMetricsCollector($this->database(['es_inbox'], statistics: false))->families();

        self::assertEquals([new MetricSample([], 3)], $families[0]->samples);
        self::assertEquals([new MetricSample([], 2)], $families[1]->samples);
    }

    /**
     * A connection whose schema holds `$tables` and whose queries answer from fixed rows.
     *
     * @param  list<string>  $tables
     * @param  array<string, int>|null  $outbox  the one-pass outbox row, a full one when omitted
     */
    private function database(array $tables, string|false $statistics = false, ?array $outbox = null): Connection
    {
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturnCallback(static fn (array $names): bool => array_diff($names, $tables) === []);

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql): string|false => str_contains($sql, 'pg_stat_user_tables') ? $statistics : 'public',
        );
        $connection->method('fetchAssociative')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'es_outbox_relay') => ['age' => 5, 'relayed' => 7],
            str_contains($sql, 'es_outbox') => $outbox ?? ['pending' => 4, 'partitions' => 2, 'cooling' => 1, 'backing_off' => 1, 'oldest_pending_age' => 30, 'failed' => 3],
            default => ['skipped' => 3, 'touched' => 2],
        });

        return $connection;
    }

    /**
     * @param  list<MetricFamily>  $families
     * @return list<string>
     */
    private function names(array $families): array
    {
        return array_map(static fn (MetricFamily $family): string => $family->name, $families);
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
