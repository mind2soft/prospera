<?php

namespace App\Http\Controllers;

use App\Models\FinancialProfile;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve a profile the caller belongs to. Unknown profiles and
     * non-members get a 404 (profiles are invisible to outsiders); members
     * lacking ownership get a 403 when $ownerOnly is set.
     */
    protected function authorizeProfile(Request $request, int|FinancialProfile $profile, bool $ownerOnly = false): FinancialProfile
    {
        if (! $profile instanceof FinancialProfile) {
            $profile = FinancialProfile::find($profile);
        }

        abort_if($profile === null, 404);
        abort_unless($profile->isMember($request->user()), 404);

        if ($ownerOnly) {
            abort_unless($profile->isOwner($request->user()), 403);
        }

        return $profile;
    }
}
