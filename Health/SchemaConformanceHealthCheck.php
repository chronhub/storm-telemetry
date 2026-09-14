<?php

declare(strict_types=1);

namespace Storm\Telemetry\Health;

use Doctrine\DBAL\Connection;
use Storm\Support\Dbal\SchemaProbe;
use Storm\Telemetry\Schema\SchemaConformanceTarget;
use Throwable;

/**
 * Whether the installed schema still matches what each module declares, read between two installs:
 * the same catalog interrogation the installers run inside their transaction, run on demand against
 * the live schema.
 *
 * An install proves its schema and rolls back otherwise; what drifts afterwards, an index or a
 * constraint dropped by hand, a column type changed, is read by nothing until a write fails. This
 * check reads it: `Degraded` from the first divergence, naming the module, the problems and the
 * installer whose re-run converges what its DDL knows how to recreate; `Ok` naming the modules
 * verified. A module whose first table is absent is not installed and reads as such. `Down` is
 * reserved for a failing catalog query. It interrogates the catalogs of every table it is given,
 * a cost per call that a health endpoint, not a scrape loop, is meant to pay.
 */
final readonly class SchemaConformanceHealthCheck implements HealthCheck
{
    /**
     * @param  iterable<SchemaConformanceTarget>  $targets
     */
    public function __construct(
        private Connection $connection,
        private iterable $targets = [],
    ) {}

    public function name(): string
    {
        return 'schema_conformance';
    }

    /**
     * {@inheritDoc}
     *
     * @infection-ignore-all a wired body: the verdicts read a live schema, and the integration suite
     *                       proves them against one; the unit suite reaches this method only through
     *                       a kernel boot whose DSN names a closed port.
     */
    public function check(): HealthCheckResult
    {
        try {
            $verified = [];
            $absent = [];
            $degraded = [];
            foreach ($this->targets as $target) {
                $connection = $target->connection ?? $this->connection;
                if (! $connection->createSchemaManager()->tablesExist([$target->tables[0]])) {
                    $absent[] = $target->module;

                    continue;
                }
                $problems = new SchemaProbe()->problems($connection, $target->catalog, $target->tables);
                if ($problems === []) {
                    $verified[] = $target->module;

                    continue;
                }
                $degraded[] = sprintf('%s: %d divergence(s), %s — re-run %s, which converges what its DDL recreates and refuses the rest', $target->module, count($problems), implode('; ', array_slice($problems, 0, 3)), $target->installer);
            }
            $reasons = $absent === [] ? '' : sprintf('; %s not installed', implode(', ', array_map(static fn (string $m): string => $m.' is', $absent)));

            if ($degraded !== []) {
                return HealthCheckResult::degraded(implode(' | ', $degraded).$reasons);
            }

            return HealthCheckResult::ok(sprintf('schema verified for %s%s', $verified === [] ? 'no module' : implode(', ', $verified), $reasons));
        } catch (Throwable $e) {
            // class only, never the message: this result lands in an HTTP body
            return HealthCheckResult::down('schema conformance query failed ('.$e::class.')');
        }
    }
}
