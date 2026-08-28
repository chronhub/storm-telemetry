<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use Storm\Contracts\Message\SendableUnderInboxTransaction;

/**
 * One normalized saga-history record: the two trace columns, workflow type and correlation id, plus
 * the announcing generation, split out; the event's remaining public properties kept as the payload;
 * the event named by its short class name. Built once from a `Saga*` event by `fromSagaEvent()` so
 * every sink receives the same shape and no sink repeats the reflection.
 *
 * Sendable under an inbox transaction, declared: the publishing sink dispatches it from inside the
 * saga's handling, so on the async strategy it leaves for the broker while the inbox transaction is
 * still open. That is this record's designed shape as best-effort observability: an entry lost to a
 * rollback or duplicated by a replay changes no decision anywhere, so the dual-write guard lets it
 * through without a handler-level grant.
 */
final readonly class SagaHistoryEntry implements SendableUnderInboxTransaction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  int  $generation  the saga run that announced, the registry's 1-based counter; 0 on a
     *                           pre-generation wire entry and on the rare skip announcements made
     *                           without the claimed row in hand, the honest unknown, never a guess
     * @param  string  $eventId  stable identity minted ONCE at the announcement, so it survives the
     *                           async strategy's transport retries: two rows sharing an eventId are
     *                           the same announcement redelivered; two distinct events always differ.
     *                           Empty only on an entry hydrated from a pre-identity wire message.
     * @param  string  $occurredAt  the announce time in storage form, captured before any fan-out or
     *                              async hop; the table's own recorded_at is the ARRIVAL time, which
     *                              on the async strategy orders consumption, not the saga's life.
     *                              Empty only on a pre-identity wire message.
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public string $eventType,
        public array $payload,
        public int $generation = 0,
        public string $eventId = '',
        public string $occurredAt = '',
    ) {}

    /**
     * Normalize an engine `Saga*` event: `workflowType` and `correlationId` become the trace columns,
     * `generation` its own field, and all three are stripped from the payload, where they would otherwise
     * duplicate inside the jsonb blob; the rest of the event's public properties stay as the payload; the
     * event type is its short class name. The caller mints identity and occurrence time; that caller is
     * the subscriber, from inside its backstop.
     */
    public static function fromSagaEvent(object $event, string $eventId = '', string $occurredAt = ''): self
    {
        $vars = get_object_vars($event);
        $workflowType = (string) ($vars['workflowType'] ?? '');
        $correlationId = (string) ($vars['correlationId'] ?? '');
        $generation = (int) ($vars['generation'] ?? 0);
        unset($vars['workflowType'], $vars['correlationId'], $vars['generation']);

        $fqcn = $event::class;
        $pos = strrpos($fqcn, '\\');

        return new self(
            $workflowType,
            $correlationId,
            $pos === false ? $fqcn : substr($fqcn, $pos + 1),
            $vars,
            $generation,
            $eventId,
            $occurredAt,
        );
    }
}
