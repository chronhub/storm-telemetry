<?php

declare(strict_types=1);

namespace Storm\Telemetry\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Support\Console\PositiveIntOption;
use Storm\Telemetry\History\GenerationOutOfRange;
use Storm\Telemetry\History\HistoryAvailability;
use Storm\Telemetry\History\WorkflowHistoryRecord;
use Storm\Telemetry\History\WorkflowHistoryStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints one saga's recorded life, oldest first: the instance row `storm:saga:inspect` reads holds
 * the current state, and this command shows every announcement that led to it.
 *
 * It reads `workflow_history`, which takes three opt-ins:
 *
 * - The Telemetry package installed
 * - `storm:telemetry:install` run
 * - The app's `SagaHistorySink` alias pointed at the table sink
 *
 * Each has its own empty answer, and the command names the one it hit rather than printing an empty
 * list: "the recorder was off" and "this saga announced nothing" are opposite conclusions.
 *
 * Records follow the saga's own announce time rather than their arrival, so the timeline keeps the
 * order the saga lived even under the async publishing sink. The store deduplicates redeliveries
 * before the window, so a row never repeats and never uses up the limit.
 *
 * `--generation` narrows to one run of a reused correlation. Without it the timeline aggregates every
 * run of the correlation, which under `#[Workflow(reuse: Allow)]` means several sagas sharing a
 * business key; each record carries its `generation` to tell the runs apart.
 *
 * Passing the workflow type pays off on a large table: the read can then use the
 * `(workflow_type, correlation_id, occurred_at, id)` index, which a correlation alone cannot.
 *
 * `--json` prints the machine-readable timeline with its availability, and an empty timeline exits
 * with `Command::FAILURE`, the contract of `storm:saga:inspect` and `storm:saga:list`.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:telemetry:history <correlation-id>
 * bin/console storm:telemetry:history <correlation-id> transfer --limit=500
 * bin/console storm:telemetry:history <correlation-id> --json | jq -r '.records[].event_type'
 * ```
 */
#[AsCommand(
    name: 'storm:telemetry:history',
    description: "Print one saga's recorded timeline from workflow_history, oldest first (read-only).",
)]
final class TelemetryHistoryCommand extends Command
{
    public function __construct(
        private readonly WorkflowHistoryStore $history,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('correlation', InputArgument::REQUIRED, 'The saga correlation id');
        $this->addArgument('type', InputArgument::OPTIONAL, 'The workflow type — narrows, and lets the read use the index');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Records to return (max '.WorkflowHistoryStore::MAX_LIMIT.')', (string) WorkflowHistoryStore::DEFAULT_LIMIT);
        $this->addOption('generation', null, InputOption::VALUE_REQUIRED, 'One run of a reused correlation; omit to aggregate every run');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable timeline');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException on a stored-payload decode failure, or encoding the `--json` output
     * @throws Exception on a DBAL read failure
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = PositiveIntOption::parse($input->getOption('limit'));

        if ($limit === null) {
            $io->getErrorStyle()->error('The --limit must be a positive integer.');

            return Command::INVALID;
        }

        $generationOpt = $input->getOption('generation');
        $generation = null;
        if ($generationOpt !== null) {
            $generation = PositiveIntOption::parse($generationOpt);
            if ($generation === null) {
                $io->getErrorStyle()->error('The --generation must be a positive integer, the run number the registry claimed.');

                return Command::INVALID;
            }
        }

        $typeArg = $input->getArgument('type');

        try {
            $timeline = $this->history->read(
                (string) $input->getArgument('correlation'),
                is_string($typeArg) && $typeArg !== '' ? $typeArg : null,
                $limit,
                $generation,
            );
        } catch (GenerationOutOfRange $e) {
            // the store's own bound, one refusal for both channels: the parser accepts any int PHP
            // holds, the column holds less, and the driver's answer would be a 500 quoting the query
            $io->getErrorStyle()->error($e->getMessage());

            return Command::INVALID;
        }

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode($timeline->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $timeline->records === [] ? Command::FAILURE : Command::SUCCESS;
        }

        if ($timeline->records === []) {
            // the empty answer's reason, never a bare "nothing found": an operator told "no history"
            // concludes the engine announced nothing, when the recorder is usually off
            match ($timeline->availability) {
                HistoryAvailability::NotInstalled => $io->getErrorStyle()->warning('No workflow_history table — run storm:telemetry:install. This says nothing about the saga.'),
                HistoryAvailability::EmptyTable => $io->getErrorStyle()->warning('The workflow_history table is empty — nothing has landed yet: a fresh install, a full prune, or no recording sink wired (the default is the null one; check the SagaHistorySink alias). The rows cannot tell which.'),
                HistoryAvailability::HasRows => $io->getErrorStyle()->warning('History rows exist, but none for this correlation: it announced nothing that landed — or it ran before, or without, the recording sink.'),
            };

            return Command::FAILURE;
        }

        $io->table(
            ['occurred at', 'gen', 'event', 'recorded at', 'payload'],
            array_map(static fn (WorkflowHistoryRecord $r): array => [
                $r->occurredAt === '' ? '—' : $r->occurredAt,
                // 0 is the honest unknown: a pre-generation row, or a skip announced without the row
                $r->generation === 0 ? '—' : (string) $r->generation,
                $r->eventType,
                // arrival is shown only when it disagrees with the saga's own clock: under the async
                // sink that gap is the observability lag, and it is the reason both times are stored
                $r->recordedAt === $r->occurredAt ? '=' : $r->recordedAt,
                self::payload($r),
            ], $timeline->records),
        );

        $io->writeln(sprintf(' %d record(s), oldest first.', count($timeline->records)));

        if ($timeline->truncated) {
            $io->getErrorStyle()->warning(sprintf('Capped at %d records — this saga announced more. Raise --limit (max %d).', $timeline->limit, WorkflowHistoryStore::MAX_LIMIT));
        }

        return Command::SUCCESS;
    }

    /**
     * The payload as compact `key=value` pairs: a jsonb blob printed raw makes the table unreadable,
     * and `--json` is the channel for the full shape.
     */
    private static function payload(WorkflowHistoryRecord $record): string
    {
        if ($record->payload === []) {
            return '—';
        }

        return implode(' ', array_map(
            static fn (int|string $key, mixed $value): string => $key.'='.(is_scalar($value) || $value === null ? var_export($value, true) : '{…}'),
            array_keys($record->payload),
            $record->payload,
        ));
    }
}
