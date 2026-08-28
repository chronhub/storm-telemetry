<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Storm\Saga\Event\SagaStarted;
use Storm\Telemetry\SagaHistorySubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Completeness guard, not line coverage: every `Storm\Saga\Event\*` must have a recording handler in
 * SagaHistorySubscriber. A NEW saga event added without one is a real, silent bug that loses audit
 * history, and no test that exercises the handlers already there can see it. This check reads the
 * declared parameter types; whether a handler's body forwards at all is the other half, and it belongs
 * to the forwarding suite in SagaHistorySubscriberTest.
 */
final class SagaHistoryCompletenessTest extends TestCase
{
    #[Test]
    public function every_saga_event_has_a_history_handler(): void
    {
        $missing = array_values(array_diff($this->sagaEvents(), $this->handledEvents()));

        self::assertSame(
            [],
            $missing,
            'Saga events with no SagaHistorySubscriber #[AsEventListener] handler: '.implode(', ', $missing),
        );
    }

    /**
     * Every concrete `Storm\Saga\Event\*` class, discovered from the namespace's directory. The directory
     * is located via an autoloaded anchor, so it survives the monorepo-to-split move.
     *
     * @return list<class-string>
     */
    private function sagaEvents(): array
    {
        $anchor = new ReflectionClass(SagaStarted::class);
        $directory = dirname((string) $anchor->getFileName());
        $namespace = $anchor->getNamespaceName();

        $events = [];
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            /** @var class-string $fqcn */
            $fqcn = $namespace.'\\'.basename($file, '.php');
            if (class_exists($fqcn)) {
                $events[] = $fqcn;
            }
        }

        return $events;
    }

    /**
     * The event types the subscriber records: the single parameter of each `#[AsEventListener]` method.
     *
     * @return list<string>
     */
    private function handledEvents(): array
    {
        $handled = [];
        foreach (new ReflectionClass(SagaHistorySubscriber::class)->getMethods() as $method) {
            if ($method->getAttributes(AsEventListener::class) === []) {
                continue;
            }

            $parameters = $method->getParameters();
            if ($parameters === []) {
                continue;
            }

            $type = $parameters[0]->getType();
            if ($type instanceof ReflectionNamedType) {
                $handled[] = $type->getName();
            }
        }

        return $handled;
    }
}
