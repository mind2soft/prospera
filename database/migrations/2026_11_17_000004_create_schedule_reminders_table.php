<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Payment reminders (issue #15, docs/scheduled-transactions.md): a reminder
// is sent at most once per (schedule, occurrence date, recipient) — the
// unique index IS the atomic dedup claim: `schedules:remind` inserts the row
// before queueing the mail, so re-runs, overlapping runs and catch-ups never
// double-send. An edit moving an occurrence yields a new date, which re-arms
// only that date.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('envelope_schedules')->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('queued_at');
            $table->unique(['schedule_id', 'occurrence_date', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_reminders');
    }
};
