<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Metrics;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Saga\Outbox\EngineCommands;
use Storm\Saga\Outbox\RedeliverSagaOutcome;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\SagaMetricsCollector;

/**
 * The saga block reads one grouping and two rows and folds them into eight gauges; the redelivery
 * gauges tell the engine's own traffic apart by every stored type the alias map gives its command
 * class, never by a literal, so a row kept under its FQCN or a retired alias still counts.
 */
final class SagaMetricsCollectorTest extends TestCase
{
    private const array NAMES = [
        'storm_saga_instances',
        'storm_saga_instances_by_state',
        'storm_saga_retries',
        'storm_saga_timers',
        'storm_saga_outbox',
        'storm_saga_outbox_backing_off',
        'storm_saga_outbox_oldest_pending_age_seconds',
        'storm_saga_outbox_redeliveries',
    ];

    #[Test]
    public function the_block_exposes_its_eight_families_in_order(): void
    {
        $bound = [];

        self::assertSame(self::NAMES, $this->names($this->collector($this->database($bound))->families()));
    }

    #[Test]
    public function the_redelivery_gauges_bind_every_stored_type_the_map_names(): void
    {
        $bound = [];
        $families = $this->byName($this->collector($this->database($bound))->families());

        self::assertSame(
            [
                'params' => ['redeliveries' => ['storm.saga.redeliver_outcome', RedeliverSagaOutcome::class]],
                'types' => ['redeliveries' => ArrayParameterType::STRING],
            ],
            $bound,
        );
        self::assertEquals(
            [new MetricSample(['status' => 'pending'], 1), new MetricSample(['status' => 'failed'], 2)],
            $families['storm_saga_outbox_redeliveries']->samples,
        );
        self::assertEquals(
            [new MetricSample(['status' => 'pending'], 4), new MetricSample(['status' => 'failed'], 3)],
            $families['storm_saga_outbox']->samples,
        );
        self::assertEquals([new MetricSample([], 1)], $families['storm_saga_outbox_backing_off']->samples);
        self::assertEquals([new MetricSample([], 30)], $families['storm_saga_outbox_oldest_pending_age_seconds']->samples);
    }

    #[Test]
    public function the_timer_gauge_reads_its_four_dispositions_off_one_row(): void
    {
        $bound = [];
        $families = $this->byName($this->collector($this->database($bound))->families());

        self::assertEquals(
            [
                new MetricSample(['disposition' => 'due'], 2),
                new MetricSample(['disposition' => 'scheduled'], 5),
                new MetricSample(['disposition' => 'claimed'], 1),
                new MetricSample(['disposition' => 'parked'], 0),
            ],
            $families['storm_saga_timers']->samples,
        );
    }

    #[Test]
    public function the_instance_gauges_fold_the_grouping_by_type_then_status_state_and_retries(): void
    {
        // groups of one type: the status gauge sums the groups of one status, the state gauge sums the
        // groups of one state across statuses, and the retry gauge sums the per-group totals, each count
        // read as an integer whatever the driver gave. A numeric state key stays a string label, where
        // PHP folds it to an integer array key
        $bound = [];
        $families = $this->byName($this->collector($this->database($bound, instances: [
            ['workflow_type' => 'onboarding', 'status' => 'running', 'state_key' => 'await_docs', 'n' => '2', 'retries' => '3'],
            ['workflow_type' => 'onboarding', 'status' => 'running', 'state_key' => '2024', 'n' => 1, 'retries' => 1],
            ['workflow_type' => 'onboarding', 'status' => 'halted', 'state_key' => '2024', 'n' => 5, 'retries' => 2],
            ['workflow_type' => 'transfer', 'status' => 'completed', 'state_key' => 'done', 'n' => 4, 'retries' => 0],
        ]))->families());

        self::assertSame(
            [[['workflow_type' => 'onboarding', 'status' => 'running'], 3], [['workflow_type' => 'onboarding', 'status' => 'halted'], 5], [['workflow_type' => 'transfer', 'status' => 'completed'], 4]],
            $this->pairs($families['storm_saga_instances']),
        );
        self::assertSame(
            [[['workflow_type' => 'onboarding', 'state_key' => 'await_docs'], 2], [['workflow_type' => 'onboarding', 'state_key' => '2024'], 6], [['workflow_type' => 'transfer', 'state_key' => 'done'], 4]],
            $this->pairs($families['storm_saga_instances_by_state']),
        );
        self::assertSame(
            [[['workflow_type' => 'onboarding'], 6], [['workflow_type' => 'transfer'], 0]],
            $this->pairs($families['storm_saga_retries']),
        );
    }

