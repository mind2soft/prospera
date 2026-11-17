<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_amount_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('wrapped_key');
            $table->timestamp('retired_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['envelope_id', 'version']);
        });

        Schema::create('envelope_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->string('amount', 80);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('period', 16);
            $table->json('weekdays');
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedInteger('reminder_days')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_schedules');
        Schema::dropIfExists('envelope_amount_keys');
    }
};
