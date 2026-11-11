<?php

namespace App\Policies;

use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\ResourcePermissions;
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
     * Using the envelope's money: assigning it to a budget or recording
     * transactions against it takes contributor+ on the envelope
     * (docs/envelope.md). Callers report a refusal as a validation error on
     * the payload's envelope_id, so it stays a plain yes/no.
     */
    public function use(User $user, Envelope $envelope): bool
    {
        return ResourcePermissions::isAtLeast($envelope->permissionFor($user), ResourcePermissions::CONTRIBUTOR);
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
