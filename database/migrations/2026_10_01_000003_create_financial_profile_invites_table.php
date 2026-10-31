<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_profile_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->string('invite_key', 64)->unique(); // sha-256 hash of the raw link key
            $table->string('email');
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            // Set when a new account registers through the invite: membership
            // is granted once that account verifies its email address.
            $table->foreignId('pending_user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['profile_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_profile_invites');
    }
};
