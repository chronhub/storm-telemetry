<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Telemetry\Tracing\OpenTelemetryContext;

/**
 * A context storage that fails never reaches the message path.
 *
 * - Capture answers an empty carrier
 * - Activation falls back to the root context
 * - A scope that cannot detach is let go
 */
final class OpenTelemetryContextTest extends TestCase
{
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private ContextStorageInterface&ExecutionContextAwareInterface $previous;

    protected function setUp(): void
    {
        $this->previous = Context::storage();
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->previous);
    }

    #[Test]
    public function a_storage_that_cannot_read_the_current_context_captures_an_empty_carrier(): void
    {
        $storage = $this->storage();
        $storage->method('current')->willThrowException(new RuntimeException('storage unavailable'));
        Context::setStorage($storage);

        self::assertSame([], new OpenTelemetryContext()->capture());
    }

    #[Test]
    public function an_activation_the_storage_refuses_falls_back_to_the_root_context(): void
    {
        $attached = [];
        $storage = $this->storage();
        $storage->method('attach')->willReturnCallback(function (ContextInterface $context) use (&$attached): ContextStorageScopeInterface {
            $attached[] = $context;
            if (count($attached) === 1) {
                throw new RuntimeException('storage refused the extracted context');
            }

            return $this->createStub(ContextStorageScopeInterface::class);
        });
        Context::setStorage($storage);

        $cleanup = new OpenTelemetryContext()->activate(['traceparent' => self::TRACEPARENT]);
        $cleanup();

        self::assertCount(2, $attached);
        self::assertSame(Context::getRoot(), $attached[1]);
    }

    #[Test]
    public function a_scope_that_cannot_detach_is_let_go_by_the_cleanup(): void
    {
        $scope = $this->createMock(ContextStorageScopeInterface::class);
        $scope->expects(self::once())->method('detach')->willThrowException(new RuntimeException('scope gone'));
        $storage = $this->storage();
        $storage->method('attach')->willReturn($scope);
        Context::setStorage($storage);

        $cleanup = new OpenTelemetryContext()->activate(['traceparent' => self::TRACEPARENT]);
        $cleanup();
    }

    private function storage(): ContextStorageInterface&ExecutionContextAwareInterface&Stub
    {
        /** @var ContextStorageInterface&ExecutionContextAwareInterface&Stub $storage */
        $storage = $this->createStubForIntersectionOfInterfaces([ContextStorageInterface::class, ExecutionContextAwareInterface::class]);

        return $storage;
    }
}
