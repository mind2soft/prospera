<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invites carry the role the invitee will be granted on acceptance
 * (issue #39). Only `collaborator` is grantable today — a profile has
 * exactly one owner and ownership transfer never happens through invites —
 * but the column is a free-form role key so future roles need no migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_profile_invites', function (Blueprint $table): void {
            $table->string('role', 32)->default('collaborator')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('financial_profile_invites', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
