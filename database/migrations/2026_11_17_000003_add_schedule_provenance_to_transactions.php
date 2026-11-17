<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generated-transaction provenance (issue #15, docs/scheduled-transactions.md):
 * the restrictive FK keeps closed-ledger rows pointing at soft-deleted
 * schedules; the unique index holds one row per occurrence and ledger while
 * manual rows (null schedule_id) may duplicate freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('schedule_id')->nullable()->constrained('envelope_schedules')->restrictOnDelete();
            $table->unique(['ledger_id', 'schedule_id', 'event_at']);
            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        if (DB::table('transactions')->whereNotNull('schedule_id')->exists()) {
            throw new RuntimeException('Cannot remove schedule provenance while generated transactions exist.');
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(['ledger_id', 'schedule_id', 'event_at']);
            $table->dropIndex(['schedule_id']);
            $table->dropConstrainedForeignId('schedule_id');
        });
    }
};
