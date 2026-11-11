<?php

namespace App\Policies;

use App\Models\Ledger;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Auth\Access\Response;

/**
 * Ledger access from the caller's effective permission
 * (ResourcePermissions::inspect — "none" is a 404, too low a 403).
 */
class LedgerPolicy
{
    public function view(User $user, Ledger $ledger): Response
    {
        return ResourcePermissions::inspect($ledger->permissionFor($user), ResourcePermissions::VIEWER);
    }

    /**
     * Recording transactions (the envelope permission is checked
     * separately).
     */
    public function record(User $user, Ledger $ledger): Response
    {
        return ResourcePermissions::inspect($ledger->permissionFor($user), ResourcePermissions::CONTRIBUTOR);
    }

    /**
     * Closing and re-opening.
     */
    public function update(User $user, Ledger $ledger): Response
    {
        return $this->owner($user, $ledger);
    }

    public function delete(User $user, Ledger $ledger): Response
    {
        return $this->owner($user, $ledger);
    }

    public function share(User $user, Ledger $ledger): Response
    {
        return $this->owner($user, $ledger);
    }

    public function transferOwnership(User $user, Ledger $ledger): Response
    {
        return $this->owner($user, $ledger);
    }

    private function owner(User $user, Ledger $ledger): Response
    {
        return ResourcePermissions::inspect($ledger->permissionFor($user), ResourcePermissions::OWNER);
    }
}
