<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A draft budget only ever belongs to the profile owner (docs/budget.md —
 * Ownership). Before that rule was enforced, the Share drawer could hand a
 * draft to a collaborator: nobody could publish it any more (publishing
 * needs budget and profile ownership) and it held the profile's single
 * draft slot. Such drafts go back to the profile owner; their collaborator
 * owner becomes contributor, as on any ownership transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stranded = DB::table('budgets')
            ->join('budget_permissions as owners', function ($join): void {
                $join->on('owners.budget_id', '=', 'budgets.id')->where('owners.permission', 'owner');
            })
            ->join('financial_profile_user as profile_owners', function ($join): void {
                $join->on('profile_owners.profile_id', '=', 'budgets.profile_id')->where('profile_owners.is_owner', true);
            })
            ->whereNull('budgets.published_at')
            ->whereColumn('owners.user_id', '!=', 'profile_owners.user_id')
            ->get(['budgets.id', 'owners.user_id as budget_owner_id', 'profile_owners.user_id as profile_owner_id']);

        foreach ($stranded as $draft) {
            DB::transaction(function () use ($draft): void {
                $now = now();

                DB::table('budget_permissions')
                    ->where('budget_id', $draft->id)
                    ->where('user_id', $draft->budget_owner_id)
                    ->update(['permission' => 'contributor', 'updated_at' => $now]);

                $promoted = DB::table('budget_permissions')
                    ->where('budget_id', $draft->id)
                    ->where('user_id', $draft->profile_owner_id)
                    ->update(['permission' => 'owner', 'updated_at' => $now]);

                if ($promoted === 0) {
                    DB::table('budget_permissions')->insert([
                        'budget_id' => $draft->id,
                        'user_id' => $draft->profile_owner_id,
                        'permission' => 'owner',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        // Data repair: nothing to restore.
    }
};