    #[Test]
    public function rows_without_their_columns_read_as_zeros(): void
    {
        // a row the driver hands back without a counter, the shape an empty table's aggregates never
        // produce but a mismatched statement could: every gauge answers zero rather than a default
        $bound = [];
        $families = $this->byName($this->collector($this->database($bound, timers: [], outbox: []))->families());

        self::assertEquals(
            [new MetricSample(['disposition' => 'due'], 0), new MetricSample(['disposition' => 'scheduled'], 0), new MetricSample(['disposition' => 'claimed'], 0), new MetricSample(['disposition' => 'parked'], 0)],
            $families['storm_saga_timers']->samples,
        );
        self::assertEquals([new MetricSample(['status' => 'pending'], 0), new MetricSample(['status' => 'failed'], 0)], $families['storm_saga_outbox']->samples);
        self::assertEquals([new MetricSample([], 0)], $families['storm_saga_outbox_backing_off']->samples);
        self::assertEquals([new MetricSample([], 0)], $families['storm_saga_outbox_oldest_pending_age_seconds']->samples);
        self::assertEquals([new MetricSample(['status' => 'pending'], 0), new MetricSample(['status' => 'failed'], 0)], $families['storm_saga_outbox_redeliveries']->samples);
    }

    #[Test]
    public function absent_saga_tables_expose_no_family(): void
    {
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(false);
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);

        self::assertSame([], $this->collector($connection)->families());
    }

    private function collector(Connection $connection): SagaMetricsCollector
    {
        return new SagaMetricsCollector($connection, new MappedEventTypeMapper(EngineCommands::CLASSES));
    }

    /**
     * A connection whose three saga tables exist, by their exact names, serving the given instance
     * grouping, one timer row and one outbox row; the parameters the outbox query binds, and their
     * types, land in `$bound`.
     *
     * @param  array<string, mixed>  $bound
     * @param  list<array<string, mixed>>  $instances
     * @param  array<string, int|string>  $timers
     * @param  array<string, int|string>  $outbox
     */
    private function database(array &$bound, array $instances = [], array $timers = ['parked' => 0, 'claimed' => 1, 'due' => 2, 'scheduled' => 5], array $outbox = ['pending' => 4, 'failed' => 3, 'backing_off' => 1, 'oldest_pending_age' => 30, 'pending_redeliveries' => 1, 'failed_redeliveries' => 2]): Connection
    {
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturnCallback(static fn (array $names): bool => $names === ['workflow_instances', 'workflow_timers', 'workflow_outbox']);

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('fetchAllAssociative')->willReturn($instances);
        $connection->method('fetchAssociative')->willReturnCallback(static function (string $sql, array $params = [], array $types = []) use (&$bound, $timers, $outbox): array {
            if (str_contains($sql, 'workflow_timers')) {
                return $timers;
            }

            $bound = ['params' => $params, 'types' => $types];

            return $outbox;
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

    /**
     * @param  list<MetricFamily>  $families
     * @return array<string, MetricFamily>
     */
    private function byName(array $families): array
    {
        return array_combine($this->names($families), $families);
    }

    /**
     * Each sample as its labels and value, compared strictly so a label folded to an integer shows.
     *
     * @return list<array{array<string, mixed>, int|float}>
     */
    private function pairs(MetricFamily $family): array
    {
        return array_map(static fn (MetricSample $sample): array => [$sample->labels, $sample->value], $family->samples);
    }
}
