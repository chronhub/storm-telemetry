<?php

declare(strict_types=1);

namespace Storm\Telemetry\FailureRegistry;

use RuntimeException;

/**
 * Produces deterministic Markdown without interpreting references as commands or HTML.
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
            '| Failure class | Prevention | Detection | Resolution | Rehearsal |',
            '| --- | --- | --- | --- | --- |',
        ];
        foreach ($registry->classes as $class) {
            $cells = [$this->escape($class->title).' ['.$this->escape($class->id).']'];
            foreach ($class->stages as $stage) {
                $cell = $stage->state->value;
                foreach ($stage->details as $key => $value) {
                    $cell .= '<br>'.$this->escape($key).': ';
                    $cell .= $key === 'issue'
                        ? ($issueUrlBase === '' ? '#'.$value : '[#'.$value.']('.str_replace(['(', ')', '[', ']'], ['%28', '%29', '%5B', '%5D'], rtrim($issueUrlBase, '/')).'/'.$value.')')
                        : $this->escape((string) $value);
                }
                $cells[] = $cell;
            }
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines)."\n";
    }

    private function escape(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n", '|', '`', '[', ']', '*', '_'], [' ', ' ', ' ', '&#124;', '&#96;', '&#91;', '&#93;', '&#42;', '&#95;'], htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
