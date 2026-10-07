<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tracing;

use Closure;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use Storm\Contracts\Message\TraceContextPropagation;
use Throwable;

final class OpenTelemetryContext implements TraceContextPropagation
{
    public function capture(): array
    {
        try {
            $carrier = [];
            TraceContextPropagator::getInstance()->inject($carrier);

            return $carrier;
        } catch (Throwable) {
            return [];
        }
    }

    public function activate(array $carrier): Closure
    {
        try {
            $scope = TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot())->activate();
        } catch (Throwable) {
            $scope = Context::getRoot()->activate();
        }

        return static function () use ($scope): void {
            try {
                $scope->detach();
            } catch (Throwable) {
            }
        };
    }
}
