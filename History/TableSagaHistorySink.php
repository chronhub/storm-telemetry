<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use JsonException;
use Storm\Telemetry\Schema\WorkflowHistorySchema;
use Throwable;

/**
 * Inserts the saga history into the queryable `workflow_history` table. Not the default; the wiring
 * defaults to `NullSagaHistorySink` since saga history is opt-in. It is the right sink when a consumer
 * reads back a saga's timeline by `(workflow_type, correlation_id)`: a btree range scan, which a log
 * aggregator cannot match on a high-cardinality correlation id.
 *
 * Cost the caller must own: a synchronous `INSERT` on whatever connection it runs on, the operational DB
 * unless wired to a separate one. It is NOT best-effort itself; a DB or encode failure throws, to be
 * absorbed by the subscriber's backstop or a wrapping `BestEffortSagaHistorySink`. Schema-tolerant: if
 * the table is not installed, with the Telemetry package pulled but `storm:telemetry:install` not run, it
 * no-ops, caching only the positive so a long-lived worker booted before the install starts recording
 * once the table appears, instead of latching "absent" for its whole life.
 */
final class TableSagaHistorySink implements SagaHistorySink
{
    private ?bool $tableExists = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * {@inheritDoc}
     *
     * When the engine's delivery seam holds an ambient transaction on this connection, the
     * announcements fire INSIDE it, before the outer commit; a failed statement there would put
     * PostgreSQL into an aborted state, 25P02, and the subscriber's backstop would swallow the
     * cause while the outer transaction dies later on an unrelated statement. So under an active
     * transaction the probe and the insert run inside their own savepoint, DBAL's nested
     * `transactional()`, and a failure rolls back to it, leaving the outer transaction usable.
     *
     * A failure also drops the positive table latch: after `storm:telemetry:install --drop` a
     * long-lived worker's next record re-probes and finds the table gone, no-oping quietly instead
     * of throwing once per announcement for the rest of its life.
     *
     * @throws Exception on a DBAL failure of the install probe or the insert
     * @throws JsonException when the payload cannot be encoded to jsonb
     */
    public function record(SagaHistoryEntry $entry): void
    {
        try {
            $this->connection->isTransactionActive()
                ? $this->connection->transactional(fn () => $this->insert($entry))
                : $this->insert($entry);
        } catch (Throwable $e) {
            $this->tableExists = null;

            throw $e;
        }
    }

    /**
     * @throws Exception on a DBAL failure of the install probe or the insert
     * @throws JsonException when the payload cannot be encoded to jsonb
     */
    private function insert(SagaHistoryEntry $entry): void
    {
        if (! $this->tableInstalled()) {
            return;
        }

        // COALESCE keeps a pre-identity wire entry with an empty occurredAt insertable: arrival
        // time is then the best available approximation.
        $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'INSERT INTO workflow_history (workflow_type, correlation_id, generation, event_type, payload, event_id, occurred_at)
             VALUES (:workflow_type, :correlation_id, :generation, :event_type, CAST(:payload AS jsonb), :event_id,
                     COALESCE(CAST(NULLIF(:occurred_at, \'\') AS timestamptz), clock_timestamp()))',
            [
                'workflow_type' => $entry->workflowType,
                'correlation_id' => $entry->correlationId,
                'generation' => $entry->generation,
                'event_type' => $entry->eventType,
                'payload' => json_encode($entry->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'event_id' => $entry->eventId,
                'occurred_at' => $entry->occurredAt,
            ],
        );
    }

    /**
     * Cache only the positive: a worker booted before `storm:telemetry:install` must start recording once
     * the table appears, not latch "absent" for its whole life, so re-probe while it is missing. The probe
     * is a single O(1) catalog lookup via `to_regclass`, search_path-aware, NOT an `information_schema`
     * scan: under a high saga-transition rate with telemetry uninstalled the latter re-ran one heavy
     * introspection per transition and dominated the DB under load.
     *
     * @throws Exception on a DBAL failure inspecting the catalog
     */
    private function tableInstalled(): bool
    {
        if ($this->tableExists === true) {
            return true;
        }

        return $this->tableExists = (bool) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT (to_regclass(?) IS NOT NULL)::int',
            [WorkflowHistorySchema::TABLE],
        );
    }
}
