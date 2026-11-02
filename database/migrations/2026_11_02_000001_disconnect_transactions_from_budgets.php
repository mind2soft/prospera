<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactions are disconnected from budgets (issue #12): the `budget_id`
 * column is removed. Budgets are only necessary to draw a financial profile
 * and report on spending limits; being bound to a month and year, they can
 * be matched to transactions by month/year without an FK.
 *
 * This also retires the "retired budget" concept, which existed solely to
 * keep replaced published budgets alive while transactions still referenced
 * them: replaced published budgets are now simply deleted, and lingering
 * retired budgets are swept by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The FK must go first: retired budgets exist precisely because
        // transactions still referenced them, and SQLite enforces foreign
        // keys during migrations.
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['budget_id']);
            $table->dropIndex(['budget_id']);
            $table->dropColumn('budget_id');
        });

        // Retired budgets are hidden leftovers of the removed snapshot
        // mechanism — nothing references them any more.
        DB::table('budgets')->whereNotNull('retired_at')->delete();

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex(['retired_at']);
            $table->dropColumn('retired_at');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->timestamp('retired_at')->nullable()->index();
        });

        // Transactions recorded without a budget cannot satisfy the original
        // non-null constraint; they must be removed first.
        DB::table('transactions')->delete();

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->index('budget_id'); // retired-budget sweep
        });
    }
};
