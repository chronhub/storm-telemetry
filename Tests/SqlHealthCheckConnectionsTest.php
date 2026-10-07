<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use Closure;
use Doctrine\DBAL\Connection;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Support\Dbal\SchemaCatalog;
use Storm\Telemetry\Health\ClockSkewHealthCheck;
use Storm\Telemetry\Health\DatabaseHealthCheck;
use Storm\Telemetry\Health\EventStorePartitionHealthCheck;
use Storm\Telemetry\Health\OutboxLivenessHealthCheck;
use Storm\Telemetry\Health\OutboxPublishHealthCheck;
use Storm\Telemetry\Health\OutboxRelayLivenessHealthCheck;
use Storm\Telemetry\Health\ProjectionRunnerLivenessHealthCheck;
use Storm\Telemetry\Health\SagaOutboxLivenessHealthCheck;
use Storm\Telemetry\Health\SchemaConformanceHealthCheck;
use Storm\Telemetry\Health\SnapshotOrphanHealthCheck;
use Storm\Telemetry\Health\SqlHealthCheck;
use Storm\Telemetry\Schema\SchemaConformanceTarget;

/**
 * The connections a SQL probe declares are the ones the checker bounds with a statement timeout
 * inside a rolled-back scope. A connection left undeclared is still read by the probe, only without
 * that bound, so the declaration is asserted without executing a single statement.
 */
final class SqlHealthCheckConnectionsTest extends TestCase
{
    /**
     * @param  Closure(Connection, Clock<PointInTime>, ProjectionCatalog): SqlHealthCheck  $probe
     */
    #[Test]
    #[DataProvider('singleConnectionProbes')]
    public function a_probe_declares_the_one_connection_it_reads(Closure $probe): void
    {
        $connection = $this->createStub(Connection::class);

        $declared = $probe($connection, $this->createStub(Clock::class), $this->createStub(ProjectionCatalog::class));

        $this->assertSame([$connection], [...$declared->connections()]);
    }

    /**
     * @return iterable<string, array{Closure(Connection, Clock<PointInTime>, ProjectionCatalog): SqlHealthCheck}>
     */
    public static function singleConnectionProbes(): iterable
    {
        yield 'clock skew' => [static fn (Connection $c, Clock $clock): SqlHealthCheck => new ClockSkewHealthCheck($c, $clock)];
        yield 'database' => [static fn (Connection $c): SqlHealthCheck => new DatabaseHealthCheck($c)];
        yield 'event store partition' => [static fn (Connection $c): SqlHealthCheck => new EventStorePartitionHealthCheck($c)];
        yield 'outbox liveness' => [static fn (Connection $c): SqlHealthCheck => new OutboxLivenessHealthCheck($c)];
        yield 'outbox publish' => [static fn (Connection $c): SqlHealthCheck => new OutboxPublishHealthCheck($c)];
        yield 'outbox relay liveness' => [static fn (Connection $c): SqlHealthCheck => new OutboxRelayLivenessHealthCheck($c)];
        yield 'projection runner liveness' => [static fn (Connection $c, Clock $clock, ProjectionCatalog $catalog): SqlHealthCheck => new ProjectionRunnerLivenessHealthCheck($catalog, $c)];
        yield 'saga outbox liveness' => [static fn (Connection $c): SqlHealthCheck => new SagaOutboxLivenessHealthCheck($c)];
        yield 'snapshot orphan' => [static fn (Connection $c): SqlHealthCheck => new SnapshotOrphanHealthCheck($c)];
    }

    #[Test]
    public function a_projection_runner_probe_without_a_read_model_connection_declares_none(): void
    {
        // the read-model connection is optional: absent, the probe reads the catalog alone and there
        // is no statement for the checker to bound
        $probe = new ProjectionRunnerLivenessHealthCheck($this->createStub(ProjectionCatalog::class));

        $this->assertSame([], [...$probe->connections()]);
    }

    #[Test]
    public function a_schema_probe_declares_each_target_connection_and_its_own_for_the_others(): void
    {
        // a target naming its own connection is bounded on it, one without falls back to the probe's.
        // The targets arrive as a generator: the probe keeps its own list of them, so a second read
        // declares the same connections as the first
        $own = $this->createStub(Connection::class);
        $ledger = $this->createStub(Connection::class);
        $targets = (static function () use ($ledger): Generator {
            yield new SchemaConformanceTarget('ledger', new SchemaCatalog([]), ['ledger_entry'], 'storm:ledger:install', $ledger);
            yield new SchemaConformanceTarget('saga', new SchemaCatalog([]), ['saga_instance'], 'storm:saga:install');
        })();

        $probe = new SchemaConformanceHealthCheck($own, $targets);

        $this->assertSame([$ledger, $own], [...$probe->connections()]);
        $this->assertSame([$ledger, $own], [...$probe->connections()]);
    }
}
