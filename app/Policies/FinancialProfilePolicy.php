<?php

namespace App\Policies;

use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Profiles are invisible to outsiders (404); members lacking ownership are
 * forbidden owner-level actions (403) — docs/shared-finances.md.
 */
class FinancialProfilePolicy
{
    public function view(User $user, FinancialProfile $profile): Response
    {
        return $profile->isMember($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Owner-level actions: renaming, deleting, inviting, removing other
     * members, transferring ownership and creating budgets or ledgers.
     */
    public function manage(User $user, FinancialProfile $profile): Response
    {
        $visible = $this->view($user, $profile);

        if ($visible->denied()) {
            return $visible;
        }

        return $profile->isOwner($user) ? Response::allow() : Response::denyWithStatus(403);
    }

    public function update(User $user, FinancialProfile $profile): Response
    {
        return $this->manage($user, $profile);
    }

    public function delete(User $user, FinancialProfile $profile): Response
    {
        return $this->manage($user, $profile);
    }

    public function transferOwnership(User $user, FinancialProfile $profile): Response
    {
        return $this->manage($user, $profile);
    }
}
