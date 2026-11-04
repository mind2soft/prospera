<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * The migrations the database has run, read from the migrations table — the
 * ground truth the deploy and rollback scripts compare releases against
 * (production migrations are forward-only, docs/encryption.md).
 */
class ListRanMigrations extends Command
{
    /** Exit code when the migrations table does not exist (fresh install). */
    public const NO_REPOSITORY = 2;

    protected $signature = 'migrations:ran';

    protected $description = 'List the migrations the database has run, one per line';

    public function handle(Migrator $migrator): int
    {
        if (! $migrator->repositoryExists()) {
            $this->getOutput()->getErrorStyle()->writeln('Migration table not found.');

            return self::NO_REPOSITORY;
        }

        foreach ($migrator->getRepository()->getRan() as $migration) {
            $this->line((string) $migration);
        }

        return self::SUCCESS;
    }
}
