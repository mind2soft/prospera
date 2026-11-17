<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinancialLockSetChanged;
use App\Models\Budget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use App\Security\Amounts\AmountContext;
use Illuminate\Support\Facades\DB;

/** Establish the complete deletion root set inside the caller's transaction, before any mutation. */
final class AccountDeletionLocks
{
    public function lock(int $userId): ?User
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Account-deletion roots require an active transaction.');
        }
        $profiles = FinancialProfile::query()->whereIn('id', $this->profileIds($userId))
            ->orderBy('id')->lockForUpdate()->get(['id']);
        $ids = $profiles->modelKeys();

        // Global id order across profiles, not a per-profile deletion loop.
        // Profile FK locks also hold new ledger/budget inserts behind us.
        Ledger::query()->whereIn('profile_id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        Budget::query()->whereIn('profile_id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);

        $user = User::query()->whereKey($userId)->lockForUpdate()->first();
        if ($user === null) {
            return null;
        }

        // A new owned envelope/membership must pass the user's FK lock; a new
        // assignment/reference must pass these envelope locks. Discover once
        // more after both barriers to catch work committed during discovery.
        Envelope::query()->where('user_id', $userId)->orderBy('id')->lockForUpdate()->get(['id']);

        if (array_diff($this->profileIds($userId), $ids) !== []) {
            throw new FinancialLockSetChanged('Financial resources changed while acquiring account-deletion locks.');
        }

        return $user;
    }

    /**
     * Includes references outside the account's memberships and pending
     * transaction deletions. IDs only: no financial amounts are selected.
     *
     * @return list<int>
     */
    private function profileIds(int $userId): array
    {
        $envelopes = Envelope::query()->where('user_id', $userId)->select('id');

        return array_values(DB::table('financial_profile_user')->where('user_id', $userId)->select('profile_id')
            ->union(DB::table('budget_envelopes')->join('budgets', 'budgets.id', '=', 'budget_envelopes.budget_id')
                ->whereIn('budget_envelopes.envelope_id', $envelopes)->select('budgets.profile_id'))
            ->union(DB::table('transactions')->join('ledgers', 'ledgers.id', '=', 'transactions.ledger_id')
                ->whereIn('transactions.envelope_id', $envelopes)->select('ledgers.profile_id'))
            ->orderBy('profile_id')->pluck('profile_id')
            ->map(fn (mixed $id): int => AmountContext::id($id, 'profile_id'))->all());
    }
}
