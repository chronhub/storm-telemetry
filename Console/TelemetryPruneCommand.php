<?php

declare(strict_types=1);

namespace Storm\Telemetry\Console;

use Doctrine\DBAL\Exception;
use Override;
use Storm\Support\Console\DurationOption;
use Storm\Support\Console\PositiveIntOption;
use Storm\Telemetry\History\WorkflowHistoryStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes `workflow_history` rows older than `--before`, the retention of saga history.
 *
 * `SagaHistorySubscriber` appends one row per saga event to this opt-in, append-only table, which
 * grows with saga throughput and so needs a retention sweep. Pruning loses only observability
 * history; the sagas' effects live in the event store.
 *
 * The sweep runs `DELETE WHERE recorded_at < age` in batches capped by `LIMIT`, never holding a long
 * lock, and is idempotent. An append-only table has no status to scope, only an age.
 *
 * The two modes are exclusive, since an age sweep and a targeted erasure are never one intention:
 *
 * - `--before <30d|90d>` sweeps by age and is required in that mode, so no prune-all happens by
 *   accident; `--batch` caps each statement.
 *
 * - `--correlation` erases one correlation's rows whatever their age, every generation at once.
 *   Correlation ids are plaintext business keys, and when an app's ids carry personal data this is
 *   the lever an erasure request acts on.
 *
 * `--dry-run` counts under either mode without deleting.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:telemetry:prune --before 90d
 * bin/console storm:telemetry:prune --before 90d --dry-run
 * bin/console storm:telemetry:prune --correlation order-4711
 * bin/console storm:telemetry:prune --correlation order-4711 --dry-run
 * ```
 */
#[AsCommand(name: 'storm:telemetry:prune', description: 'Prune workflow_history rows older than --before, or erase one --correlation (saga history retention).')]
final class TelemetryPruneCommand extends Command
{
    public function __construct(
        private readonly WorkflowHistoryStore $history,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('before', null, InputOption::VALUE_REQUIRED, 'Prune history rows older than this (e.g. 90d, 48h) — required unless --correlation');
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows deleted per batch', '1000');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be pruned, delete nothing');
        $this->addOption('correlation', null, InputOption::VALUE_REQUIRED, 'Erase every history row of this correlation id, regardless of age');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'With --correlation: narrow the erasure to one workflow type');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the probe, the count or the delete
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (! $this->history->installed()) {
            // as in the read command: a raw "relation does not exist" names nothing
            $io->warning('No workflow_history table — run storm:telemetry:install. Nothing recorded, nothing to prune.');

            return Command::FAILURE;
        }

        $before = $input->getOption('before');
        $correlation = $input->getOption('correlation');

        if (is_string($correlation) && $correlation !== '') {
            if (is_string($before) && $before !== '') {
                $io->error('--correlation and --before are exclusive: erase one correlation, or sweep by age, never both at once.');

                return Command::INVALID;
            }

            $type = $input->getOption('type');
            $type = is_string($type) ? $type : null;

            if ($input->getOption('dry-run') === true) {
                $io->success(sprintf(
                    'Would erase %d history row(s) for correlation "%s". Nothing deleted.',
                    $this->history->countForCorrelation($correlation, $type),
                    $correlation,
                ));

                return Command::SUCCESS;
            }

            $deleted = $this->history->deleteForCorrelation($correlation, $type);
            $io->success(sprintf('Erased %d history row(s) for correlation "%s".', $deleted, $correlation));

            return Command::SUCCESS;
        }

        // required unless `--correlation`, handled above: the shared option reads it as optional, and
        // its absence is this command's own refusal
        $duration = DurationOption::optional($input, $io, 'before');
        if (is_int($duration)) {
            return $duration;
        }
        if ($duration === null) {
            $io->getErrorStyle()->error('Specify --before with an age such as 90d, 48h or 90m (d=days, h=hours, m=minutes), or --correlation to erase one correlation.');

            return Command::INVALID;
        }
        $age = $duration->seconds;

        $batch = PositiveIntOption::parse($input->getOption('batch'));
        if ($batch === null) {
            $io->error('--batch must be a positive integer (rows deleted per batch), e.g. --batch=1000.');

            return Command::INVALID;
        }
        $dryRun = $input->getOption('dry-run') === true;

        $io->title(sprintf('Telemetry prune — saga history older than %s%s', $before, $dryRun ? ' (DRY RUN)' : ''));

        if ($dryRun) {
            $io->success(sprintf('Would prune %d history row(s). Nothing deleted.', $this->history->countPrunable($age)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Pruned %d history row(s).', $this->history->prune($age, $batch)));

        return Command::SUCCESS;
    }
}
