<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\FailureRegistry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\FailureRegistry\FailureRegistry;
use Storm\Telemetry\FailureRegistry\PrepareToFailRenderer;
use Symfony\Component\Yaml\Yaml;

final class PrepareToFailRendererTest extends TestCase
{
    #[Test]
    public function it_renders_one_row_per_class_with_four_stages(): void
    {
        $text = (new PrepareToFailRenderer)->render(FailureRegistry::fromFile());
        $rows = array_values(array_filter(explode("\n", $text), static fn (string $line): bool => str_starts_with($line, '| ')));
        self::assertCount(18, $rows);
        foreach ($rows as $row) {
            self::assertSame(6, substr_count($row, '|'));
        }
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
            self::assertStringContainsString('| hole<br>', $text);
            self::assertStringContainsString('| not_applicable<br>', $text);
            self::assertStringNotContainsString('| available', $text);
            self::assertStringContainsString('&lt;script&gt;&#124;', $text);
            self::assertStringContainsString('issue: #504', $text);
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
    private function registryWithOneHole(int $issue): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ptf-');
        self::assertNotFalse($file);
        $entry = ['id' => 'example', 'title' => 'Example'];
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
}
