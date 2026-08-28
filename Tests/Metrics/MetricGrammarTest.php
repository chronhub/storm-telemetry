<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Metrics;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\MetricsCollector;
use Storm\Telemetry\Metrics\MetricsExposition;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;

/**
 * The Prometheus grammar is enforced at the value objects, where an app collector's bad name is
 * refused before it can invalidate the whole scrape body; the exposition's backstop then turns the
 * refusal into an error sample, which is the difference between one dark block and a dead endpoint.
 */
final class MetricGrammarTest extends TestCase
{
    #[Test]
    public function a_family_name_outside_the_grammar_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MetricFamily::gauge('storm-saga-instances', 'a hyphen is not name grammar', []);
    }

    #[Test]
    public function a_label_name_outside_the_grammar_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricSample(['workflow-type' => 'transfer'], 1);
    }

    #[Test]
    public function a_reserved_double_underscore_label_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricSample(['__name__' => 'smuggled'], 1);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_family_refuses_a_metric_type_outside_its_public_union(): void
    {
        // the declared gauge|counter union is enforced at construction: an out-of-union type would
        // reach the renderer as an invalid Prometheus TYPE line and invalidate the scrape body
        $this->expectException(InvalidArgumentException::class);

        new MetricFamily('storm_jobs', 'histogram-ish', 'jobs', []); // @phpstan-ignore argument.type (deliberate: the string the declared counter|gauge union excludes)
    }

    #[Test]
    public function conformant_names_pass_and_label_values_stay_free_text(): void
    {
        $family = MetricFamily::gauge('storm_saga_instances', 'ok', [
            new MetricSample(['workflow_type' => 'transfer — any text, escaped by the renderer'], 1),
        ]);

        self::assertSame('storm_saga_instances', $family->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_collector_emitting_a_bad_name_goes_dark_alone_instead_of_killing_the_scrape(): void
    {
        $bad = new class() implements MetricsCollector
        {
            public function families(): array
            {
                return [MetricFamily::gauge('storm bad name', 'refused at the VO', [])];
            }
        };
        $healthy = new class() implements MetricsCollector
        {
            public function families(): array
            {
                return [MetricFamily::gauge('storm_ok', 'ok', [new MetricSample([], 1)])];
            }
        };

        $text = new MetricsExposition([$bad, $healthy], new PrometheusTextRenderer, $this->createStub(Connection::class), statementTimeoutMs: 0)->render();

        self::assertStringContainsString("storm_ok 1\n", $text);
        self::assertStringContainsString('error="InvalidArgumentException"', $text);
        self::assertStringNotContainsString('storm bad name', $text);
    }
}
