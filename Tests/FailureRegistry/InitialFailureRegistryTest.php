<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\FailureRegistry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\FailureRegistry\FailureRegistry;
use Storm\Telemetry\FailureRegistry\StageState;

final class InitialFailureRegistryTest extends TestCase
{
    #[Test]
    public function it_contains_sixteen_classes_disposed_on_every_stage(): void
    {
        $registry = FailureRegistry::fromFile();
        self::assertCount(16, $registry->classes);
        $ids = [];
        $holes = [];
        foreach ($registry->classes as $class) {
            $ids[] = $class->id;
            self::assertCount(4, $class->stages);
            foreach ($class->stages as $name => $stage) {
                if ($stage->state === StageState::Hole) {
                    $holes[] = $class->id.'.'.$name;
                }
            }
        }
        self::assertCount(16, array_unique($ids));
        // every class is disposed on its four stages; a hole that reappears anywhere fails by name
        self::assertSame([], $holes);
    }
}
