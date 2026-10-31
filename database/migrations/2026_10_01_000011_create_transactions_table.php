<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enveloppe_id')->constrained('enveloppes')->restrictOnDelete();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->integer('amount'); // signed: positive for revenue, negative for expense
            $table->timestamp('event_at'); // stored at 00:00:00
            $table->timestamps();

            $table->index(['ledger_id', 'event_at']);
            $table->index(['enveloppe_id', 'event_at']); // enveloppe totals/history
            $table->index('budget_id'); // retired-budget sweep
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
