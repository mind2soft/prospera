<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Undoable transaction deletion (issue #48, docs/undoable-deletion.md): a
 * deleted transaction is soft-deleted, restorable during a short window,
 * then purged by `transactions:purge-deleted`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable();

            $table->index('deleted_at'); // purge sweep
        });
    }

    public function down(): void
    {
        // Pending deletions would otherwise come back as live entries.
        DB::table('transactions')->whereNotNull('deleted_at')->delete();

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
    }
};
