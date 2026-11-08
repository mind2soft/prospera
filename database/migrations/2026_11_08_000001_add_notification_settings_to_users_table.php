<?php

use App\Settings\NotificationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user email notification settings. A JSON column (not columns):
     * the shape is a closed catalog of toggles (see
     * {@see NotificationSettings}) that future notification
     * senders gate on at send time. `null` means "the user never opened
     * the settings page" — every toggle defaults to enabled.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_settings')->nullable()->after('locale')
                ->comment('Email notification preferences (App\Settings\NotificationSettings).');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });
    }
};
