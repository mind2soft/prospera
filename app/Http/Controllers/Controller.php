<?php

namespace App\Http\Controllers;

use App\Models\FinancialProfile;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

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
