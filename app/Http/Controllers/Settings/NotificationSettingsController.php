<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationSettingsUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    /**
     * Show the user's notification settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Notifications', [
            'settings' => $request->user()->notification_settings->toArray(),
        ]);
    }

    /**
     * Update the user's notification settings (autosave — the page sends
     * the full catalog, the master switch included, so the stored object is
     * always complete).
     */
    public function update(NotificationSettingsUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $settings = $request->user()->notification_settings->with($validated);

        $request->user()->notification_settings = $settings;
        $request->user()->save();

        return response()->json(['settings' => $settings->toArray()]);
    }
}
