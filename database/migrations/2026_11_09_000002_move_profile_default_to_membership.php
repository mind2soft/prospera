<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Default profile" becomes a per-user notion (issue #41): it moves from the
 * profile-level `financial_profiles.is_default` flag — which could not
 * describe shared profiles (one row, several users) — to the
 * `financial_profile_user` pivot, one default per user.
 *
 * The row flag is dropped along with its deletion guard: profile deletion
 * now always reassigns the affected users' defaults (earliest created owned
 * profile, else earliest remaining membership, else a seeded default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_profile_user', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('is_owner');
        });

        // Backfill pass 1: flag each user's currently flagged owned profile
        // (legacy accounts always own exactly one — seeded at registration).
        DB::table('financial_profile_user as pivot')
            ->join('financial_profiles as profiles', 'profiles.id', '=', 'pivot.profile_id')
            ->where('profiles.is_default', true)
            ->where('pivot.is_owner', true)
            ->update(['pivot.is_default' => true]);

        // Backfill pass 2: non-owner members of a flagged profile saw it as
        // their default too (the row flag was profile-wide). Flag them unless
        // they already have a default from pass 1 (their own flagged profile
        // takes precedence).
        DB::table('financial_profile_user as pivot')
            ->join('financial_profiles as profiles', 'profiles.id', '=', 'pivot.profile_id')
            ->where('profiles.is_default', true)
            ->where('pivot.is_owner', false)
            ->whereNotIn('pivot.user_id', function ($query): void {
                $query->select('user_id')
                    ->from('financial_profile_user')
                    ->where('is_default', true);
            })
            ->update(['pivot.is_default' => true]);

        Schema::table('financial_profiles', function (Blueprint $table): void {
            $table->dropIndex(['is_default']);
            $table->dropColumn('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('financial_profiles', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false);
            $table->index('is_default');
        });

        // Best-effort restore: flag the profile each user holds as their
        // pivot default when they own it (the row flag could only describe
        // one profile per account anyway).
        DB::table('financial_profile_user as pivot')
            ->join('financial_profiles as profiles', 'profiles.id', '=', 'pivot.profile_id')
            ->where('pivot.is_default', true)
            ->where('pivot.is_owner', true)
            ->update(['profiles.is_default' => true]);

        Schema::table('financial_profile_user', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
