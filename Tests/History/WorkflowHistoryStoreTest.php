<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\History;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\History\GenerationOutOfRange;
use Storm\Telemetry\History\HistoryAvailability;
use Storm\Telemetry\History\WorkflowHistoryStore;

/**
 * The history read's own contract, over a recorded connection: what the timeline asks the database
 * for, and what the rows become on the way out.
 *
 * This file observes a live database and was deliberately OUTSIDE this scope's mutation field when
 * the scope was written; an ApiOps test that builds the store to exercise a provider brought it in,
 * and a file half in the field is a file whose escapes nobody chose. What follows finishes that
 * opening rather than arguing with it: the reads that are pure decision, the clamp, the truncation
 * probe and the hydration, all of which a stub answers as faithfully as a server. What the server
 * alone can say, that the SQL means what it reads like, stays in the integration suite.
 */
final class WorkflowHistoryStoreTest extends TestCase
{
    /** @var list<array{sql: string, params: array<int|string, mixed>, types: array<int|string, mixed>}> */
    private array $reads = [];

    #[Test]
    #[Group('adversarial')]
    public function a_generation_outside_what_the_column_stores_is_refused_before_any_read(): void
    {
        // the column is a signed 32-bit integer: a value past it does not narrow a timeline, it makes
        // the database reject the statement, so the refusal belongs here rather than in the driver
        foreach ([0, -1, WorkflowHistoryStore::MAX_GENERATION + 1] as $outside) {
            try {
                $this->store()->read('corr-1', generation: $outside);
                self::fail(sprintf('expected a refusal for generation %d', $outside));
            } catch (GenerationOutOfRange) {
                self::assertSame([], $this->reads);
            }
        }
    }

    #[Test]
    public function the_first_and_last_generations_the_column_stores_are_accepted(): void
    {
        // the bounds are INSIDE: a guard that refused them would make the first run of every
        // correlation unreadable, which is the run an incident is usually about
        foreach ([1, WorkflowHistoryStore::MAX_GENERATION] as $inside) {
            $this->reads = [];
            $store = $this->store([[1], []]);

            self::assertSame([], $store->read('corr-1', generation: $inside)->records);
            self::assertSame($inside, $this->read(1)['params']['generation']);
        }
    }

