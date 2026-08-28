<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Storm\Chronicler\Telemetry\AppendContext;
use Storm\Chronicler\Telemetry\LoadContext;
use Storm\Chronicler\Telemetry\OccConflictContext;
use Storm\Projector\Telemetry\BatchContext;
use Storm\Projector\Telemetry\ListenerFailureContext;
use Storm\Projector\Telemetry\RunContext;
use Storm\Telemetry\StormObservability;
use Stringable;

final class StormObservabilityTest extends TestCase
{
    private RecordingLogger $logger;

    private StormObservability $observability;

    #[Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger;
        $this->observability = new StormObservability($this->logger);
    }

    #[Test]
    public function records_an_append_at_info_with_module_and_operation_fields(): void
    {
        $ctx = new AppendContext('account-x', 'account', 3, 7, 12.4);

        $this->observability->recordAppend($ctx);

        self::assertSame(
            [['info', 'storm.event_store.append', ['module' => 'event_store', 'operation' => 'append', ...(array) $ctx]]],
            $this->logger->records,
        );
    }

    #[Test]
    public function records_a_load_at_info(): void
    {
        $ctx = new LoadContext('retrieveAll', 'account-x', 5, 3.1);

        $this->observability->recordLoad($ctx);

        self::assertSame(
            [['info', 'storm.event_store.load', ['module' => 'event_store', 'operation' => 'load', ...(array) $ctx]]],
            $this->logger->records,
        );
    }

    #[Test]
    public function records_a_stale_occ_conflict_at_info(): void
    {
        // the CAS pre-check losing to a concurrent head move is normal contention, absorbed by the
        // retry boundary upstream; logging it as a warning drowned the level in noise
        $ctx = new OccConflictContext('account-x', 'account', 5, 7, 'stale');

        $this->observability->recordOccConflict($ctx);

        self::assertSame(
            [['info', 'storm.event_store.occ_conflict', ['module' => 'event_store', 'operation' => 'occ_conflict', ...(array) $ctx]]],
            $this->logger->records,
        );
    }

    #[Test]
    public function records_a_duplicate_occ_conflict_at_warning(): void
    {
        // the UNIQUE backstop caught a second writer landing the same version: the anomaly keeps eyes
        $ctx = new OccConflictContext('account-x', 'account', 5, 5, 'duplicate');

        $this->observability->recordOccConflict($ctx);

        self::assertSame(
            [['warning', 'storm.event_store.occ_conflict', ['module' => 'event_store', 'operation' => 'occ_conflict', ...(array) $ctx]]],
            $this->logger->records,
        );
    }

    #[Test]
    public function records_a_projector_batch_at_info_with_its_lag(): void
    {
        $ctx = new BatchContext('account_balance', 42, 1.7, lag: 5);

        $this->observability->recordBatch($ctx);

        self::assertSame(
            [['info', 'storm.projector.batch', ['module' => 'projector', 'operation' => 'batch', ...(array) $ctx]]],
            $this->logger->records,
        );
        self::assertSame(5, $this->logger->records[0][2]['lag']); // the per-cycle lag metric rides the batch log
    }

    #[Test]
    public function a_pure_noop_scan_stays_silent_while_a_zero_batch_with_lag_still_logs(): void
    {
        // a daemon on an idle system polls forever: one info line per empty poll is standing noise.
        // Silence requires ALL THREE zeros; nothing seen with a positive lag is a stall worth a line
        $this->observability->recordBatch(new BatchContext('account_balance', 0, 0.3, lag: 0));
        self::assertSame([], $this->logger->records);

        $this->observability->recordBatch(new BatchContext('account_balance', 0, 0.3, lag: 12));
        self::assertCount(1, $this->logger->records);
        self::assertSame(12, $this->logger->records[0][2]['lag']);

        // the single-cursor's empty advance: events SEEN with none applied is the cursor traversing
        // foreign traffic, the ops discriminant the BatchContext docblock names; it must log
        $this->observability->recordBatch(new BatchContext('account_balance', 7, 0.3, lag: 0, applied: 0));
        self::assertCount(2, $this->logger->records);
        self::assertSame(7, $this->logger->records[1][2]['eventCount']);
    }

    #[Test]
    public function records_a_projector_run_at_info(): void
    {
        $ctx = new RunContext('account_balance', 100, 4, 25.0, 'idle');
        $expected = (array) $ctx;
        unset($expected['error']); // the nullable Throwable carrier is dropped from the flat context

        $this->observability->recordRun($ctx);

        self::assertSame(
            [['info', 'storm.projector.run', ['module' => 'projector', 'operation' => 'run', ...$expected]]],
            $this->logger->records,
        );
    }

    #[Test]
    public function records_a_failed_projector_run_at_error_with_the_exception(): void
    {
        $error = new RuntimeException('boom');
        $ctx = new RunContext('account_balance', 0, 1, 5.0, 'failed', $error);

        $this->observability->recordRun($ctx);

        self::assertCount(1, $this->logger->records);
        [$level, $message, $context] = $this->logger->records[0];

        self::assertSame('error', $level); // failed is non-transient, so error, not info
        self::assertSame('storm.projector.run', $message);
        self::assertSame('projector', $context['module']);
        self::assertSame('failed', $context['finalStatus']);
        self::assertSame(RuntimeException::class, $context['error_class']);
        self::assertSame('boom', $context['error_message']);
        self::assertSame($error, $context['exception']);   // Monolog renders the backtrace
        self::assertArrayNotHasKey('error', $context);      // the raw Throwable isn't double-logged under 'error'
    }

    #[Test]
    public function records_a_commit_listener_failure_at_warning_with_the_exception(): void
    {
        // warning, not error: the batch is durably committed and the projection keeps running, but a
        // side-channel that keeps failing (a stale cache until TTL) needs an operator's eye, not a rollback.
        $error = new RuntimeException('cache purge failed');
        $ctx = new ListenerFailureContext('account_balance', $error);

        $this->observability->recordListenerFailure($ctx);

        self::assertCount(1, $this->logger->records);
        [$level, $message, $context] = $this->logger->records[0];

        self::assertSame('warning', $level);
        self::assertSame('storm.projector.commit_listener_failure', $message);
        self::assertSame('projector', $context['module']);
        self::assertSame('account_balance', $context['projection']);
        self::assertSame(RuntimeException::class, $context['error_class']);
        self::assertSame('cache purge failed', $context['error_message']);
        self::assertSame($error, $context['exception']);
    }

    #[Test]
    public function structured_context_carries_module_and_operation_then_the_dto_fields(): void
    {
        $ctx = new AppendContext('account-x', 'account', 3, 7, 12.4);

        $this->observability->recordAppend($ctx);

        self::assertSame(
            [
                'module' => 'event_store',
                'operation' => 'append',
                'streamName' => 'account-x',
                'category' => 'account',
                'eventCount' => 3,
                'newVersion' => 7,
                'durationMs' => 12.4,
            ],
            $this->logger->records[0][2],
        );
    }

    #[Test]
    public function a_throwing_logger_never_escapes_any_emission(): void
    {
        // the ports' fail-open contract, honored by THIS implementation: recordAppend fires inside
        // the append transaction and recordRun inside the runner's finally; a logger outage there
        // must never roll back an append or mask a run's real outcome
        $hostile = new class() extends AbstractLogger
        {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        };
        $observability = new StormObservability($hostile);

        $observability->recordAppend(new AppendContext('account-x', 'account', 3, 7, 12.4));
        $observability->recordOccConflict(new OccConflictContext('account-x', 'account', 3, 7, 'stale'));
        $observability->recordBatch(new BatchContext('rm_articles', 10, 5.0));
        $observability->recordRun(new RunContext('rm_articles', 10, 1, 5.0, 'idle', new RuntimeException('primary failure')));

        $this->expectNotToPerformAssertions();
    }
}

/**
 * Test double: a PSR-3 logger that records every call.
 *
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        /** @var array<string, mixed> $context */
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
