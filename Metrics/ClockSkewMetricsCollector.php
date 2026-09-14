<?php

declare(strict_types=1);

namespace Storm\Telemetry\Metrics;

use Doctrine\DBAL\Connection;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Telemetry\Health\ClockSkew;

/**
 * The clock block: the application's clock against the database's, signed, positive when the
 * application runs ahead. One sample, read on every scrape, so a host whose time jumps shows as
 * a step and a host that drifts shows as a slope.
 */
final readonly class ClockSkewMetricsCollector implements MetricsCollector
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private Connection $connection,
        private Clock $clock,
    ) {}

    /**
     * @return list<MetricFamily>
     */
    public function families(): array
    {
        return [
            MetricFamily::gauge('storm_clock_skew_seconds', 'Application clock minus database clock, in seconds, positive when the application runs ahead', [
                new MetricSample([], ClockSkew::read($this->connection, $this->clock)),
            ]),
        ];
    }
}
