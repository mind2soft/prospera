<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_profiles', function (Blueprint $table): void {
            $table->timestamp('initial_budget_created_at')->nullable();
        });

        DB::table('financial_profiles')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('budgets')
                    ->whereColumn('budgets.profile_id', 'financial_profiles.id');
            })
            ->update(['initial_budget_created_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('financial_profiles', function (Blueprint $table): void {
            $table->dropColumn('initial_budget_created_at');
        });
    }
};
