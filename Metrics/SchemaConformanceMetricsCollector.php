<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;
use Storm\Support\Dbal\SchemaProbe;
use Storm\Telemetry\Schema\SchemaConformanceTarget;

/**
 * The schema block: how many divergences each module's live schema shows against its catalog.
 *
 * It is the scrape-time counterpart of the conformance check, so a drift by hand between two installs
 * is graphed and alerted on before a write fails. A module whose first table is absent is not
 * installed and reports no sample. Each scrape interrogates the catalogs of every table the targets
 * name, a cost repeated on the scrape's timer that is the price of reading drift without waiting for
 * a deployment.
 */
final readonly class SchemaConformanceMetricsCollector implements MetricsCollector
{
    /**
     * @param  iterable<SchemaConformanceTarget>  $targets
     */
    public function __construct(
        private Connection $connection,
        private iterable $targets = [],
    ) {}

    public function families(): array
    {
        $samples = [];
        foreach ($this->targets as $target) {
            $connection = $target->connection ?? $this->connection;
            if (! $connection->createSchemaManager()->tablesExist([$target->tables[0]])) {
                continue;
            }
            $samples[] = new MetricSample(['module' => $target->module], count(new SchemaProbe()->problems($connection, $target->catalog, $target->tables)));
        }

        return $samples === [] ? [] : [
            MetricFamily::gauge('storm_schema_problems', 'Divergences between the live schema and what each installed module declares', $samples),
        ];
    }
}
