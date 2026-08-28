<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * One recorded `Saga*` announcement, read back: the write-side {@see SagaHistoryEntry} as the table
 * returns it, plus the arrival time the table stamped itself.
 *
 * The two times are NOT interchangeable and both are served. `occurredAt` is the saga's own clock,
 * captured at the announcement before any fan-out or async hop; it is the one that orders a life.
 * `recordedAt` is when the row landed, which under the publishing sink orders consumption instead.
 * A timeline read on `recordedAt` alone can show a saga's steps out of the order it lived them.
 */
final readonly class WorkflowHistoryRecord
{
    /**
     * @param  int  $generation  the run that announced, the registry's 1-based counter; 0 for a
     *                           pre-generation row or a skip announcement made without the row in hand
     * @param  array<string, mixed>  $payload  the event's remaining public properties
     * @param  string  $eventId  stable announcement identity; empty for a pre-identity row
     * @param  string  $occurredAt  the saga's own announce time; empty for a pre-identity row
     * @param  string  $recordedAt  when the row landed, never empty
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $eventType,
        public array $payload,
        public string $eventId,
        public string $occurredAt,
        public string $recordedAt,
    ) {}

    /**
     * The machine shape, snake_case on the wire: the console's `--json` contract, and the shape the
     * ops HTTP surface carries verbatim.
     *
     * @return array{workflow_type: string, correlation_id: string, generation: int, event_type: string, payload: array<string, mixed>, event_id: string, occurred_at: string, recorded_at: string}
     */
    public function toArray(): array
    {
        return [
            'workflow_type' => $this->workflowType,
            'correlation_id' => $this->correlationId,
            'generation' => $this->generation,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
