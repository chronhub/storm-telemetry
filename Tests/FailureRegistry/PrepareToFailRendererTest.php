<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\FailureRegistry;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\FailureRegistry\FailureRegistry;
use Storm\Telemetry\FailureRegistry\FailureRegistryLint;
use Storm\Telemetry\FailureRegistry\PrepareToFailRenderer;
use Symfony\Component\Yaml\Yaml;

final class PrepareToFailRendererTest extends TestCase
{
    #[Test]
    public function it_renders_a_summary_row_per_class_carrying_states_alone(): void
    {
        $registry = FailureRegistry::fromFile();
        $text = (new PrepareToFailRenderer)->render($registry);
        $rows = array_values(array_filter(explode("\n", $text), static fn (string $line): bool => str_starts_with($line, '| ')));
        self::assertCount(count($registry->classes) + 2, $rows);
        foreach ($rows as $row) {
            self::assertSame(6, substr_count($row, '|'));
        }
        foreach (array_slice($rows, 2) as $row) {
            self::assertStringNotContainsString('<br>', $row);
            self::assertStringNotContainsString('reference: ', $row);
            self::assertMatchesRegularExpression('/^\| \[[^]]+]\(#[a-z][a-z0-9-]*\)(?: \| (?:available|hole|not_applicable)){4} \|$/D', $row);
        }
    }

    #[Test]
    public function it_renders_one_anchored_section_per_class_with_four_stage_subsections(): void
    {
        $registry = FailureRegistry::fromFile();
        $text = (new PrepareToFailRenderer)->render($registry);
        foreach ($registry->classes as $class) {
            self::assertStringContainsString("\n## ".$class->title.' {#'.$class->id."}\n", $text);
            self::assertStringContainsString('](#'.$class->id.')', $text);
        }
        foreach (FailureRegistryLint::STAGES as $stage) {
            self::assertSame(count($registry->classes), substr_count($text, "\n### ".ucfirst($stage).': '));
            foreach ($registry->classes as $class) {
                self::assertStringContainsString(' {#'.$class->id.'-'.$stage."}\n", $text);
            }
        }
    }

    #[Test]
    public function it_renders_every_stage_detail_as_its_own_list_item(): void
    {
        $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile());
        self::assertStringContainsString("### Prevention: available {#append-conflict-prevention}\n\n- `kind`: code\n- `reference`: src/Chronicler/Exception/ConcurrencyException.php\n", $text);
        self::assertStringNotContainsString('<br>', $text);
    }

    #[Test]
    public function it_does_not_render_a_hole_or_not_applicable_as_available(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ptf-');
        self::assertNotFalse($file);
        try {
            $entry = ['id' => 'example', 'title' => '<script>| unsafe'];
            foreach (['prevention', 'detection', 'resolution'] as $stage) {
                $entry[$stage] = ['state' => 'hole', 'issue' => 504];
            }
            $entry['rehearsal'] = ['state' => 'not_applicable', 'reason' => 'No broker', 'decision' => 'Decision 504'];
            file_put_contents($file, Yaml::dump(['classes' => [$entry]], 8));
            $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile($file));
            self::assertStringContainsString('### Prevention: hole', $text);
            self::assertStringContainsString('### Rehearsal: not_applicable', $text);
            self::assertStringNotContainsString('available', $text);
            self::assertStringContainsString('&lt;script&gt;&#124; unsafe', $text);
            self::assertStringContainsString('- `issue`: #504', $text);
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function it_does_not_let_a_title_hijack_the_section_anchor(): void
    {
        $file = $this->registryWithOneHole(511, 'Hijack {#elsewhere}');
        try {
            $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile($file));
            self::assertStringContainsString('## Hijack &#123;#elsewhere&#125; {#example}', $text);
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function it_omits_private_tracker_links_by_default(): void
    {
        $file = $this->registryWithOneHole(511);
        try {
            $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile($file));
            self::assertStringNotContainsString('http://gitlab.test', $text);
            self::assertStringContainsString('#511', $text);
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function it_links_only_to_an_explicit_tracker(): void
    {
        $file = $this->registryWithOneHole(511);
        try {
            $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile($file), 'https://tracker.example/issues/');
            self::assertStringContainsString('[#511](https://tracker.example/issues/511)', $text);
        } finally {
            unlink($file);
        }
    }

    /**
     * A registry of one class whose four stages are holes naming the issue: the shipped registry no longer holds
     * a hole once every class is disposed, so the link rendering is proven on a planted one.
     */
    private function registryWithOneHole(int $issue, string $title = 'Example'): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ptf-');
        self::assertNotFalse($file);
        $entry = ['id' => 'example', 'title' => $title];
        foreach (['prevention', 'detection', 'resolution', 'rehearsal'] as $stage) {
            $entry[$stage] = ['state' => 'hole', 'issue' => $issue];
        }
        file_put_contents($file, Yaml::dump(['classes' => [$entry]], 8));

        return $file;
    }

    #[Test]
    public function it_rejects_an_executable_link_scheme(): void
    {
        $this->expectException(RuntimeException::class);
        (new PrepareToFailRenderer)->render(FailureRegistry::fromFile(), 'javascript:alert(1)');
    }

    #[Test]
    #[DataProvider('trackerBasesItRefuses')]
    public function it_refuses_a_tracker_base_that_is_not_a_plain_http_location(string $base): void
    {
        $this->expectException(RuntimeException::class);

        (new PrepareToFailRenderer)->render(FailureRegistry::fromFile(), $base);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function trackerBasesItRefuses(): iterable
    {
        yield 'an invalid URL that still parses' => ['http://tracker example/issues'];
        yield 'a scheme other than HTTP' => ['ftp://tracker.example/issues'];
        yield 'a query' => ['https://tracker.example/issues?page=1'];
        yield 'a fragment' => ['https://tracker.example/issues#top'];
        yield 'a user without a password' => ['https://ops@tracker.example/issues'];
    }

    #[Test]
    public function the_page_opens_on_its_title_and_a_capitalized_coverage_header(): void
    {
        $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile());

        self::assertStringStartsWith("# Prepare to fail\n", $text);
        self::assertStringContainsString("\n| Failure class | Prevention | Detection | Resolution | Rehearsal |\n| --- | --- | --- | --- | --- |\n", $text);
    }

    #[Test]
    public function a_tracker_link_escapes_the_parentheses_of_its_base(): void
    {
        // a parenthesis left raw would close the Markdown link early
        $file = $this->registryWithOneHole(511);
        try {
            $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile($file), 'https://tracker.example/issues(ops)');
            self::assertStringContainsString('[#511](https://tracker.example/issues%28ops%29/511)', $text);
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function a_quote_in_a_title_is_escaped(): void
    {
        $file = $this->registryWithOneHole(511, 'Broker "down"');
        try {
            $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile($file));
            self::assertStringContainsString('## Broker &quot;down&quot; {#example}', $text);
        } finally {
            unlink($file);
        }
    }
}
