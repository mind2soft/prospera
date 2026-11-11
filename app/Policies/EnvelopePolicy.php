<?php

namespace App\Policies;

use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Envelope management is owner-only; anything else is invisible (404).
 */
class EnvelopePolicy
{
    public function update(User $user, Envelope $envelope): Response
    {
        return $this->owner($user, $envelope);
    }

    public function delete(User $user, Envelope $envelope): Response
    {
        return $this->owner($user, $envelope);
    }

    public function share(User $user, Envelope $envelope): Response
    {
        return $this->owner($user, $envelope);
    }

    public function transferOwnership(User $user, Envelope $envelope): Response
    {
        return $this->owner($user, $envelope);
    }

    /**
     * Shares are visible to the owner and to profile co-members of the
     * owner.
     */
    public function viewShares(User $user, Envelope $envelope): Response
    {
        return $envelope->isOwner($user) || FinancialProfile::membersShareAProfile($envelope->user, $user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function owner(User $user, Envelope $envelope): Response
    {
        return $envelope->isOwner($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
