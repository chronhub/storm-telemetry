<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use JsonException;
use Storm\Support\Dbal\BatchedDelete;
use Storm\Telemetry\Schema\WorkflowHistorySchema;

/**
 * Read and retention store of the `workflow_history` table, the queryable saga-history log that
 * {@see TableSagaHistorySink} writes.
 *
 * It owns the table's timeline read, count and prune, so the `storm:telemetry:history` and
 * `storm:telemetry:prune` commands stay pure presentation.
 *
 * It stays apart from the sinks: a `SagaHistorySink` is a write strategy, one of `Table`, `Log`,
 * `Null` or `FanOut`, while retention is a property of the table rather than of whichever sink fills
 * it. The prune therefore works whatever the active sink, and an operator can reclaim the table even
 * after switching strategies.
 */
final readonly class WorkflowHistoryStore
{
    /** History is append-only; a row is prunable purely by age. */
    private const string PRUNABLE = "recorded_at < now() - (CAST(:age AS bigint) * interval '1 second')";

    /** Records a timeline returns when the caller states no preference. */
    public const int DEFAULT_LIMIT = 100;

    /** The ceiling a caller cannot argue past, whatever it asks for. */
    public const int MAX_LIMIT = 1000;

    /**
     * The `generation` column's own ceiling, a 32-bit integer: a filter beyond it is refused before
     * the query, where the driver would otherwise answer an out-of-range error quoting the SQL.
     */
    public const int MAX_GENERATION = 2_147_483_647;

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * One saga run's recorded life, oldest first, the timeline behind `storm:telemetry:history` and
     * the ops HTTP surface.
     *
     * Rows are ordered by `occurred_at` then `id` rather than by arrival: `occurred_at` is the saga's
     * own clock, so under the publishing sink a hop that lands out of order still reads in the order
     * it was lived. That clock has two limits, both left to the `id` tiebreak, which is insertion
     * order:
     *
     * - Two announcements sharing one `occurred_at`
     * - A backwards wall-clock step, NTP for example, which reorders the span it crosses
     *
     * A durable per-step ordinal would close both, and is not built until a real timeline is misread
     * over them. A pre-identity wire entry with an empty `occurredAt` is stored under its arrival
     * time, the sink's `COALESCE`, so every row carries a real timestamp.
     *
     * `$generation` selects one run of a reused correlation, and null, the default, aggregates every
     * run. Under the default `reject` policy a correlation has only one run, so both views coincide;
     * under `#[Workflow(reuse: Allow)]` the runs are distinct sagas sharing a business key, and a
     * forensic surface serves them all, each record labeled with its `generation`, rather than hide
     * history in silence. The label prevents attributing run 1's compensation to the living run 2, and
     * narrowing to one run is the explicit gesture.
     *
     * Redeliveries are deduplicated here, before the window and the truncation verdict, the reader's
     * half of the schema's blind-append contract: rows sharing a non-empty `event_id` are one
     * announcement, and the surviving row is the original delivery, the lowest `id`. Pre-identity
     * rows with an empty `event_id` never collapse.
     *
     * `$workflowType` is optional but worth passing: with it the filter uses the
     * `(workflow_type, correlation_id, occurred_at, id)` index, whose leading column a correlation-only
     * predicate cannot use. No second index closes that gap, and the dedup pass sorts the
     * correlation's own rows: the writer is a per-event `INSERT` on the saga's own transaction, and
     * taxing that hot path to serve a forensic read run once per incident is the wrong trade.
     *
     * @param  int  $limit  requested cap, clamped to [1, self::MAX_LIMIT]
     * @param  ?int  $generation  one run's timeline, or null for every run the correlation ever had
     *
     * @throws GenerationOutOfRange when `$generation` lies outside what the column stores
     * @throws Exception on a DBAL read failure
     * @throws JsonException when a stored payload is not decodable jsonb, which the sink cannot write
     */
    public function read(string $correlationId, ?string $workflowType = null, int $limit = self::DEFAULT_LIMIT, ?int $generation = null): WorkflowHistoryTimeline
    {
        if ($generation !== null && ($generation < 1 || $generation > self::MAX_GENERATION)) {
            throw GenerationOutOfRange::outside($generation);
        }

        $capped = max(1, min(self::MAX_LIMIT, $limit));

        if (! $this->installed()) {
            // the absence of a table is not the silence of a saga
            return new WorkflowHistoryTimeline([], false, $capped, HistoryAvailability::NotInstalled);
        }

        $where = 'correlation_id = :corr';
        $params = ['corr' => $correlationId, 'n' => $capped + 1];
        $types = ['n' => ParameterType::INTEGER];

        if ($workflowType !== null && $workflowType !== '') {
            $where .= ' AND workflow_type = :type';
            $params['type'] = $workflowType;
        }
        if ($generation !== null) {
            $where .= ' AND generation = :generation';
            $params['generation'] = $generation;
            $types['generation'] = ParameterType::INTEGER;
        }

        // `DISTINCT ON` keeps the original delivery of each identity and runs before the `LIMIT`,
        // since a redelivered row must neither occupy a window slot nor tip the truncation verdict
        $rows = $this->connection->fetchAllAssociative(
            /* language=PostgreSQL */
            "SELECT workflow_type, correlation_id, generation, event_type, payload, event_id, occurred_at, recorded_at
             FROM (
                 SELECT DISTINCT ON ((event_id = ''), COALESCE(NULLIF(event_id, ''), id::text))
                        workflow_type, correlation_id, generation, event_type, payload, event_id, occurred_at, recorded_at, id
                 FROM workflow_history WHERE ".$where."
                 ORDER BY (event_id = ''), COALESCE(NULLIF(event_id, ''), id::text), id ASC
             ) deduped
             ORDER BY occurred_at ASC, id ASC LIMIT :n",
            $params,
            $types,
        );

        $truncated = count($rows) > $capped;

        return new WorkflowHistoryTimeline(
            records: array_map($this->hydrate(...), $truncated ? array_slice($rows, 0, $capped) : $rows),
            truncated: $truncated,
            limit: $capped,
            // only pay the extra probe when the answer is empty, which is exactly when the caller
            // needs to know whether anything records at all
            availability: $rows === [] ? $this->availability() : HistoryAvailability::HasRows,
        );
    }

    /**
     * Counts the history rows older than `$ageSeconds`, the dry-run preview of `prune()`.
     *
     * The parameter is a plain `int` rather than `positive-int` because the runtime guard is the
     * contract: an annotation only defends type-checked callers, and the prune-all hazard comes from
     * the others.
     *
     * @throws InvalidArgumentException when `$ageSeconds` is not strictly positive, a caller bug
     * @throws Exception on a DBAL read failure
     */
    public function countPrunable(int $ageSeconds): int
    {
        $this->assertRetentionAge($ageSeconds);

        return (int) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT count(*) FROM workflow_history WHERE '.self::PRUNABLE,
            ['age' => $ageSeconds],
        );
    }

    /**
     * Prunes the history rows older than `$ageSeconds` in statements capped at `$batch` rows, never
     * holding a long lock.
     *
     * Both parameters are plain `int` for the reason `countPrunable()` gives.
     *
     * @return int rows pruned
     *
     * @throws InvalidArgumentException when `$ageSeconds` or `$batch` is not strictly positive, a caller bug
     * @throws Exception on a DBAL delete failure
     */
    public function prune(int $ageSeconds, int $batch): int
    {
        $this->assertRetentionAge($ageSeconds);
        if ($batch < 1) {
            throw new InvalidArgumentException(sprintf('The prune batch must be strictly positive, got %d.', $batch));
        }

        return BatchedDelete::run($this->connection, 'workflow_history', self::PRUNABLE, ['age' => $ageSeconds], $batch);
    }

    /**
     * Erases one correlation's history rows, the erasure that `recorded_at` retention cannot give.
     *
     * `correlation_id` is a business key stored in plaintext, and the crypto-shredding that lets the
     * event store erase data does not reach this table. When an app's correlation ids carry personal
     * data, this is the targeted deletion an erasure request acts on, every generation at once, since
     * an erasure never wants one run of an identifier.
     *
     * @return int rows deleted
     *
     * @throws InvalidArgumentException when `$correlationId` is empty, which would silently delete nothing
     * @throws Exception on a DBAL delete failure
     */
    public function deleteForCorrelation(string $correlationId, ?string $workflowType = null): int
    {
        [$where, $params] = $this->correlationPredicate($correlationId, $workflowType);

        return (int) $this->connection->executeStatement(
            'DELETE FROM workflow_history WHERE '.$where,
            $params,
        );
    }

    /**
     * Count what `deleteForCorrelation()` would erase, erasing nothing; the dry run of an erasure.
     *
     * @return int rows the erasure would delete
     *
     * @throws InvalidArgumentException when `$correlationId` is empty, the erasure's own refusal
     * @throws Exception on a DBAL read failure
     */
    public function countForCorrelation(string $correlationId, ?string $workflowType = null): int
    {
        [$where, $params] = $this->correlationPredicate($correlationId, $workflowType);

        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM workflow_history WHERE '.$where,
            $params,
        );
    }

    /**
     * The predicate the erasure and its count both run, written once so a dry run can never report a
     * population the erasure would not remove.
     *
     * @return array{string, array<string, string>}
     *
     * @throws InvalidArgumentException when `$correlationId` is empty
     */
    private function correlationPredicate(string $correlationId, ?string $workflowType): array
    {
        if ($correlationId === '') {
            throw new InvalidArgumentException('The correlation id must not be empty.');
        }

        $where = 'correlation_id = :corr';
        $params = ['corr' => $correlationId];

        if ($workflowType !== null && $workflowType !== '') {
            $where .= ' AND workflow_type = :type';
            $params['type'] = $workflowType;
        }

        return [$where, $params];
    }

    /**
     * Whether `workflow_history` exists at all, the fact behind the commands' "not installed"
     * wording; public so the prune surface refuses with the same honesty as the read.
     *
     * It is one O(1) catalog lookup through `to_regclass`, aware of `search_path`, the probe the table
     * sink runs on its write path and for the same measured reason: an `information_schema` scan per
     * call dominates the database under load. The answer is not cached, since a command is a one-shot
     * and a cached "absent" would outlive the install.
     *
     * @throws Exception on a DBAL failure inspecting the catalog
     */
    public function installed(): bool
    {
        return (bool) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT (to_regclass(?) IS NOT NULL)::int',
            [WorkflowHistorySchema::TABLE],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws JsonException when a stored payload is not decodable jsonb, which the sink cannot write
     */
    private function hydrate(array $row): WorkflowHistoryRecord
    {
        $payload = $row['payload'] ?? null;
        $decoded = is_string($payload) && $payload !== '' ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : [];

        return new WorkflowHistoryRecord(
            workflowType: (string) $row['workflow_type'],
            correlationId: (string) $row['correlation_id'],
            generation: (int) $row['generation'],
            eventType: (string) $row['event_type'],
            // the sink encodes an object and the record declares a keyed map, so a scalar or a list,
            // which `is_array` alone would accept, is someone else's row; an empty object decodes to
            // an empty array that reads as a list, at no cost, since both answer `[]`
            payload: is_array($decoded) && ! array_is_list($decoded) ? $decoded : [],
            eventId: (string) $row['event_id'],
            occurredAt: $row['occurred_at'] !== null ? (string) $row['occurred_at'] : '',
            recordedAt: (string) $row['recorded_at'],
        );
    }

    /**
     * The table-level fact behind an empty timeline, asked only then: no row at all, or rows for
     * other correlations.
     *
     * It is a fact rather than a wiring verdict: rows cannot tell an active sink over an empty table
     * from no sink at all, so the caller's message must keep naming both readings. The probe is an
     * `EXISTS` with `LIMIT 1`, never a count.
     *
     * @throws Exception on a DBAL read failure
     */
    private function availability(): HistoryAvailability
    {
        // equivalent: the cast answers the analyzer, the driver's `EXISTS`
        // arriving as whatever it pleases; the ternary below coerces the same value identically, and
        // nothing else reads it. Its sibling in `installed()` is not equivalent and is not pinned:
        // there the cast meets a `bool` return type, where a raw driver value fails under strict types.
        // @infection-ignore-all
        $any = (bool) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT EXISTS(SELECT 1 FROM workflow_history LIMIT 1)',
        );

        return $any ? HistoryAvailability::HasRows : HistoryAvailability::EmptyTable;
    }

    /**
     * The invariant must not depend on the CLI caller.
     *
     * `Duration` already refuses `0d` at the command, but a programmatic zero, or worse a negative age
     * that moves the cutoff into the future, would turn retention into prune-all.
     */
    private function assertRetentionAge(int $ageSeconds): void
    {
        if ($ageSeconds < 1) {
            throw new InvalidArgumentException(sprintf(
                'The retention age must be strictly positive, got %d — zero or negative would prune every row.',
                $ageSeconds,
            ));
        }
    }
}
