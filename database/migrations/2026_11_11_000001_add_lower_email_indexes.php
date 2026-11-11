<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Invites match emails case-insensitively (`lower(email) = ?`), which the
 * plain `users.email` and `(profile_id, email)` indexes cannot serve: add
 * expression indexes on `lower(email)`. PostgreSQL and SQLite index the
 * expression directly; MySQL (8.0.13+) needs it parenthesized. Other
 * drivers are left untouched.
 */
return new class extends Migration
{
    /** @var array<string, string> index name => indexed column list */
    private const INDEXES = [
        'users_lower_email_index' => 'users|lower(email)',
        'financial_profile_invites_profile_id_lower_email_index' => 'financial_profile_invites|profile_id, lower(email)',
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        foreach (self::INDEXES as $name => $definition) {
            [$table, $columns] = explode('|', $definition);

            $sql = match ($driver) {
                'pgsql', 'sqlite' => "create index {$name} on {$table} ({$columns})",
                'mysql', 'mariadb' => "create index {$name} on {$table} (".str_replace('lower(email)', '(lower(email))', $columns).')',
                default => null,
            };

            if ($sql !== null) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        foreach (self::INDEXES as $name => $definition) {
            [$table] = explode('|', $definition);

            $sql = match ($driver) {
                'pgsql', 'sqlite' => "drop index if exists {$name}",
                'mysql', 'mariadb' => "drop index {$name} on {$table}",
                default => null,
            };

            if ($sql !== null) {
                DB::statement($sql);
            }
        }
    }
};
