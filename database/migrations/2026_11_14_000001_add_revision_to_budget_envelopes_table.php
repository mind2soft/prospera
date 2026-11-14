<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_envelopes', function (Blueprint $table): void {
            // Compare-and-write guard for concurrent amount edits (two tabs,
            // or a stale autosave racing a newer one): the client PATCHes the
            // revision it edited against, and a mismatch is rejected without
            // changing data. Not encrypted — it never hides an amount.
            $table->unsignedInteger('revision')->default(1)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('budget_envelopes', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
