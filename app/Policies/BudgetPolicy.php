<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Auth\Access\Response;

/**
 * Budget access from the caller's effective permission
 * (ResourcePermissions::inspect — "none" is a 404, too low a 403).
 */
class BudgetPolicy
{
    public function __construct(private readonly FinancialProfilePolicy $profiles) {}

    public function view(User $user, Budget $budget): Response
    {
        return ResourcePermissions::inspect($budget->permissionFor($user), ResourcePermissions::VIEWER);
    }

    /**
     * Editing the draft's envelope assignments.
     */
    public function update(User $user, Budget $budget): Response
    {
        return $this->owner($user, $budget);
    }

    public function delete(User $user, Budget $budget): Response
    {
        return $this->owner($user, $budget);
    }

    public function share(User $user, Budget $budget): Response
    {
        return $this->owner($user, $budget);
    }

    public function transferOwnership(User $user, Budget $budget): Response
    {
        return $this->owner($user, $budget);
    }

    /**
     * Publishing replaces profile-wide data: budget and profile ownership.
     */
    public function publish(User $user, Budget $budget): Response
    {
        return $this->andProfileOwner($this->owner($user, $budget), $user, $budget);
    }

    /**
     * The clone occupies the profile's single draft slot: budget viewer and
     * profile owner.
     */
    public function clone(User $user, Budget $budget): Response
    {
        return $this->andProfileOwner($this->view($user, $budget), $user, $budget);
    }

    private function owner(User $user, Budget $budget): Response
    {
        return ResourcePermissions::inspect($budget->permissionFor($user), ResourcePermissions::OWNER);
    }

    private function andProfileOwner(Response $response, User $user, Budget $budget): Response
    {
        return $response->denied() ? $response : $this->profiles->manage($user, $budget->profile);
    }
}
