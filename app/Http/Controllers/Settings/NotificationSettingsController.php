<?php

declare(strict_types=1);

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
        $user = $this->currentUser($request);

        return Inertia::render('settings/Notifications', [
            'settings' => $user->notification_settings->toArray(),
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
        $user = $this->currentUser($request);

        $settings = $user->notification_settings->with($validated);

        $user->notification_settings = $settings;
        $user->save();

        return response()->json(['settings' => $settings->toArray()]);
    }
}
