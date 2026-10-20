<?php

declare(strict_types=1);

namespace Storm\Telemetry\Console;

use Storm\Telemetry\Metrics\MetricsExposition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the Prometheus text exposition the HTTP metrics surface serves, raw and unstyled, so an
 * operator reads at the terminal what a scraper ingests and a pipe to `grep` or `promtool` works.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:telemetry:metrics
 * ```
 *
 * ```bash
 * bin/console storm:telemetry:metrics | grep storm_saga_instances
 * ```
 */
// An invokable command has no `setHelp()` for the harvesting pass to reach, so the examples above are
// repeated in the attribute's `help`; the pass fails the build for any sibling that omits them.
#[AsCommand(
    name: 'storm:telemetry:metrics',
    description: 'Print the ops metrics in Prometheus text exposition format',
    help: <<<'HELP'
        Examples:

        bin/console storm:telemetry:metrics

        bin/console storm:telemetry:metrics | grep storm_saga_instances
        HELP,
)]
final readonly class MetricsCommand
{
    public function __construct(
        private MetricsExposition $exposition,
    ) {}

    public function __invoke(OutputInterface $output): int
    {
        $output->write($this->exposition->render(), false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
