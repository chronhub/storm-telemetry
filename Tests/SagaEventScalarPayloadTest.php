<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Storm\Saga\Event\SagaStarted;
use Storm\Telemetry\History\SagaHistoryEntry;

/**
 * The telemetry half of the no-decrypt doctrine, PINNED rather than assumed: `workflow_history`
 * stores each `Saga*` lifecycle event's public properties as its payload, so the ONLY way a domain
 * event's personal data can ever reach the history table is a lifecycle event growing an
 * object-typed property that smuggles it in. This suite forbids exactly that, structurally: every
 * public property of every `Saga*` event is a builtin scalar or array carrying state names, keys,
 * reasons and counts, never an object. A new lifecycle event carrying `public DomainEvent $event`
 * turns this red before its first row is written.
 *
 * Discovery is from the FILESYSTEM, anchored on a known event, the SchemaCompleteness pattern: a
 * new event file is seen without anyone remembering to list it.
 *
 * @see SagaHistoryEntry::fromSagaEvent the payload projection this pins
 */
final class SagaEventScalarPayloadTest extends TestCase
{
    #[Test]
    public function every_saga_lifecycle_event_carries_only_scalar_or_array_properties(): void
    {
        $classes = $this->eventClasses();
        self::assertNotSame([], $classes, 'the Saga/Event directory vanished — the anchor moved?');

        foreach ($classes as $class) {
            foreach (new ReflectionClass($class)->getProperties() as $property) {
                $type = $property->getType();

                self::assertInstanceOf(ReflectionNamedType::class, $type, sprintf(
                    '%s::$%s must declare one named type — a union/intersection cannot be proven payload-safe here',
                    $class,
                    $property->getName(),
                ));

                self::assertTrue($type->isBuiltin(), sprintf(
                    '%s::$%s is typed %s — an OBJECT property on a Saga* lifecycle event would smuggle its contents (a domain payload, personal data included) into workflow_history, which stores these properties verbatim. Carry scalar facts: names, keys, reasons.',
                    $class,
                    $property->getName(),
                    $type->getName(),
                ));
            }
        }
    }

    /**
     * @return list<class-string>
     */
    private function eventClasses(): array
    {
        $reflection = new ReflectionClass(SagaStarted::class);
        $namespace = $reflection->getNamespaceName();

        $classes = [];
        foreach (glob(dirname((string) $reflection->getFileName()).'/*.php') ?: [] as $file) {
            $class = $namespace.'\\'.basename($file, '.php');
            self::assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class), sprintf('%s does not autoload — a stray file in the Event directory?', $class));

            // interfaces and traits declare no payload of their own; the scan pins the events
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
