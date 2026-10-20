<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

use Storm\Contracts\Message\SendableUnderInboxTransaction;

/**
 * One normalized saga-history record, built once from a `Saga*` event by `fromSagaEvent()` so every
 * sink receives the same shape and none repeats the reflection:
 *
 * - The two trace columns, workflow type and correlation id, and the announcing generation are split
 *   out.
 *
 * - The event's remaining public properties stay as the payload.
 *
 * - The event is named by its short class name.
 *
 * It declares itself sendable under an inbox transaction: the publishing sink dispatches it from
 * inside the saga's handling, so under the async strategy it leaves for the broker while the inbox
 * transaction is still open. As best-effort observability, an entry lost to a rollback or duplicated
 * by a replay changes no decision anywhere, so the dual-write guard lets it through without a
 * handler-level grant.
 */
final readonly class SagaHistoryEntry implements SendableUnderInboxTransaction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  int  $generation  the saga run that announced, the registry's 1-based counter; 0 on a
     *                           pre-generation wire entry and on the rare skip announcements made
     *                           without the claimed row in hand, the honest unknown, never a guess
     * @param  string  $eventId  stable identity minted once at the announcement, so it survives the
     *                           async strategy's transport retries: two rows sharing an `eventId` are
     *                           the same announcement redelivered, and two distinct events always
     *                           differ. Empty only on an entry hydrated from a pre-identity wire
     *                           message.
     * @param  string  $occurredAt  the announce time in storage form, captured before any fan-out or
     *                              async hop; the table's own `recorded_at` is the arrival time, which
     *                              under the async strategy orders consumption rather than the saga's
     *                              life. Empty only on a pre-identity wire message.
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
     * Normalizes an engine `Saga*` event: `workflowType`, `correlationId` and `generation` leave the
     * payload, where they would otherwise repeat inside the jsonb blob.
     *
     * The caller mints the identity and the occurrence time: the subscriber, from inside its backstop.
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
