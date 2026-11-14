<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Auth\Access\Response;

class TransactionPolicy
{
    /**
     * Ledger owners delete any entry, contributors their own entries only,
     * viewers none; the ledger is invisible (404) without any permission.
     */
    public function delete(User $user, Transaction $transaction): Response
    {
        $permission = $transaction->ledger->permissionFor($user);

        if ($permission === ResourcePermissions::NONE) {
            return Response::denyAsNotFound();
        }

        $mayDelete = $permission === ResourcePermissions::OWNER
            || ($permission === ResourcePermissions::CONTRIBUTOR && $transaction->user_id === $user->id);

        return $mayDelete ? Response::allow() : Response::denyWithStatus(403);
    }

    /**
     * Undoing a deletion follows the deletion rules (docs/ledger.md).
     */
    public function restore(User $user, Transaction $transaction): Response
    {
        return $this->delete($user, $transaction);
    }
}
