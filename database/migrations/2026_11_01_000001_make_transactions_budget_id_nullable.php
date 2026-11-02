<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactions no longer require a published budget — envelopes are the only
 * dependency for recording ledger entries (a budget is necessary for
 * reporting only). The budget snapshot becomes optional: it is kept when the
 * envelope belongs to the latest published budget, null otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['budget_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('budget_id')->nullable()->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('budget_id')
                ->references('id')
                ->on('budgets')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Transactions recorded without a budget cannot satisfy the original
        // non-null constraint; they must be removed first.
        DB::table('transactions')->whereNull('budget_id')->delete();

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['budget_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('budget_id')->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('budget_id')
                ->references('id')
                ->on('budgets')
                ->restrictOnDelete();
        });
    }
};
