<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\Metrics;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;

/**
 * The exposition text a scraper ingests, byte for byte: headers per family, one line per sample,
 * label escaping per the spec, and no orphan headers for an empty family.
 */
final class PrometheusTextRendererTest extends TestCase
{
    private PrometheusTextRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PrometheusTextRenderer;
    }

    #[Test]
    public function a_family_renders_headers_then_one_line_per_sample(): void
    {
        $text = $this->renderer->render([
            MetricFamily::gauge('storm_saga_instances', 'Saga instances by workflow type and status', [
                new MetricSample(['workflow_type' => 'onboarding', 'status' => 'running'], 3),
                new MetricSample(['workflow_type' => 'transfer', 'status' => 'completed'], 12),
            ]),
        ]);

        self::assertSame(
            "# HELP storm_saga_instances Saga instances by workflow type and status\n"
            ."# TYPE storm_saga_instances gauge\n"
            ."storm_saga_instances{workflow_type=\"onboarding\",status=\"running\"} 3\n"
            ."storm_saga_instances{workflow_type=\"transfer\",status=\"completed\"} 12\n",
            $text,
        );
    }

    #[Test]
    public function a_label_free_sample_renders_the_bare_name(): void
    {
        $text = $this->renderer->render([
            MetricFamily::gauge('storm_inbox_rows', 'Inbox rows', [new MetricSample([], 42)]),
        ]);

        self::assertStringContainsString("storm_inbox_rows 42\n", $text);
    }

    #[Test]
    public function an_empty_family_emits_no_orphan_headers(): void
    {
        $text = $this->renderer->render([
            MetricFamily::gauge('storm_projection_lag', 'Lag', []),
            MetricFamily::gauge('storm_inbox_rows', 'Inbox rows', [new MetricSample([], 1)]),
        ]);

        self::assertStringNotContainsString('storm_projection_lag', $text);
        self::assertStringContainsString('storm_inbox_rows 1', $text);
    }

    #[Test]
    #[Group('adversarial')]
    public function label_values_escape_backslash_quote_and_newline(): void
    {
        // A workflow name is app-authored text: unescaped, a quote would break the sample line and
        // the whole scrape with it, the parser refusing mid-body.
        $text = $this->renderer->render([
            MetricFamily::gauge('storm_saga_instances', 'help', [
                new MetricSample(['workflow_type' => 'a"b\\c'."\n".'d'], 1),
            ]),
        ]);

        self::assertStringContainsString('storm_saga_instances{workflow_type="a\"b\\\\c\nd"} 1', $text);
    }

    #[Test]
    public function float_values_render_in_decimal_form(): void
    {
        $text = $this->renderer->render([
            MetricFamily::gauge('storm_gauge', 'help', [new MetricSample([], 1.5)]),
        ]);

        self::assertStringContainsString('storm_gauge 1.5', $text);
    }

    #[Test]
    #[Group('adversarial')]
    public function non_finite_values_render_as_the_spec_tokens_never_php_cast_words(): void
    {
        // PHP casts INF and NAN to "INF" / "NAN", tokens the Prometheus parser rejects, and one
        // rejected line kills the whole body; the format's own spellings are +Inf, -Inf and NaN
        $text = $this->renderer->render([
            MetricFamily::gauge('storm_gauge', 'help', [
                new MetricSample(['edge' => 'inf'], INF),
                new MetricSample(['edge' => 'ninf'], -INF),
                new MetricSample(['edge' => 'nan'], NAN),
            ]),
        ]);

        self::assertStringContainsString('storm_gauge{edge="inf"} +Inf', $text);
        self::assertStringContainsString('storm_gauge{edge="ninf"} -Inf', $text);
        self::assertStringContainsString('storm_gauge{edge="nan"} NaN', $text);
        self::assertStringNotContainsString('INF', $text);
        self::assertStringNotContainsString('NAN', $text);
    }
}