    #[Test]
    public function the_timeline_clamps_the_window_it_was_asked_for(): void
    {
        self::assertSame(WorkflowHistoryStore::MAX_LIMIT, $this->store([[1], []])->read('c', limit: 10_000)->limit);
        self::assertSame(1, $this->store([[1], []])->read('c', limit: 0)->limit);
        self::assertSame(1, $this->store([[1], []])->read('c', limit: -3)->limit);
        self::assertSame(100, $this->store([[1], []])->read('c')->limit);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_absent_table_is_named_rather_than_read_as_a_silent_saga(): void
    {
        // the absence of a table is not the silence of a saga, and the difference is the whole
        // answer: an operator told "nothing recorded" would stop looking
        $timeline = $this->store([[0]])->read('corr-1');

        self::assertSame(HistoryAvailability::NotInstalled, $timeline->availability);
        self::assertSame([], $timeline->records);
        self::assertFalse($timeline->truncated);
        self::assertCount(1, $this->reads); // the catalog probe alone; the history is never queried
    }

    #[Test]
    public function the_install_probe_asks_the_catalog_for_the_history_table(): void
    {
        self::assertTrue($this->store([[1]])->installed());
        self::assertSame(['workflow_history'], $this->read(0)['params']);

        self::assertFalse($this->store([[0]])->installed());
    }

    #[Test]
    #[Group('adversarial')]
    public function the_timeline_asks_for_one_row_past_its_window_so_truncation_is_known(): void
    {
        $this->store([[1], []])->read('corr-1', limit: 5);

        self::assertSame(6, $this->read(1)['params']['n']);
        self::assertSame(ParameterType::INTEGER, $this->read(1)['types']['n']);
    }

    #[Test]
    #[Group('adversarial')]
    public function truncation_is_reported_only_past_the_window_and_the_probe_row_never_answers(): void
    {
        $rows = array_map(fn (int $i): array => $this->historyRow(['event_id' => 'e-'.$i]), range(1, 3));

        $full = $this->store([[1], $rows])->read('corr-1', limit: 3);
        self::assertFalse($full->truncated);
        self::assertCount(3, $full->records);

        $cut = $this->store([[1], [...$rows, $this->historyRow(['event_id' => 'e-4'])]])->read('corr-1', limit: 3);
        self::assertTrue($cut->truncated);
        self::assertCount(3, $cut->records);
        self::assertSame(['e-1', 'e-2', 'e-3'], array_map(static fn ($r): string => $r->eventId, $cut->records));
    }

    #[Test]
    public function the_timeline_narrows_only_on_the_filters_it_was_given(): void
    {
        $this->store([[1], []])->read('corr-1');
        $bare = $this->read(1);
        self::assertStringContainsString('workflow_history WHERE correlation_id = :corr', $bare['sql']);
        self::assertArrayNotHasKey('type', $bare['params']);
        self::assertArrayNotHasKey('generation', $bare['params']);

        $this->reads = [];
        $this->store([[1], []])->read('corr-1', 'onboarding', 10, 2);
        $narrow = $this->read(1);
        self::assertStringContainsString('WHERE correlation_id = :corr AND workflow_type = :type AND generation = :generation', $narrow['sql']);
        self::assertStringContainsString('SELECT workflow_type, correlation_id', $narrow['sql']);
        self::assertSame('onboarding', $narrow['params']['type']);
        self::assertSame(2, $narrow['params']['generation']);
        self::assertSame(ParameterType::INTEGER, $narrow['types']['generation']);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_workflow_type_narrows_nothing(): void
    {
        // an unfilled form field arrives as an empty string, and narrowing on it would answer
        // nothing where the operator asked for every type on the correlation
        $this->store([[1], []])->read('corr-1', '');

        self::assertStringNotContainsString('workflow_type = :type', $this->read(1)['sql']);
        self::assertArrayNotHasKey('type', $this->read(1)['params']);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_timeline_pays_one_probe_to_say_whether_anything_records_at_all(): void
    {
        // rows for other correlations and no rows at all are different answers, and only the empty
        // case pays for the distinction
        $quiet = $this->store([[1], [], [1]])->read('corr-1');
        self::assertSame(HistoryAvailability::HasRows, $quiet->availability);
        self::assertCount(3, $this->reads);

        $this->reads = [];
        $bare = $this->store([[1], [], [0]])->read('corr-1');
        self::assertSame(HistoryAvailability::EmptyTable, $bare->availability);

        $this->reads = [];
        $answered = $this->store([[1], [$this->historyRow()]])->read('corr-1');
        self::assertSame(HistoryAvailability::HasRows, $answered->availability);
        self::assertCount(2, $this->reads); // no probe: the answer already proves rows exist
    }

    #[Test]
    public function a_record_carries_the_declared_type_and_not_the_driver_s(): void
    {
        $row = $this->historyRow(['generation' => '4', 'workflow_type' => 1_700_000_050,
            'correlation_id' => 1_700_000_051, 'event_type' => 1_700_000_052,
            'event_id' => 1_700_000_053, 'occurred_at' => 1_700_000_054, 'recorded_at' => 1_700_000_055]);

        $record = $this->store([[1], [$row]])->read('corr-1')->records[0];

        self::assertSame(4, $record->generation);
        self::assertSame('1700000050', $record->workflowType);
        self::assertSame('1700000051', $record->correlationId);
        self::assertSame('1700000052', $record->eventType);
        self::assertSame('1700000053', $record->eventId);
        self::assertSame('1700000054', $record->occurredAt);
        self::assertSame('1700000055', $record->recordedAt);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_record_without_its_own_clock_reads_as_empty_and_never_as_null(): void
    {
        // `occurredAt` is a declared string: a null stamp is an absence to render, not a type the
        // caller has to guard
        $record = $this->store([[1], [$this->historyRow(['occurred_at' => null])]])->read('corr-1')->records[0];

        self::assertSame('', $record->occurredAt);
    }

    #[Test]
    #[Group('adversarial')]
    public function only_an_object_payload_survives_the_decode(): void
    {
        // the sink encodes an object; a scalar, a list, an unreadable column or none at all is
        // someone else's row, and a forensic read shows an empty bag rather than guessing
        $cases = [
            ['{"a":1}', ['a' => 1]],
            ['', []],
            [null, []],
            [17, []],
            ['"a string"', []],
            ['[1,2]', []],
            ['null', []],
        ];

        foreach ($cases as [$stored, $expected]) {
            $record = $this->store([[1], [$this->historyRow(['payload' => $stored])]])->read('corr-1')->records[0];
            self::assertSame($expected, $record->payload);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function historyRow(array $overrides = []): array
    {
        return [...[
            'workflow_type' => 'onboarding', 'correlation_id' => 'corr-1', 'generation' => 1,
            'event_type' => 'SagaStarted', 'payload' => '{}', 'event_id' => 'e-1',
            'occurred_at' => '2026-08-23 10:00:00+00', 'recorded_at' => '2026-08-23 10:00:01+00',
        ], ...$overrides];
    }

    /**
     * One recorded read, by call order; an accessor for the same reason its sibling gateway test
     * has one, a cleared log leaving the analyser certain it stays empty.
     *
     * @return array{sql: string, params: array<int|string, mixed>, types: array<int|string, mixed>}
     */
    private function read(int $nth): array
    {
        self::assertArrayHasKey($nth, $this->reads);

        return $this->reads[$nth];
    }

    /**
     * @param  list<list<mixed>>  $results  one result per read, in call order; a scalar read takes
     *                                      the first cell of its row
     */
    private function store(array $results = [[1], []]): WorkflowHistoryStore
    {
        $this->reads = [];
        $connection = $this->createStub(Connection::class);
        $queue = $results;

        $record = function (string $sql, array $params, array $types) use (&$queue): mixed {
            $this->reads[] = ['sql' => $sql, 'params' => $params, 'types' => $types];

            return array_shift($queue);
        };

        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params = [], array $types = []): mixed => $record($sql, $params, $types)[0] ?? null,
        );
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql, array $params = [], array $types = []): array => $record($sql, $params, $types) ?? [],
        );

        return new WorkflowHistoryStore($connection);
    }
}
