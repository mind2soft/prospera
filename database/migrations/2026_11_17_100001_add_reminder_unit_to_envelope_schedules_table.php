<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #117: a `month` reminder unit lets a schedule remind one calendar
 * month before an occurrence. `day` (the legacy semantics, and the treatment
 * of legacy nulls in the model accessor) keeps `reminder_days` meaning days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelope_schedules', function (Blueprint $table): void {
            $table->string('reminder_unit', 10)->nullable()->after('reminder_days');
        });
    }

    public function down(): void
    {
        Schema::table('envelope_schedules', function (Blueprint $table): void {
            $table->dropColumn('reminder_unit');
        });
    }
};
