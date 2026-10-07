<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\FailureRegistry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\FailureRegistry\FailureRegistry;

/**
 * A registry file is refused before any class is built: a document that is not a mapping, then a
 * mapping the lint rejects, with every lint error named.
 */
final class FailureRegistryTest extends TestCase
{
    #[Test]
    public function a_document_that_is_not_a_mapping_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('registry: expected a mapping.');

        $this->load("just a sentence\n");
    }

    #[Test]
    public function a_registry_the_lint_rejects_is_refused_with_every_error_on_its_own_line(): void
    {
        try {
            $this->load("classes:\n  - first\n  - second\n");
            self::fail('a registry the lint rejects must not load');
        } catch (RuntimeException $e) {
            self::assertSame("classes.0: expected a mapping.\nclasses.1: expected a mapping.", $e->getMessage());
        }
    }

    private function load(string $yaml): FailureRegistry
    {
        $file = tempnam(sys_get_temp_dir(), 'ptf-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, $yaml);

            return FailureRegistry::fromFile($file);
        } finally {
            unlink($file);
        }
    }
}
