<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * The authenticated user. Auth middleware guarantees one on the routes
     * these controllers serve; this makes the invariant explicit and fails
     * closed with 401 if it ever stops holding (guest-only routes never
     * call it).
     */
    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401);

        return $user;
    }

    /**
     * Resolve a profile passed by id (e.g. `profile_id` in a payload) and
     * authorize the FinancialProfilePolicy ability on it. Unknown profiles
     * and non-members get a 404 (profiles are invisible to outsiders).
     */
    protected function authorizeProfileId(int $profileId, string $ability = 'view'): FinancialProfile
    {
        $profile = FinancialProfile::find($profileId);

        abort_if($profile === null, 404);

        $this->authorize($ability, $profile);

        return $profile;
    }
}
