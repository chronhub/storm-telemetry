<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use JsonException;
use Storm\Telemetry\Schema\WorkflowHistorySchema;
use Throwable;

/**
 * Inserts the saga history into the queryable `workflow_history` table.
 *
 * Saga history is opt-in, so the wiring defaults to `NullSagaHistorySink`. This sink fits a consumer
 * that reads back a saga's timeline by `(workflow_type, correlation_id)`, a btree range scan that a
 * log aggregator cannot match on a high-cardinality correlation id.
 *
 * The caller must own its cost: a synchronous `INSERT` on the connection it runs on, the operational
 * database unless wired to a separate one. It is not best-effort itself: a database or encode failure
 * throws, for the subscriber's backstop or a wrapping `BestEffortSagaHistorySink` to absorb.
 *
 * While the table is not installed, with the package pulled but `storm:telemetry:install` not run, it
 * does nothing, and a long-lived worker booted before the install starts recording once the table
 * appears.
 */
final class TableSagaHistorySink implements SagaHistorySink
{
    private ?bool $tableExists = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * {@inheritDoc}
     *
     * When the engine's delivery seam holds an ambient transaction on this connection, the
     * announcements fire inside it, before the outer commit. A failed statement there would put
     * PostgreSQL into the aborted state 25P02, and the subscriber's backstop would swallow the cause
     * while the outer transaction dies later on an unrelated statement. Under an active transaction
     * the probe and the insert therefore run in their own savepoint, DBAL's nested `transactional()`,
     * and a failure rolls back to it, leaving the outer transaction usable.
     *
     * A failure also drops the positive table latch: after `storm:telemetry:install --drop`, a
     * long-lived worker's next record re-probes, finds the table gone and does nothing, instead of
     * throwing once per announcement for the rest of its life.
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

        // `COALESCE` keeps a pre-identity wire entry with an empty `occurredAt` insertable: arrival
        // time is then the best available approximation.
        $this->connection->executeStatement(
            /* language=PostgreSQL */
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
     * Probes for the table, caching only a positive answer.
     *
     * A worker booted before `storm:telemetry:install` must start recording once the table appears
     * rather than latch "absent" for its whole life, so it re-probes while the table is missing. The
     * probe is one O(1) catalog lookup through `to_regclass`, aware of `search_path`, rather than an
     * `information_schema` scan, which under a high saga-transition rate with telemetry uninstalled
     * would run one heavy introspection per transition and dominate the database under load.
     *
     * @throws Exception on a DBAL failure inspecting the catalog
     */
    private function tableInstalled(): bool
    {
        if ($this->tableExists === true) {
            return true;
        }

        return $this->tableExists = (bool) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT (to_regclass(?) IS NOT NULL)::int',
            [WorkflowHistorySchema::TABLE],
        );
    }
}
