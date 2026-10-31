<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('permission', 20); // 'owner', 'contributor', 'viewer'
            $table->timestamps();

            $table->unique(['budget_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_permissions');
    }
};
