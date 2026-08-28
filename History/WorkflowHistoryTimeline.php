<?php

declare(strict_types=1);

namespace Storm\Telemetry\History;

/**
 * One saga's recorded life: the rows, whether the cap cut them short, and, when there are none, why
 * there are none.
 *
 * The `availability` is the reason this is an envelope rather than a bare list. An empty timeline has
 * three very different meanings:
 *
 * - Never installed;
 * - Nothing wired to record;
 * - Genuinely nothing announced for this correlation.
 *
 * A reader that returns `[]` for all three tells an operator the engine stayed silent when the truth
 * is that the recorder was off. Truncation is announced for the same reason it is on the saga
 * listing: a capped answer that reads as a complete one is the lie an operator cannot recover from.
 *
 * @see WorkflowHistoryStore::read() the producer
 */
final readonly class WorkflowHistoryTimeline
{
    /**
     * @param  list<WorkflowHistoryRecord>  $records  oldest first, the order the saga lived
     * @param  bool  $truncated  true when at least one further row matched beyond the cap
     * @param  positive-int  $limit  the cap actually applied, after the server-side clamp
     */
    public function __construct(
        public array $records,
        public bool $truncated,
        public int $limit,
        public HistoryAvailability $availability,
    ) {}

    /**
     * The machine shape, snake_case on the wire, the console's `--json` contract.
     *
     * @return array{records: list<array<string, mixed>>, truncated: bool, limit: int, availability: string}
     */
    public function toArray(): array
    {
        return [
            'records' => array_map(static fn (WorkflowHistoryRecord $r): array => $r->toArray(), $this->records),
            'truncated' => $this->truncated,
            'limit' => $this->limit,
            'availability' => $this->availability->value,
        ];
    }
}
