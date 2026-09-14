<?php

declare(strict_types=1);

namespace Storm\Telemetry\FailureRegistry;

/**
 * Reports authoring defects without executing operational references.
 */
final class FailureRegistryLint
{
    public const array STAGES = ['prevention', 'detection', 'resolution', 'rehearsal'];

    /**
     * @param  mixed[]  $data
     * @param  list<string>|null  $alertNames
     * @return list<string>
     */
    public function lint(array $data, ?array $alertNames = null): array
    {
        if (! isset($data['classes']) || ! is_array($data['classes']) || ! array_is_list($data['classes']) || $data['classes'] === []) {
            return ['registry.classes: expected a nonempty list.'];
        }
        $errors = [];
        $ids = [];
        foreach ($data['classes'] as $index => $entry) {
            if (! is_array($entry)) {
                $errors[] = 'classes.'.$index.': expected a mapping.';
                continue;
            }
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : 'classes.'.$index;
            if (! is_string($entry['id'] ?? null) || preg_match('/^[a-z][a-z0-9-]*$/D', $entry['id']) !== 1) {
                $errors[] = $id.'.id: expected a lowercase identifier.';
            }
            if (in_array($id, $ids, true)) {
                $errors[] = $id.'.id: duplicate class identifier.';
            }
            $ids[] = $id;
            if (! $this->text($entry['title'] ?? null)) {
                $errors[] = $id.'.title: expected nonempty text.';
            }
            foreach (array_diff(array_keys($entry), ['id', 'title', ...self::STAGES]) as $key) {
                $errors[] = $id.'.'.$key.': unknown class field.';
            }
            foreach (self::STAGES as $name) {
                $path = $id.'.'.$name;
                $stage = $entry[$name] ?? null;
                $state = is_array($stage) && is_string($stage['state'] ?? null) ? StageState::tryFrom($stage['state']) : null;
                if ($state === null) {
                    $errors[] = $path.': expected a stage with state available, hole or not_applicable.';
                    continue;
                }
                $required = match ($state) {
                    StageState::Hole => ['issue'],
                    StageState::NotApplicable => ['reason', 'decision'],
                    StageState::Available => match ($name) {
                        'resolution' => ['kind', 'reference', 'preconditions', 'expected_result'],
                        'rehearsal' => ['reference', 'initial_state', 'hypothesis', 'injection', 'observation', 'recovery', 'cleanup'],
                        default => ['kind', 'reference'],
                    },
                };
                foreach ($required as $field) {
                    $value = $stage[$field] ?? null;
                    $valid = $field === 'issue' ? is_int($value) && $value > 0 : $this->text($value);
                    if (! $valid) {
                        $errors[] = $path.'.'.$field.': expected '.($field === 'issue' ? 'a positive issue number.' : 'nonempty text.');
                    }
                }
                foreach (array_diff(array_keys($stage), ['state', ...$required, ...($state === StageState::Hole ? ['reason', 'decision'] : [])]) as $field) {
                    $errors[] = $path.'.'.$field.': unknown stage field.';
                }
                foreach ($stage as $field => $value) {
                    if (! is_string($value) && ! is_int($value)) {
                        $errors[] = $path.'.'.$field.': expected a scalar string or integer.';
                    }
                }
                if ($state !== StageState::Available) {
                    continue;
                }
                $kinds = match ($name) {
                    'prevention' => ['code', 'document'],
                    'detection' => ['alert', 'metric', 'structured_log', 'console', 'healthcheck'],
                    'resolution' => ['console', 'ops_endpoint', 'runbook', 'supervisor_action'],
                    default => [],
                };
                if ($kinds !== [] && ! in_array($stage['kind'] ?? null, $kinds, true)) {
                    $errors[] = $path.'.kind: unsupported reference kind.';
                }
                if ($name === 'detection' && ($stage['kind'] ?? null) === 'alert' && $alertNames !== null && ! in_array($stage['reference'] ?? null, $alertNames, true)) {
                    $errors[] = $path.'.reference: alert rule was not found in the supplied rules.';
                }
            }
        }

        return $errors;
    }

    private function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
