<?php

declare(strict_types=1);

namespace Storm\Telemetry\Console;

use RuntimeException;
use Storm\Telemetry\FailureRegistry\FailureRegistry;
use Storm\Telemetry\FailureRegistry\PrepareToFailRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ExceptionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks operational coverage and generates its documentation without running recovery actions.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:telemetry:prepare-to-fail --check
 * ```
 *
 * ```bash
 * bin/console storm:telemetry:prepare-to-fail --write
 * ```
 */
#[AsCommand(
    name: 'storm:telemetry:prepare-to-fail',
    description: 'Lint the failure registry and check or write its generated documentation',
    help: "Examples:\n\nbin/console storm:telemetry:prepare-to-fail --check\n\nbin/console storm:telemetry:prepare-to-fail --write",
)]
final readonly class PrepareToFailCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Check that the generated document is current.')] bool $check = false,
        #[Option(description: 'Write the generated document.')] bool $write = false,
        #[Option(description: 'Registry YAML file.')] string $registry = FailureRegistry::DEFAULT_PATH,
        #[Option(description: 'Generated Markdown destination, relative to the current directory.')] string $document = 'docs/operations/prepare-to-fail.md',
        #[Option(name: 'issue-url-base', description: 'Optional tracker URL prefix for links; omitted keeps issue numbers as text.')] string $issueUrlBase = '',
        #[Option(name: 'alert-rules', description: 'Prometheus rule file; omitted means the alert reference check is skipped.')] string $alertRules = '',
    ): int {
        if ($check === $write) {
            $output->writeln('Choose exactly one of --check or --write.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        }
        try {
            if (realpath($registry) !== false && realpath($registry) === realpath($document)) {
                throw new RuntimeException('The document must not overwrite the registry.');
            }
            $alerts = $alertRules === '' ? null : $this->alertNames($alertRules);
            $loaded = FailureRegistry::fromFile($registry, $alerts);
            $rendered = (new PrepareToFailRenderer)->render($loaded, $issueUrlBase);
            $output->writeln($alerts === null ? 'Alert reference check: skipped; no --alert-rules supplied.' : 'Alert reference check: passed against '.$alertRules, OutputInterface::OUTPUT_RAW);
            if ($check) {
                if (! is_file($document) || file_get_contents($document) !== $rendered) {
                    throw new RuntimeException('Generated document differs or is missing: '.$document.'. Run --write and review the diff.');
                }
            } else {
                if (! is_dir(dirname($document)) || ! is_writable(dirname($document))) {
                    throw new RuntimeException('Document directory is missing or not writable: '.dirname($document));
                }
                $temporary = tempnam(dirname($document), '.prepare-to-fail-');
                if ($temporary === false) {
                    throw new RuntimeException('Cannot create a temporary document.');
                }
                try {
                    if (file_put_contents($temporary, $rendered) !== strlen($rendered) || ! rename($temporary, $document)) {
                        throw new RuntimeException('Cannot write document: '.$document);
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            $output->writeln('Registry and document: OK.', OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (RuntimeException|ExceptionInterface $error) {
            $output->writeln($error->getMessage(), OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function alertNames(string $path): array
    {
        $data = Yaml::parseFile($path);
        if (! is_array($data) || ! is_array($data['groups'] ?? null)) {
            throw new RuntimeException('Alert rules: expected a groups list.');
        }
        $names = [];
        foreach ($data['groups'] as $group) {
            if (! is_array($group) || ! is_array($group['rules'] ?? null)) {
                throw new RuntimeException('Alert rules: each group requires a rules list.');
            }
            foreach ($group['rules'] as $rule) {
                if (! is_array($rule)) {
                    throw new RuntimeException('Alert rules: expected a rule mapping.');
                }
                if (array_key_exists('alert', $rule)) {
                    if (! is_string($rule['alert']) || trim($rule['alert']) === '') {
                        throw new RuntimeException('Alert rules: alert names must be nonempty strings.');
                    }
                    $names[] = $rule['alert'];
                }
            }
        }

        return $names;
    }
}
