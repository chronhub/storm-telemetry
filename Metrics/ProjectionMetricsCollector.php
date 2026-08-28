<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Projector\Store\ProjectionCatalog;

/**
 * The projector block: per-projection lag against the safe-head floor, status and generation. Lag
 * is measured against `event_store_high_water`, the position every projection is allowed to read
 * to, so it counts events the projection COULD have folded and has not; events above the floor are
 * nobody's lag yet.
 *
 * Two collaborators because the two facts live apart under a split topology: the checkpoints come
 * from the catalog port, which routes each row to its home, and the floor is a single-row read on
 * the events side, where the event store keeps it. Joining them in one SQL statement would bind the
 * block to a single-database deployment and report an empty projector on every other one.
 *
 * An idle projection sits at whatever checkpoint it stopped on, so its lag climbs with the floor;
 * the value stays raw and the status family is the join key a query filters on.
 */
final readonly class ProjectionMetricsCollector implements MetricsCollector
{
    public function __construct(
        private ProjectionCatalog $catalog,
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure interrogating the tables
     */
    public function families(): array
    {
        if (! $this->connection->createSchemaManager()->tablesExist(['event_store_high_water'])) {
            return [];
        }

        $head = $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT position FROM event_store_high_water WHERE id = 1',
        );

        if ($head === false) {
            return [];
        }

        $lag = [];
        $status = [];
        $generation = [];

        foreach ($this->catalog->all() as $row) {
            $lag[] = new MetricSample(['projection' => $row->name], max((int) $head - $row->lastPosition, 0));
            $status[] = new MetricSample(['projection' => $row->name, 'status' => $row->status->value], 1);
            $generation[] = new MetricSample(['projection' => $row->name], $row->generation);
        }

        return [
            MetricFamily::gauge('storm_projection_lag', 'Events below the safe-head floor the projection has not folded yet', $lag),
            MetricFamily::gauge('storm_projection_status', 'Current status per projection, value always 1', $status),
            MetricFamily::gauge('storm_projection_generation', 'Rebuild generation per projection', $generation),
        ];
    }
}
