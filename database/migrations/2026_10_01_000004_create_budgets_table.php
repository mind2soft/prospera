<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('financial_profiles')->restrictOnDelete();
            $table->string('currency', 3);
            $table->unsignedInteger('effective_year')->default(0);
            $table->unsignedTinyInteger('effective_month')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            // Per-profile lookups: draft slot, month replacement, latest published.
            $table->index(['profile_id', 'effective_year', 'effective_month']);
            $table->index(['profile_id', 'published_at']);
            $table->index('retired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
