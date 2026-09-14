<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\FailureRegistry;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\Console\PrepareToFailCommand;
use Symfony\Component\Console\Output\BufferedOutput;

final class PrepareToFailCommandTest extends TestCase
{
    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptf-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function it_fails_when_the_document_diverges(): void
    {
        $command = new PrepareToFailCommand;
        $output = new BufferedOutput;
        $document = $this->directory.'/output.md';
        self::assertSame(0, $command($output, write: true, document: $document));
        self::assertSame(0, $command($output, check: true, document: $document));
        file_put_contents($document, 'drift');
        self::assertSame(1, $command($output, check: true, document: $document));
        self::assertSame('drift', file_get_contents($document));
        self::assertStringContainsString('differs or is missing', $output->fetch());
    }

    #[Test]
    public function it_declares_the_alert_check_skipped_without_rules(): void
    {
        $output = new BufferedOutput;
        self::assertSame(0, (new PrepareToFailCommand)($output, write: true, document: $this->directory.'/output.md'));
        self::assertStringContainsString('Alert reference check: skipped', $output->fetch());
    }

    #[Test]
    public function it_rejects_malformed_input_without_overwriting_the_document(): void
    {
        $registry = $this->directory.'/registry.yaml';
        $document = $this->directory.'/output.md';
        file_put_contents($registry, 'classes: [');
        file_put_contents($document, 'keep');
        self::assertSame(1, (new PrepareToFailCommand)(new BufferedOutput, write: true, registry: $registry, document: $document));
        self::assertSame('keep', file_get_contents($document));
    }

    #[Test]
    public function it_refuses_conflicting_modes_and_unknown_alert_references(): void
    {
        $command = new PrepareToFailCommand;
        $output = new BufferedOutput;
        self::assertSame(2, $command($output));
        self::assertSame(2, $command($output, check: true, write: true));
        $rules = $this->directory.'/rules.yaml';
        file_put_contents($rules, 'groups: broken');
        self::assertSame(1, $command($output, write: true, document: $this->directory.'/output.md', alertRules: $rules));
        self::assertFileDoesNotExist($this->directory.'/output.md');
    }
}
