<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ledger expired notification (issue #50, docs/notifications.md): a ledger
// is notified at most once ever, when the daily run first detects it past
// its end date while open. The nullable timestamp doubles as the dedup
// state and the atomic claim marker — re-running the command, a re-opened
// ledger, or a scheduler catch-up never sends a second email.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledgers', function (Blueprint $table): void {
            $table->timestamp('expired_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ledgers', function (Blueprint $table): void {
            $table->dropColumn('expired_notified_at');
        });
    }
};
