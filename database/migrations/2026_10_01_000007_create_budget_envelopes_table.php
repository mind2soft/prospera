<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();
            $table->integer('amount'); // signed: positive for revenue, negative for expense
            $table->string('type', 10); // 'fixed' or 'variable'
            $table->timestamps();

            $table->unique(['budget_id', 'envelope_id']);
            $table->index('envelope_id'); // "is this envelope referenced?" checks
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_envelopes');
    }
};
