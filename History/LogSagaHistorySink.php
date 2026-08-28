<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use Psr\Log\LoggerInterface;

/**
 * Ships the saga history as a structured PSR-3 log record, `storm.saga.history`, leaving the operational
 * database untouched. The log driver forwards it to whatever sink is configured, such as Loki, stdout, or
 * a file. Cheapest write path; it trades queryability, since a log aggregator searches by label and time,
 * not by a high-cardinality `correlation_id`, so reconstructing one saga's timeline is poor here versus
 * `TableSagaHistorySink`.
 *
 * @see TableSagaHistorySink  the queryable alternative
 */
final readonly class LogSagaHistorySink implements SagaHistorySink
{
    public function __construct(private LoggerInterface $logger) {}

    public function record(SagaHistoryEntry $entry): void
    {
        $this->logger->info('storm.saga.history', [
            'workflow_type' => $entry->workflowType,
            'correlation_id' => $entry->correlationId,
            'generation' => $entry->generation,
            'event_type' => $entry->eventType,
            'event_id' => $entry->eventId,
            'occurred_at' => $entry->occurredAt,
            'payload' => $entry->payload,
        ]);
    }
}
