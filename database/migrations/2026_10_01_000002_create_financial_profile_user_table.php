<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_profile_user', function (Blueprint $table) {
            $table->foreignId('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->boolean('is_owner')->default(false);
            $table->timestamps();

            $table->unique(['profile_id', 'user_id']);
            $table->index(['profile_id', 'created_at']); // earliest-joiner ownership transfer
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_profile_user');
    }
};
