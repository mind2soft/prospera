<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dashboard widget configuration (issue #29): one row per widget,
        // keyed by user AND financial profile — every profile member manages
        // their own dashboard per profile. Widget configuration references
        // envelope ids only; no money amounts are stored here.
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // envelope_spending | income_expenses | radial
            $table->string('title')->nullable(); // null → client renders the localized default title
            $table->string('view_type'); // widget-specific; validated against type
            $table->unsignedTinyInteger('column_width'); // 1..5 columns of the xl grid
            $table->string('period_type'); // days | months
            $table->unsignedSmallInteger('period_range'); // canonical enumeration, see DashboardWidget
            $table->json('selection'); // ordered selection items (envelopes / flat groups)
            $table->unsignedInteger('sort_order'); // drag & drop ordering
            $table->timestamps();

            $table->index(['user_id', 'financial_profile_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
