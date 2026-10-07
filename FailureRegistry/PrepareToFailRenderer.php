<?php

declare(strict_types=1);

namespace Storm\Telemetry\FailureRegistry;

use RuntimeException;

/**
 * Produces deterministic Markdown without interpreting references as commands or HTML.
 *
 * The document opens on a coverage table that carries the four states and nothing else, each row
 * linking to the class section below it; the details of a stage are a list under their own heading,
 * so no cell ever holds a paragraph and the page reads at any width.
 */
final class PrepareToFailRenderer
{
    public function render(FailureRegistry $registry, string $issueUrlBase = ''): string
    {
        if ($issueUrlBase !== '') {
            $url = parse_url($issueUrlBase);
            if (filter_var($issueUrlBase, FILTER_VALIDATE_URL) === false || ! is_array($url) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['query']) || isset($url['fragment']) || isset($url['user']) || isset($url['pass'])) {
                throw new RuntimeException('Issue URL base must be an HTTP or HTTPS URL without credentials, query or fragment.');
            }
        }
        $lines = [
            '# Prepare to fail',
            '',
            'Generated from the Telemetry package registry, `src/Telemetry/resources/prepare-to-fail.yaml`. Do not edit this page directly.',
            '',
            'Available means a documented mechanism exists, not that a service is healthy or a recovery has been rehearsed. Holes remain open work; not applicable requires a recorded decision. References are descriptive and are never executed by this registry.',
            '',
            '## Coverage',
            '',
            '| Failure class | '.implode(' | ', array_map(ucfirst(...), FailureRegistryLint::STAGES)).' |',
            '| '.implode(' | ', array_fill(
                // `implode()` joins by value, so the start index is an equivalent mutant; it sits on a line
                // of its own so the gate's configuration leaves out that line alone, the column count
                // below staying killed
                0,
                count(FailureRegistryLint::STAGES) + 1,
                '---'
            )).' |',
            ...array_map(
                fn (FailureClass $class): string => '| ['.$this->escape($class->title).'](#'.$class->id.') | '.implode(' | ', array_map(static fn (Stage $stage): string => $stage->state->value, $class->stages)).' |',
                $registry->classes,
            ),
        ];
        foreach ($registry->classes as $class) {
            $lines[] = '';
            $lines[] = '## '.$this->escape($class->title).' {#'.$class->id.'}';
            foreach ($class->stages as $name => $stage) {
                $lines[] = '';
                $lines[] = '### '.ucfirst($name).': '.$stage->state->value.' {#'.$class->id.'-'.$name.'}';
                if ($stage->details === []) {
                    continue;
                }
                $lines[] = '';
                foreach ($stage->details as $key => $value) {
                    $lines[] = '- `'.$key.'`: '.($key === 'issue'
                        ? $this->issue($value, $issueUrlBase)
                        : $this->escape((string) $value));
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function issue(string|int $number, string $issueUrlBase): string
    {
        if ($issueUrlBase === '') {
            return '#'.$number;
        }

        return '[#'.$number.']('.str_replace(['(', ')', '[', ']'], ['%28', '%29', '%5B', '%5D'], rtrim($issueUrlBase, '/')).'/'.$number.')';
    }

    private function escape(string $text): string
    {
        return str_replace(
            ["\r\n", "\r", "\n", '|', '`', '[', ']', '*', '_', '{', '}'],
            [' ', ' ', ' ', '&#124;', '&#96;', '&#91;', '&#93;', '&#42;', '&#95;', '&#123;', '&#125;'],
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
