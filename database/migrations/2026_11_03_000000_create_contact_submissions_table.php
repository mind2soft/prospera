<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contact-form submission log (issue #17): serves both the audit log
        // (who contacted us, when, from where) and the per-IP cooldown check.
        // The message is encrypted at rest — free-form text may contain
        // sensitive details.
        //
        // NOTE: this migration is deliberately dated BEFORE the encryption
        // migration so that `2026_11_03_000001_encrypt_amounts_at_rest` stays
        // the last migration — EncryptAmountsMigrationTest rolls it back with
        // `migrate:rollback --step=1`.
        Schema::create('contact_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('subject');
            $table->text('message');
            $table->string('ip_address', 45);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('ip_address');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
