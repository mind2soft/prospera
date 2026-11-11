<?php

namespace App\Policies;

use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FinancialProfileInvitePolicy
{
    public function __construct(private readonly FinancialProfilePolicy $profiles) {}

    /**
     * Revoking: profile owner only, and only the profile's own invites.
     */
    public function delete(User $user, FinancialProfileInvite $invite, FinancialProfile $profile): Response
    {
        $managed = $this->profiles->manage($user, $profile);

        if ($managed->denied()) {
            return $managed;
        }

        return $invite->profile_id === $profile->id ? Response::allow() : Response::denyAsNotFound();
    }
}
