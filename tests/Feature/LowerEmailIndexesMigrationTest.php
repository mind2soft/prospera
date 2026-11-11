<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Case-insensitive email lookups (`lower(email) = ?`) are served by
 * expression indexes, and the migration is reversible.
 */
class LowerEmailIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lower_email_lookups_use_an_index(): void
    {
        $this->assertContains('users_lower_email_index', $this->indexNames('users'));
        $this->assertContains('financial_profile_invites_profile_id_lower_email_index', $this->indexNames('financial_profile_invites'));

        if (DB::getDriverName() === 'sqlite') {
            $plan = collect(DB::select('explain query plan select * from users where lower(email) = ?', ['a@example.com']))
                ->pluck('detail')
                ->implode(' ');

            $this->assertStringContainsString('users_lower_email_index', $plan);
        }
    }

    public function test_the_migration_is_reversible(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_11_000001_add_lower_email_indexes.php');

        $migration->down();

        $this->assertNotContains('users_lower_email_index', $this->indexNames('users'));
        $this->assertNotContains('financial_profile_invites_profile_id_lower_email_index', $this->indexNames('financial_profile_invites'));

        $migration->up();

        $this->assertContains('users_lower_email_index', $this->indexNames('users'));
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        return array_values(array_map(fn (array $index): string => $index['name'], Schema::getIndexes($table)));
    }
}
