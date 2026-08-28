<?php

declare(strict_types=1);

namespace Storm\Telemetry;

use Psr\Log\LoggerInterface;
use Storm\Chronicler\Telemetry\AppendContext;
use Storm\Chronicler\Telemetry\EventStoreObservability;
use Storm\Chronicler\Telemetry\LoadContext;
use Storm\Chronicler\Telemetry\OccConflictContext;
use Storm\Projector\Telemetry\BatchContext;
use Storm\Projector\Telemetry\ListenerFailureContext;
use Storm\Projector\Telemetry\ProjectorObservability;
use Storm\Projector\Telemetry\RunContext;
use Throwable;

/**
 * The opt-in observability facade for the Storm framework; implements every module's observability
 * port and turns each recorded operation into one structured Monolog line, the Context DTO
 * flattened into the log's structured context array.
 *
 * The extension seam is the PORTS, not this class: an app wanting a different fanout, for example
 * an OpenTelemetry exporter or a metrics driver, replaces the two port aliases with its own
 * implementation; this class stays the shipped log-based one. Enriching every line with an ambient
 * correlation or trace id is the logging pipeline's job, a Monolog processor on the storm channels,
 * not a concern of this class.
 *
 * Channel convention: `storm.event_store` and `storm.projector` per module, so an ops pipeline can
 * filter per concern. Levels follow severity:
 *
 * - `info` for a normal operation;
 *
 * - `warning` for an OCC conflict, transient but noteworthy since the retry papers over it while the
 *   rate matters at scale, and for a commit-listener failure the projection survives;
 *
 * - `error` for a failed projector run, whose status stays failed until reset.
 */
final readonly class StormObservability implements EventStoreObservability, ProjectorObservability
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        // the ports' FAIL-OPEN contract, honored at construction. Every emission below runs inside
        // the observed system: the append transaction, the runner loop, the finally. A throwing
        // logger must never reach it
        $this->logger = new BestEffortLogger($logger);
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
        // Split by kind: `stale` is the CAS doing its job on a concurrent head move, absorbed by the
        // retry boundary upstream, a normal contention fact at info. `duplicate` means the UNIQUE
        // backstop caught a second writer landing the same version, the anomaly worth a warning.
        $record = $this->record('event_store', 'occ_conflict', $ctx);

        $ctx->kind === 'duplicate'
            ? $this->logger->warning('storm.event_store.occ_conflict', $record)
            : $this->logger->info('storm.event_store.occ_conflict', $record);
    }

    /**
     * A pure no-op scan stays silent: a daemon projector on an idle system polls forever, and one
     * `info` line per empty poll is standing noise with zero information. Anything moved, seen, or
     * still owed, a non-zero lag with nothing seen is a stall worth a line, logs as before.
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
        // warning, not error: the projection keeps running on a durably committed batch, but a
        // side-channel that keeps failing, such as stale caches until TTL, needs an operator's eye.
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
            // A failed run is non-transient, its status stays `failed` until reset, so `error`, not `info`.
            // `exception` is Monolog's special key: it renders the full backtrace to the log handler.
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
     * Build the structured context array Monolog serializes alongside the message. Two fixed fields,
     * `module` and `operation`, make per-module routing trivial in any structured-log pipeline such as
     * Loki, Elastic, or a cloud aggregator, without requiring Monolog channel splits at install time.
     *
     * @return array<string, mixed>
     */
    private function record(string $module, string $operation, object $ctx): array
    {
        return ['module' => $module, 'operation' => $operation, ...get_object_vars($ctx)];
    }
}
