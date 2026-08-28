<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Storm\Chronicler\Telemetry\EventStoreObservability;
use Storm\Projector\Telemetry\ProjectorObservability;
use Storm\Telemetry\History\LogSagaHistorySink;
use Storm\Telemetry\Metrics\MetricsExposition;
use Storm\Telemetry\Metrics\OutboxMetricsCollector;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;
use Storm\Telemetry\Metrics\ProjectionMetricsCollector;
use Storm\Telemetry\Metrics\SagaHistoryMetricsCollector;
use Storm\Telemetry\Metrics\SagaMetricsCollector;
use Storm\Telemetry\History\NullSagaHistorySink;
use Storm\Telemetry\History\SagaHistorySink;
use Storm\Telemetry\History\TableSagaHistorySink;
use Storm\Telemetry\History\WorkflowHistoryStore;
use Storm\Telemetry\StormObservability;

/*
 * Storm\Telemetry: opt-in observability wiring.
 *
 * Imported by StormBundle AFTER Chronicler / Projector, so the explicit alias overrides below
 * REPLACE the Null aliases shipped by those modules, namely NullEventStoreObservability and
 * NullProjectorObservability. The framework bodies stay identical; only the alias target
 * changes. Removing this package's import means the framework boots with the Null defaults again.
 *
 * Constraints: this file is the ENTIRE wiring surface; Chronicler / Projector do NOT depend on
 * Telemetry. The direction is always observer to observed: Telemetry depends on the modules it
 * observes, namely Chronicler, Projector, and Saga; they never depend back.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Load every class in the package so autoconfigure picks up:
    // - StormObservability, the facade
    // - SagaHistorySubscriber, #[AsEventListener] on Saga* events
    // - InstallTelemetryCommand, #[AsCommand('storm:telemetry:install')]
    $services->load('Storm\\Telemetry\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Schema/',
            dirname(__DIR__).'/Tests/',
            dirname(__DIR__).'/config/',
            // Wired explicitly below: this dir holds value objects and composites, namely SagaHistoryEntry,
            // FanOutSagaHistorySink, and BestEffortSagaHistorySink, whose constructors cannot autowire.
            dirname(__DIR__).'/History/',
            // Wired explicitly below: MetricSample and MetricFamily are value objects.
            dirname(__DIR__).'/Metrics/',
        ]);

    // The metrics block, the scrape-time twin of the health checks: three stateless collectors,
    // the text renderer, and the exposition that composes every tagged MetricsCollector. The tag
    // arrives by autoconfiguration on the interface, registered by the bundle.
    $services->set(SagaMetricsCollector::class)->autowire();
    $services->set(SagaHistoryMetricsCollector::class)->autowire();
    $services->set(OutboxMetricsCollector::class)->autowire();
    $services->set(ProjectionMetricsCollector::class)->autowire();
    $services->set(PrometheusTextRenderer::class)->autowire();
    $services->set(MetricsExposition::class)->autowire();

    // Saga-history sink strategy. The leaf sinks autowire trivially; the default is no-op. Saga history is opt-in:
    // nothing is written until an app chooses a sink, so no surprise writes on the operational DB. An app selects
    // by overriding the SagaHistorySink alias: LogSagaHistorySink to ship to an external sink, TableSagaHistorySink
    // for the queryable workflow_history table, an async publishing sink off the worker, or a FanOutSagaHistorySink
    // it composes, wrapping each child in BestEffortSagaHistorySink for isolation.
    $services->set(LogSagaHistorySink::class)->autowire();
    $services->set(TableSagaHistorySink::class)->autowire();
    $services->set(NullSagaHistorySink::class)->autowire();
    $services->alias(SagaHistorySink::class, NullSagaHistorySink::class);

    // The read/retention side of workflow_history, count and prune, behind storm:telemetry:prune. Distinct
    // from the sinks, the write strategies: retention belongs to the table, not the active sink. In History/
    // so excluded from load() above; wired explicitly here.
    $services->set(WorkflowHistoryStore::class)->autowire();

    // Override the Null aliases from Chronicler / Projector. Last alias wins in services.php
    // import order; StormBundle imports Telemetry after Chronicler / Projector for this to take.
    $services->alias(EventStoreObservability::class, StormObservability::class);
    $services->alias(ProjectorObservability::class, StormObservability::class);
};
