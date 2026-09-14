<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\Console\MetricsCommand;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\MetricsCollector;
use Storm\Telemetry\Metrics\MetricsExposition;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class MetricsCommandTest extends TestCase
{
    #[Test]
    public function console_metrics_preserve_prometheus_text_without_console_formatting(): void
    {
        $collector = new class() implements MetricsCollector
        {
            public function families(): array
            {
                return [MetricFamily::gauge('storm_raw', '<info>help</info>', [new MetricSample([], 1)])];
            }
        };
        $exposition = new MetricsExposition([$collector], new PrometheusTextRenderer, $this->createStub(Connection::class), statementTimeoutMs: 0);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        self::assertSame(Command::SUCCESS, new MetricsCommand($exposition)($output));
        self::assertSame("# HELP storm_raw <info>help</info>\n# TYPE storm_raw gauge\nstorm_raw 1\n", $output->fetch());
    }
}
