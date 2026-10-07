<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\FailureRegistry;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\Console\PrepareToFailCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

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

    #[Test]
    public function a_run_without_a_mode_says_which_mode_to_choose(): void
    {
        $output = new BufferedOutput;

        self::assertSame(2, (new PrepareToFailCommand)($output));
        self::assertSame("Choose exactly one of --check or --write.\n", $output->fetch());
    }

    #[Test]
    public function a_written_document_is_reported_ok(): void
    {
        $output = new BufferedOutput;

        self::assertSame(0, (new PrepareToFailCommand)($output, write: true, document: $this->directory.'/output.md'));
        self::assertStringContainsString("Registry and document: OK.\n", $output->fetch());
    }

    #[Test]
    public function the_document_never_overwrites_its_own_registry(): void
    {
        $registry = $this->registry();
        $before = file_get_contents($registry);
        $output = new BufferedOutput;

        self::assertSame(1, (new PrepareToFailCommand)($output, write: true, registry: $registry, document: $registry));
        self::assertStringContainsString('The document must not overwrite the registry.', $output->fetch());
        self::assertSame($before, file_get_contents($registry));
    }

    #[Test]
    public function a_document_whose_directory_is_a_file_is_refused_before_any_write(): void
    {
        $blocker = $this->directory.'/blocker';
        file_put_contents($blocker, '');
        $output = new BufferedOutput;

        self::assertSame(1, (new PrepareToFailCommand)($output, write: true, document: $blocker.'/output.md'));
        self::assertStringContainsString('Document directory is missing or not writable: '.$blocker, $output->fetch());
    }

    #[Test]
    public function a_failed_write_leaves_no_temporary_file_behind(): void
    {
        // a document path that is a directory: the temporary file lands beside it, and the rename over a
        // directory fails, which PHP reports with a warning read here instead of letting it through
        $document = $this->directory.'/output.md';
        mkdir($document);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $exit = (new PrepareToFailCommand)(new BufferedOutput, write: true, document: $document);
        } finally {
            restore_error_handler();
            rmdir($document);
        }

        self::assertSame(1, $exit);
        self::assertSame([], glob($this->directory.'/.prepare-to-fail-*'));
        self::assertNotSame([], $warnings);
    }

    #[Test]
    public function well_formed_alert_rules_pass_the_reference_check(): void
    {
        $rules = $this->directory.'/rules.yaml';
        file_put_contents($rules, Yaml::dump(['groups' => [['name' => 'storm', 'rules' => [['alert' => 'BrokerDown', 'expr' => 'up == 0']]]]], 8));
        $output = new BufferedOutput;

        self::assertSame(0, (new PrepareToFailCommand)($output, write: true, registry: $this->registry(), document: $this->directory.'/output.md', alertRules: $rules));
        self::assertStringContainsString('Alert reference check: passed against '.$rules, $output->fetch());
    }

    #[Test]
    public function every_alert_of_every_group_is_read_for_the_reference_check(): void
    {
        // the alert the registry detects by sits second in its group, beside a recording rule that names
        // no alert, and a second group follows
        $rules = $this->rules([
            ['name' => 'storm', 'rules' => [['alert' => 'OutboxStalled', 'expr' => 'up == 0'], ['record' => 'storm:lag', 'expr' => 'up'], ['alert' => 'BrokerDown', 'expr' => 'up == 0']]],
            ['name' => 'bank', 'rules' => [['alert' => 'LedgerDrift', 'expr' => 'up == 0']]],
        ]);
        $registry = $this->registry(['state' => 'available', 'kind' => 'alert', 'reference' => 'BrokerDown']);
        $output = new BufferedOutput;

        self::assertSame(0, (new PrepareToFailCommand)($output, write: true, registry: $registry, document: $this->directory.'/output.md', alertRules: $rules));
        self::assertStringContainsString('Alert reference check: passed against '.$rules, $output->fetch());
    }

    #[Test]
    public function a_group_without_its_rules_list_is_refused(): void
    {
        $output = new BufferedOutput;

        self::assertSame(1, (new PrepareToFailCommand)($output, write: true, registry: $this->registry(), document: $this->directory.'/output.md', alertRules: $this->rules([['name' => 'storm']])));
        self::assertStringContainsString('Alert rules: each group requires a rules list.', $output->fetch());
    }

    #[Test]
    #[DataProvider('alertNamesThatAreNoText')]
    public function an_alert_name_must_be_nonempty_text(mixed $alert): void
    {
        $output = new BufferedOutput;
        $rules = $this->rules([['name' => 'storm', 'rules' => [['alert' => $alert, 'expr' => 'up == 0']]]]);

        self::assertSame(1, (new PrepareToFailCommand)($output, write: true, registry: $this->registry(), document: $this->directory.'/output.md', alertRules: $rules));
        self::assertStringContainsString('Alert rules: alert names must be nonempty strings.', $output->fetch());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function alertNamesThatAreNoText(): iterable
    {
        yield 'a number' => [42];
        yield 'blanks' => ['   '];
    }

    /**
     * A registry of one class whose stages are holes, detection aside when one is given.
     *
     * @param  array<string, string>|null  $detection
     */
    private function registry(?array $detection = null): string
    {
        $file = $this->directory.'/registry.yaml';
        $entry = ['id' => 'example', 'title' => 'Example'];
        foreach (['prevention', 'detection', 'resolution', 'rehearsal'] as $stage) {
            $entry[$stage] = ['state' => 'hole', 'issue' => 511];
        }
        if ($detection !== null) {
            $entry['detection'] = $detection;
        }
        file_put_contents($file, Yaml::dump(['classes' => [$entry]], 8));

        return $file;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function rules(array $groups): string
    {
        $file = $this->directory.'/rules.yaml';
        file_put_contents($file, Yaml::dump(['groups' => $groups], 8));

        return $file;
    }
}
