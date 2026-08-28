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
 * Read and retention store for the `workflow_history` table, the queryable saga-history log written by
 * {@see TableSagaHistorySink}. Owns the table's timeline read, count and prune so the
 * `storm:telemetry:history` and `storm:telemetry:prune` commands stay pure presentation.
 *
 * Deliberately separate from the sink: a `SagaHistorySink` is a write strategy, one of several such as
 * `Table`, `Log`, `Null`, or `FanOut`, but retention is a property of the table, not of whichever sink
 * fills it. Keeping it here means the prune works regardless of the active sink, and an operator can
 * reclaim the table even after switching strategies.
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
     * One saga RUN's recorded life, oldest first; the timeline behind `storm:telemetry:history` and
     * the ops HTTP surface.
     *
     * Ordered by `occurred_at` then `id`, NOT by arrival: `occurred_at` is the saga's own clock, so
     * under the publishing sink a hop that lands out of order still reads in the order it was lived.
     * Honest limits of that clock: two announcements sharing one `occurred_at` fall back to `id`,
     * which IS insertion order, and a backwards wall-clock step, NTP for example, reorders the span
     * it crosses with only that same tiebreak; a durable per-step ordinal would close both and is
     * deliberately not built until a real timeline is misread over it. A pre-identity wire entry
     * with an empty `occurredAt` is stored under its ARRIVAL time, the sink's COALESCE, so every
     * row carries a real timestamp.
     *
     * `$generation` selects ONE run of a reused correlation; null, the default, aggregates every
     * run. The aggregate default is deliberate: under the default `reject` policy a correlation
     * only ever has one run, so both views coincide; under `#[Workflow(reuse: Allow)]` the runs are
     * distinct sagas sharing a business key, and a forensic surface serves them all, each record
     * labeled with its `generation`, rather than silently hiding history. Attributing run 1's
     * compensation to the living run 2 is the misread the LABEL exists to prevent, and narrowing
     * to one run is the explicit gesture.
     *
     * Redeliveries are deduplicated HERE, before the window and the truncation verdict, the reader's
     * half of the schema's blind-append contract: rows sharing a non-empty `event_id` are one
     * announcement, and the surviving row is the original delivery, lowest `id`. Pre-identity rows
     * with an empty `event_id` never collapse.
     *
     * `$workflowType` is optional and worth passing: with it the filter rides the
     * `(workflow_type, correlation_id, occurred_at, id)` index; without it, a correlation-only
     * predicate cannot use that index's leading column. Deliberately NOT closed by adding a second
     * index, and the dedup pass sorts the correlation's own rows: the writer is a per-event INSERT
     * on the saga's own transaction, and taxing that hot path to serve a forensic read performed
     * once per incident is the wrong trade.
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

        // DISTINCT ON keeps the lowest id per identity, the original delivery; a redelivered row
        // must neither occupy a window slot nor tip the truncation verdict, so the dedup runs
        // BEFORE the LIMIT. An empty event_id keys on the row itself and never collapses.
        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            "SELECT workflow_type, correlation_id, generation, event_type, payload, event_id, occurred_at, recorded_at
             FROM (
                 SELECT DISTINCT ON (COALESCE(NULLIF(event_id, ''), id::text))
                        workflow_type, correlation_id, generation, event_type, payload, event_id, occurred_at, recorded_at, id
                 FROM workflow_history WHERE ".$where."
                 ORDER BY COALESCE(NULLIF(event_id, ''), id::text), id ASC
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
     * Count the history rows older than `$ageSeconds`; the dry-run preview of `prune()`.
     *
     * `int`, not `positive-int`, on purpose: the runtime guard IS the contract; an annotation only
     * defends type-checked callers, and the prune-all hazard comes precisely from the others.
     *
     * @throws InvalidArgumentException when `$ageSeconds` is not strictly positive, a caller bug
     * @throws Exception on a DBAL read failure
     */
    public function countPrunable(int $ageSeconds): int
    {
        $this->assertRetentionAge($ageSeconds);

        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT count(*) FROM workflow_history WHERE '.self::PRUNABLE,
            ['age' => $ageSeconds],
        );
    }

    /**
     * Prune the history rows older than `$ageSeconds`, in `$batch`-capped statements; never a long lock.
     *
     * `int`, not `positive-int`, on purpose; see `countPrunable()`.
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
     * Erase one correlation's history rows; the erasure lever `recorded_at` retention cannot give.
     *
     * `correlation_id` is a business key stored in plaintext, and the crypto-shredding veil that
     * gives the event store its erasure lever does not reach this table; when an app's correlation
     * ids carry personal data, this is the targeted deletion an erasure request acts on, all
     * generations at once, since an erasure never wants one run of an identifier.
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
     * Whether `workflow_history` exists at all; the fact behind the commands' "not installed"
     * wording, public so the prune surface refuses with the same honesty as the read.
     *
     * Single O(1) catalog lookup via `to_regclass`, search_path-aware, the same probe the table
     * sink uses on its write path and for the same measured reason: an `information_schema` scan
     * per call once dominated the DB under load. NOT cached here; a command is a one-shot, and a
     * cached "absent" would outlive the install.
     *
     * @throws Exception on a DBAL failure inspecting the catalog
     */
    public function installed(): bool
    {
        return (bool) $this->connection->fetchOne(
            /** @lang PostgreSQL */
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
            // the sink encodes an object, and the record declares a keyed map; a scalar, or a list,
            // which `is_array` alone would wave through, is someone else's row. An empty object
            // decodes to an empty array and reads as a list, which costs nothing: both answer `[]`.
            payload: is_array($decoded) && ! array_is_list($decoded) ? $decoded : [],
            eventId: (string) $row['event_id'],
            occurredAt: $row['occurred_at'] !== null ? (string) $row['occurred_at'] : '',
            recordedAt: (string) $row['recorded_at'],
        );
    }

    /**
     * The table-level fact behind an empty timeline, asked only then: no row at all, or rows for
     * other correlations. A FACT, not a wiring verdict: rows cannot tell an active sink over an
     * empty table from no sink at all, so the caller's message must keep naming both readings.
     * `EXISTS` with `LIMIT 1`, never a count.
     *
     * @throws Exception on a DBAL read failure
     */
    private function availability(): HistoryAvailability
    {
        // @infection-ignore-all; equivalent: the cast answers the ANALYSER, the driver's `EXISTS`
        // arriving as whatever it pleases; the ternary below coerces the same value identically, and
        // nothing else reads it. Its sibling in `installed()` is NOT equivalent and is not pinned:
        // there the cast meets a `bool` return type, where a raw driver value fails under strict types.
        $any = (bool) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT EXISTS(SELECT 1 FROM workflow_history LIMIT 1)',
        );

        return $any ? HistoryAvailability::HasRows : HistoryAvailability::EmptyTable;
    }

    /**
     * The invariant must not depend on the CLI caller: `Duration` already refuses `0d` at the command,
     * but a programmatic zero, or worse a negative age that moves the cutoff into the FUTURE, would
     * turn retention into prune-all.
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
