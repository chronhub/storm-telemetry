<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Metrics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every collector class under `Metrics/` is set by hand in the package's `config/services.php`,
 * since that directory is excluded from the automatic load: a collector left out compiles, tests
 * green in isolation, and is never scraped.
 */
final class EveryCollectorIsWiredTest extends TestCase
{
    #[Test]
    public function every_collector_class_is_set_in_the_package_services(): void
    {
        $services = (string) file_get_contents(dirname(__DIR__, 2).'/config/services.php');
        $files = glob(dirname(__DIR__, 2).'/Metrics/*MetricsCollector.php');
        self::assertNotFalse($files);
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $class = basename($file, '.php');
            if ($class === 'MetricsCollector') {
                continue; // the interface, not a collector
            }
            self::assertStringContainsString('->set('.$class.'::class)', $services, sprintf('%s is not set in config/services.php: it compiles, tests green in isolation, and is never scraped.', $class));
        }
    }
}
