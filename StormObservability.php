<?php

declare(strict_types=1);

namespace Storm\Telemetry;

use Psr\Log\LoggerInterface;
use Storm\Chronicler\Telemetry\AppendContext;
use Storm\Chronicler\Telemetry\EventStoreObservability;
use Storm\Chronicler\Telemetry\LoadContext;
use Storm\Chronicler\Telemetry\OccConflictContext;
use Storm\Chronicler\Telemetry\SafeHeadCrossingContext;
use Storm\Chronicler\Telemetry\SafeHeadObservability;
use Storm\Projector\Telemetry\BatchContext;
use Storm\Projector\Telemetry\ListenerFailureContext;
use Storm\Projector\Telemetry\ProjectorObservability;
use Storm\Projector\Telemetry\RunContext;
use Throwable;

/**
 * The opt-in observability facade of Storm: it implements every module's observability port and
 * logs each recorded operation as one structured Monolog line, its context DTO flattened into the
 * log context.
 *
 * The ports are the extension seam. An app wanting another fanout, such as an OpenTelemetry exporter
 * or a metrics driver, points the port aliases at its own implementation, and this class remains the
 * shipped log-based one. Adding an ambient correlation or trace id to every line belongs to the
 * logging pipeline, as a Monolog processor.
 *
 * Message names open on their concern, `storm.event_store`, `storm.projector` or `storm.safe_head`,
 * so an ops pipeline can filter per concern. Levels follow severity:
 *
 * - `info` for a normal operation, including a stale OCC conflict the retry absorbs
 * - `warning` for a duplicate-version OCC conflict, and for a commit-listener failure the projection
 *   survives
 * - `error` for a failed projector run, whose status stays `failed` until reset
 */
final readonly class StormObservability implements EventStoreObservability, ProjectorObservability, SafeHeadObservability
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        // the ports are fail-open: every emission below runs inside the observed system, the append
        // transaction, the runner loop or a `finally`, and a throwing logger must never reach it
        $this->logger = new BestEffortLogger($logger);
    }

    public function recordCrossing(SafeHeadCrossingContext $context): void
    {
        $this->logger->info('storm.safe_head.crossed', get_object_vars($context) + ['presumption' => 'aborted']);
    }

    public function recordAppend(AppendContext $ctx): void
    {
        $this->logger->info('storm.event_store.append', $this->record('event_store', 'append', $ctx));
    }

    public function recordLoad(LoadContext $ctx): void
    {
        $this->logger->info('storm.event_store.load', $this->record('event_store', 'load', $ctx));
    }

    public function recordOccConflict(OccConflictContext $ctx): void
    {
        // `stale` is the CAS seeing a concurrent head move that the upstream retry absorbs, so `info`;
        // `duplicate` is the `UNIQUE` backstop catching a second writer of one version, so `warning`
        $record = $this->record('event_store', 'occ_conflict', $ctx);

        $ctx->kind === 'duplicate'
            ? $this->logger->warning('storm.event_store.occ_conflict', $record)
            : $this->logger->info('storm.event_store.occ_conflict', $record);
    }

    /**
     * {@inheritDoc}
     *
     * A scan that saw, applied and owed nothing stays silent: an idle daemon projector polls forever,
     * and a line per empty poll carries no information. A non-zero lag with nothing seen is a stall,
     * and it logs.
     */
    public function recordBatch(BatchContext $ctx): void
    {
        if ($ctx->eventCount === 0 && $ctx->applied === 0 && $ctx->lag === 0) {
            return;
        }

        $this->logger->info('storm.projector.batch', $this->record('projector', 'batch', $ctx));
    }

    public function recordListenerFailure(ListenerFailureContext $ctx): void
    {
        $this->logger->warning('storm.projector.commit_listener_failure', [
            'module' => 'projector',
            'operation' => 'commit_listener_failure',
            'projection' => $ctx->projection,
            'error_class' => $ctx->error::class,
            'error_message' => $ctx->error->getMessage(),
            'exception' => $ctx->error,
        ]);
    }

    public function recordRun(RunContext $ctx): void
    {
        $context = $this->record('projector', 'run', $ctx);
        $error = $context['error'] ?? null;
        unset($context['error']); // the nullable Throwable carrier, re-attached below under 'exception'

        if ($error instanceof Throwable) {
            // `exception` is Monolog's special key: it renders the full backtrace to the log handler
            $this->logger->error('storm.projector.run', [
                ...$context,
                'error_class' => $error::class,
                'error_message' => $error->getMessage(),
                'exception' => $error,
            ]);

            return;
        }

        $this->logger->info('storm.projector.run', $context);
    }

    /**
     * Builds the structured context Monolog serializes alongside the message.
     *
     * The two fixed fields, `module` and `operation`, let any structured-log pipeline such as Loki,
     * Elastic or a cloud aggregator route per module, without Monolog channel splits at install time.
     *
     * @return array<string, mixed>
     */
    private function record(string $module, string $operation, object $ctx): array
    {
        return ['module' => $module, 'operation' => $operation, ...get_object_vars($ctx)];
    }
}
