<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Storm\Chronicler\Telemetry\EventStoreObservability;
use Storm\Chronicler\Telemetry\SafeHeadObservability;
use Storm\Projector\Telemetry\ProjectorObservability;
use Storm\Telemetry\History\LogSagaHistorySink;
use Storm\Telemetry\History\NullSagaHistorySink;
use Storm\Telemetry\History\SagaHistorySink;
use Storm\Telemetry\History\TableSagaHistorySink;
use Storm\Telemetry\History\WorkflowHistoryStore;
use Storm\Telemetry\Metrics\ClockSkewMetricsCollector;
use Storm\Telemetry\Metrics\EventStoreCapacityMetricsCollector;
use Storm\Telemetry\Metrics\EventStoreDefaultRowsMetricsCollector;
use Storm\Telemetry\Metrics\MetricsExposition;
use Storm\Telemetry\Metrics\OutboxMetricsCollector;
use Storm\Telemetry\Metrics\ProjectionMetricsCollector;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;
use Storm\Telemetry\Metrics\SafeHeadMetricsCollector;
use Storm\Telemetry\Metrics\SagaHistoryMetricsCollector;
use Storm\Telemetry\Metrics\SagaMetricsCollector;
use Storm\Telemetry\Metrics\SchemaConformanceMetricsCollector;
use Storm\Telemetry\Metrics\SnapshotMetricsCollector;
use Storm\Telemetry\StormObservability;

/*
 * `Storm\Telemetry`: opt-in observability wiring.
 *
 * `StormBundle` imports this file after Chronicler and Projector, so the alias overrides below
 * replace the Null defaults those modules ship, such as `NullEventStoreObservability` and
 * `NullProjectorObservability`. The framework bodies stay identical and only the alias target
 * changes; removing this package's import boots the framework on the Null defaults again.
 *
 * This file is the whole wiring surface, and Chronicler and Projector do not depend on Telemetry.
 * The dependency always runs from observer to observed: Telemetry depends on Chronicler, Projector
 * and Saga, which never depend back.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Load every class in the package so autoconfigure picks up:
    // - `StormObservability`, the facade
    // - `SagaHistorySubscriber`, `#[AsEventListener]` on `Saga*` events
    // - `InstallTelemetryCommand`, `#[AsCommand('storm:telemetry:install')]`
    $services->load('Storm\\Telemetry\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Tracing/',
            dirname(__DIR__).'/Schema/',
            dirname(__DIR__).'/FailureRegistry/',
            dirname(__DIR__).'/resources/',
            dirname(__DIR__).'/Tests/',
            dirname(__DIR__).'/config/',
            // wired below, since these constructors cannot autowire: the value object
            // `SagaHistoryEntry` and the composites `FanOutSagaHistorySink` and `BestEffortSagaHistorySink`
            dirname(__DIR__).'/History/',
            // wired below: `MetricSample` and `MetricFamily` are value objects
            dirname(__DIR__).'/Metrics/',
        ]);

    // Metrics, the scrape-time counterpart of the health checks: stateless collectors, the text
    // renderer, and the exposition composing every `MetricsCollector` the bundle's autoconfiguration tags
    $services->set(SagaMetricsCollector::class)->autowire();
    $services->set(SagaHistoryMetricsCollector::class)->autowire();
    $services->set(OutboxMetricsCollector::class)->autowire();
    $services->set(ProjectionMetricsCollector::class)->autowire();
    $services->set(SafeHeadMetricsCollector::class)->autowire();
    $services->set(EventStoreCapacityMetricsCollector::class)->autowire();
    $services->set(EventStoreDefaultRowsMetricsCollector::class)->autowire();
    // the conformance targets are the bundle's to set: the core catalog lives in Ledger, which
    // Telemetry does not depend on, and the split read-model store is the application's choice
    $services->set(SchemaConformanceMetricsCollector::class)->autowire();
    $services->set(SnapshotMetricsCollector::class)->autowire();
    $services->set(ClockSkewMetricsCollector::class)->autowire();
    $services->set(PrometheusTextRenderer::class)->autowire();
    $services->set(MetricsExposition::class)->autowire();

    // Saga history is opt-in: the default sink writes nothing until an app chooses one, so the
    // operational database sees no surprise write. The leaf sinks autowire, and an app picks one by
    // overriding the `SagaHistorySink` alias:
    // - `LogSagaHistorySink` ships entries to an external sink
    // - `TableSagaHistorySink` fills the queryable `workflow_history` table
    // - An async publishing sink keeps the write off the worker
    // - A `FanOutSagaHistorySink` composes several, each child wrapped in `BestEffortSagaHistorySink`
    $services->set(LogSagaHistorySink::class)->autowire();
    $services->set(TableSagaHistorySink::class)->autowire();
    $services->set(NullSagaHistorySink::class)->autowire();
    $services->alias(SagaHistorySink::class, NullSagaHistorySink::class);

    // The read and retention side of `workflow_history`, count and prune, behind `storm:telemetry:prune`.
    // Retention belongs to the table rather than to the active sink, the write strategy; `History/` is
    // excluded from `load()` above, so it is wired here.
    $services->set(WorkflowHistoryStore::class)->autowire();

    // override the Null defaults of Chronicler and Projector: the last alias wins in import order
    $services->alias(SafeHeadObservability::class, StormObservability::class);
    $services->alias(EventStoreObservability::class, StormObservability::class);
    $services->alias(ProjectorObservability::class, StormObservability::class);
};
