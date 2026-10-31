<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_enveloppes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enveloppe_id')->constrained('enveloppes')->restrictOnDelete();
            $table->integer('amount'); // signed: positive for revenue, negative for expense
            $table->string('type', 10); // 'fixed' or 'variable'
            $table->timestamps();

            $table->unique(['budget_id', 'enveloppe_id']);
            $table->index('enveloppe_id'); // "is this enveloppe referenced?" checks
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_enveloppes');
    }
};
