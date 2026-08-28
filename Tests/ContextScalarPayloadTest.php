<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Storm\Chronicler\Telemetry\AppendContext;
use Storm\Chronicler\Telemetry\LoadContext;
use Storm\Chronicler\Telemetry\OccConflictContext;
use Storm\Projector\Telemetry\BatchContext;
use Storm\Projector\Telemetry\ListenerFailureContext;
use Storm\Projector\Telemetry\RunContext;

/**
 * Structural guard, the flattening's mirror of `SagaEventScalarPayloadTest`: `StormObservability`
 * flattens every Context DTO with a blind `get_object_vars()`, so an object-typed property added to
 * any of them would land in the log context unnoticed, unserialized by design and unhandled by the
 * facade. The two `Throwable` carriers the facade extracts BY HAND are the only sanctioned
 * exceptions; a third object property must either become scalar or join the hand-handled set, and
 * this test is where that decision surfaces.
 */
final class ContextScalarPayloadTest extends TestCase
{
    /** The object-typed properties the facade extracts by hand before logging. */
    private const array HAND_HANDLED = [
        RunContext::class.'::error',
        ListenerFailureContext::class.'::error',
    ];

    private const array CONTEXTS = [
        AppendContext::class,
        LoadContext::class,
        OccConflictContext::class,
        BatchContext::class,
        RunContext::class,
        ListenerFailureContext::class,
    ];

    #[Test]
    public function every_flattened_context_property_is_scalar_or_a_hand_handled_throwable(): void
    {
        foreach (self::CONTEXTS as $context) {
            foreach (new ReflectionClass($context)->getProperties() as $property) {
                $key = $context.'::'.$property->getName();
                $type = $property->getType();

                self::assertInstanceOf(ReflectionNamedType::class, $type, $key.' must carry a single named type');

                if (in_array($key, self::HAND_HANDLED, true)) {
                    continue;
                }

                self::assertTrue(
                    $type->isBuiltin(),
                    $key.' is object-typed and NOT hand-handled by StormObservability: it would flatten into the log context blind',
                );
            }
        }
    }
}
