<?php

declare(strict_types=1);

namespace Storm\Telemetry\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Override;
use Storm\Support\Dbal\InstallLock;
use Storm\Support\Dbal\SchemaProbe;
use Storm\Telemetry\Schema\TelemetrySchemaCatalog;
use Storm\Telemetry\Schema\WorkflowHistorySchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Creates the Telemetry tables, currently only `workflow_history`. Separate from `storm:install` for
 * the core and `storm:saga:install` for the saga on purpose: Telemetry is opt-in, so its schema is
 * opt-in too. An app that wants the persisted saga history runs this in addition to the others.
 *
 * Carries the same operational contract as `storm:install`:
 *
 * - ONE transaction under an advisory lock, so concurrent installs cannot interleave;
 *
 * - The target `current_database()`, `current_schema()` and server version printed before anything
 *   runs;
 *
 * - A refusal below the PostgreSQL 17 product floor;
 *
 * - The conformance probe inside the transaction, so the command either proves the schema it created
 *   or rolls back untouched.
 *
 * Three behaviors via mutually exclusive flags, mirroring `storm:install`:
 *
 * - No flag runs `up()` only, an idempotent create;
 * - `--drop` runs `down()` only, a drop with no recreate;
 * - `--reset` runs `down()` then `up()`, a drop and recreate.
 *
 * `--drop` and `--reset` destroy the recorded trail, so both demand an interactive confirmation
 * naming the target database and schema, or `--force` when no interaction is possible. The trail
 * is an observability record, not the source of truth; the ceremony exists because the command
 * cannot know what an operator's incident review still needs from it.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:telemetry:install                  # create the tables (idempotent, verified)
 * bin/console storm:telemetry:install --drop --force   # drop only, no recreate
 * bin/console storm:telemetry:install --reset --force  # drop then recreate
 * ```
 */
#[AsCommand(
    name: 'storm:telemetry:install',
    description: 'Create the Telemetry tables, verified (workflow_history; --drop to drop only; --reset to drop + recreate; both ask unless --force).',
)]
final class InstallTelemetryCommand extends Command
{
    /** "STORMTEL" packed as an int64; the advisory lock serializing telemetry installs/resets per database. */
    private const int ADVISORY_LOCK_KEY = 0x53544F524D54454C;

    /** The product's documented floor; the check mirrors `storm:install` so every installer refuses the same servers. */
    private const int MINIMUM_SERVER_VERSION_NUM = 170_000;

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('drop', null, InputOption::VALUE_NONE, 'Drop the tables (no re-create). Mutually exclusive with --reset.');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Reset: drop the tables then re-create them. Mutually exclusive with --drop.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Confirm --drop/--reset without asking (required when the session is non-interactive).');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure creating / dropping the telemetry tables
     * @throws Throwable propagated after the install transaction is rolled back
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $drop = (bool) $input->getOption('drop');
        $reset = (bool) $input->getOption('reset');

        if ($drop && $reset) {
            $io->error('--drop and --reset are mutually exclusive. --drop drops only; --reset drops and re-creates.');

            return Command::INVALID;
        }

        [$database, $schema, $version, $versionNum] = $this->home();
        $io->text(sprintf('%s / %s — PostgreSQL %s', $database, $schema, $version));
        if ($versionNum < self::MINIMUM_SERVER_VERSION_NUM) {
            $io->error(sprintf('PostgreSQL 17 is the documented floor — this connection runs %s.', $version));

            return Command::FAILURE;
        }

        if ($drop || $reset) {
            $target = sprintf('%s/%s', $database, $schema);
            if (! (bool) $input->getOption('force')) {
                if (! $input->isInteractive()) {
                    $io->error(sprintf(
                        '--%s destroys the recorded telemetry trail on %s and the session is non-interactive: pass --force to confirm.',
                        $drop ? 'drop' : 'reset',
                        $target,
                    ));

                    return Command::INVALID;
                }
                if (! $io->confirm(sprintf('%s the Telemetry tables on %s?', $drop ? 'Drop' : 'Drop and re-create', $target), false)) {
                    $io->warning('Aborted — nothing was dropped.');

                    return Command::SUCCESS;
                }
            }
        }

        // One transaction under the advisory lock, and the shape is PROVEN inside it before
        // committing: the DDL is `CREATE TABLE IF NOT EXISTS`, so a table predating a column keeps
        // its old shape and only fails later, inside the sink's INSERT, on a path the subscriber's
        // backstop deliberately swallows. Proving it here turns a silent drift into a refusal at
        // the moment someone can act on it.
        $this->connection->beginTransaction();

        try {
            InstallLock::acquire($this->connection, self::ADVISORY_LOCK_KEY, 'storm:telemetry:install');

            if ($drop || $reset) {
                foreach (WorkflowHistorySchema::down() as $statement) {
                    $this->connection->executeStatement($statement);
                }
            }

            if (! $drop) {
                foreach (WorkflowHistorySchema::up() as $statement) {
                    $this->connection->executeStatement($statement);
                }

                $problems = new SchemaProbe()->problems(
                    $this->connection,
                    TelemetrySchemaCatalog::catalog(),
                    array_keys(TelemetrySchemaCatalog::COLUMNS),
                );

                if ($problems !== []) {
                    $this->connection->rollBack(); // an install that cannot prove its schema installs nothing

                    $io->error('The telemetry schema is not conformant:');
                    $io->listing($problems);

                    return Command::FAILURE;
                }
            }

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        $io->success(match (true) {
            $drop => 'Telemetry schema dropped.',
            $reset => 'Telemetry schema reset.',
            default => 'Telemetry schema installed.',
        });

        return Command::SUCCESS;
    }

    /**
     * Where this connection's DDL lands. The schema is `search_path`-relative by design, so the
     * target is printed, never assumed.
     *
     * @return array{string, string, string, int} database, schema, server version, numeric version
     *
     * @throws Exception on a DBAL failure probing the connection
     */
    private function home(): array
    {
        /** @var array{db: string, schema: string, version: string, version_num: int|string} $row */
        $row = $this->connection->fetchAssociative(
            /** @lang PostgreSQL */
            "SELECT current_database() AS db, current_schema() AS schema,
                    current_setting('server_version') AS version,
                    current_setting('server_version_num')::int AS version_num",
        );

        return [$row['db'], $row['schema'], $row['version'], (int) $row['version_num']];
    }
}
