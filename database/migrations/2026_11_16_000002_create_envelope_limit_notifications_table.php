<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Envelope limit exceeded notification (issue #52, docs/notifications.md):
// an envelope is notified at most once per (published budget, envelope,
// ledger) — the unique index over the three ids IS the atomic dedup claim:
// the checker inserts the row before queueing the mails, so concurrent
// recordings and queue retries can never double-send, and a newly published
// budget revision (a new budget_id) re-arms the check.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_limit_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at');
            $table->unique(['budget_id', 'envelope_id', 'ledger_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_limit_notifications');
    }
};
