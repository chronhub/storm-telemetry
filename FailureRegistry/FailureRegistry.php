<?php

declare(strict_types=1);

namespace Storm\Telemetry\FailureRegistry;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads validated failure classes from a package-local registry.
 */
final readonly class FailureRegistry
{
    public const string DEFAULT_PATH = __DIR__.'/../resources/prepare-to-fail.yaml';

    /**
     * @param  list<FailureClass>  $classes
     */
    private function __construct(public array $classes) {}

    /**
     * @param  list<string>|null  $alertNames
     */
    public static function fromFile(string $path = self::DEFAULT_PATH, ?array $alertNames = null): self
    {
        $data = Yaml::parseFile($path);
        if (! is_array($data)) {
            throw new RuntimeException('registry: expected a mapping.');
        }
        $errors = (new FailureRegistryLint)->lint($data, $alertNames);
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }
        $classes = [];
        foreach ($data['classes'] as $entry) {
            $stages = [];
            foreach (FailureRegistryLint::STAGES as $name) {
                $details = $entry[$name];
                $state = StageState::from($details['state']);
                unset($details['state']);
                $stages[$name] = new Stage($state, $details);
            }
            $classes[] = new FailureClass($entry['id'], $entry['title'], $stages);
        }

        return new self($classes);
    }
}
