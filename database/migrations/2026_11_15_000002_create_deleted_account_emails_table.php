<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Blacklisted email addresses of deleted accounts (issue #71): a
        // deleted account's email can no longer be recycled — neither to
        // register a new account, nor as another account's email address.
        // Not encrypted — an email address never hides an amount.
        Schema::create('deleted_account_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            // When the deleted account was created, and when it was deleted.
            $table->timestamp('account_created_at')->nullable();
            $table->timestamp('account_deleted_at');
            $table->timestamps();

            // Rows are looked up case-insensitively (`lower(email) = ?`),
            // which a plain `email` index cannot serve — same convention as
            // the `users` and `financial_profile_invites` indexes.
            $table->unique(['email']);
        });

        $driver = DB::getDriverName();

        // One blacklisted row per address, regardless of case.
        $sql = match ($driver) {
            'pgsql', 'sqlite' => 'create unique index deleted_account_emails_lower_email_unique on deleted_account_emails (lower(email))',
            'mysql', 'mariadb' => 'create unique index deleted_account_emails_lower_email_unique on deleted_account_emails ((lower(email)))',
            default => null,
        };

        if ($sql !== null) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        $sql = match ($driver) {
            'pgsql', 'sqlite' => 'drop index if exists deleted_account_emails_lower_email_unique',
            'mysql', 'mariadb' => 'drop index deleted_account_emails_lower_email_unique on deleted_account_emails',
            default => null,
        };

        if ($sql !== null) {
            DB::statement($sql);
        }

        Schema::dropIfExists('deleted_account_emails');
    }
};
