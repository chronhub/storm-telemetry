<?php

declare(strict_types=1);

namespace Storm\Telemetry\Schema;

use Storm\Support\Dbal\SchemaCatalog;
use Storm\Telemetry\Console\InstallTelemetryCommand;

/**
 * What `storm:telemetry:install` proves after its DDL: `workflow_history` as the sink relies on it.
 * The module's own verification data, kept beside the `*Schema` class it mirrors, exactly as Saga keeps
 * `SagaSchemaCatalog`; Telemetry is opt-in and depends on no core package, only on the shared
 * {@see \Storm\Support\Dbal\SchemaProbe} that reads this.
 *
 * Why the install verifies rather than trusts its own DDL: the statements are `CREATE TABLE IF NOT
 * EXISTS`, so a `workflow_history` predating a column keeps its old shape silently and fails only
 * later, inside the sink's INSERT, on a path the subscriber's backstop deliberately swallows. Proving
 * the shape at install turns that into a refusal at the moment someone can act on it.
 *
 * @see InstallTelemetryCommand the installer that runs the probe inside its transaction
 */
final class TelemetrySchemaCatalog
{
    /**
     * Every column the table must expose, pinned to the shape the probe composes from the live
     * catalogs, `format_type` plus its nullability; the full set `WorkflowHistorySchema` declares.
     * A homonym with the right names but a narrower type or a flipped nullability fails exactly
     * where the class docblock says: inside the sink's INSERT, swallowed. A null value here would
     * fall back to a presence-only check.
     *
     * @var array<string, array<string, string|null>>
     */
    public const array COLUMNS = [
        'workflow_history' => [
            'id' => 'bigint not null',
            'workflow_type' => 'text not null',
            'correlation_id' => 'text not null',
            'generation' => 'integer not null',
            'event_type' => 'text not null',
            'payload' => 'jsonb not null',
            'event_id' => 'text not null',
            'occurred_at' => 'timestamp(6) with time zone not null',
            'recorded_at' => 'timestamp(6) with time zone not null',
        ],
    ];

    /**
     * Named constraints per table; a non-null value is a fragment the live `pg_get_constraintdef`
     * must contain. The single primary key pins its column, `PRIMARY KEY (…)` deparsing verbatim,
     * so a homonym keyed differently is refused at install rather than discovered at read time.
     *
     * @var array<string, array<string, string|null>>
     */
    public const array CONSTRAINTS = [
        'workflow_history' => ['workflow_history_pk' => 'PRIMARY KEY (id)'],
    ];

    /**
     * Indexes per table; a non-null value is a fragment the live `indexdef` must contain. The composite
     * order is what makes the per-correlation history read cheap, and `CREATE INDEX IF NOT EXISTS` never
     * verifies that a homonym kept it.
     */
    public const array INDEXES = [
        'workflow_history' => [
            'workflow_history_corr_idx' => '(workflow_type, correlation_id, occurred_at, id)',
        ],
    ];

    /**
     * The declared shape as verification DATA, so the shared probe can read it.
     */
    public static function catalog(): SchemaCatalog
    {
        return new SchemaCatalog(self::COLUMNS, self::CONSTRAINTS, self::INDEXES, []);
    }
}
