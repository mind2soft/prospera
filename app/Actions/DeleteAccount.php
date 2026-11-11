<?php

namespace App\Actions;

use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\FinancialProfileInvite;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Delete a user account (docs/shared-finances.md — Account termination).
 *
 * Account deletion is never blocked: ownership of owned profiles passes to
 * the earliest joiner (ties broken by the lowest user_id) and solo profiles
 * are deleted along with the owner's resources in it. Resources owned by the
 * deleted user auto-transfer to the profile owners; the dependency order
 * (transactions → ledgers → budgets → envelopes) is guaranteed by the
 * cascade rules on the foreign keys. Shared by the self-service deletion
 * (verified or not) and the scheduled prune of unverified accounts.
 */
class DeleteAccount
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Ownership of owned profiles passes to the earliest joiner (ties
            // broken by the lowest user_id); solo profiles are deleted along
            // with the owner's resources in them. Resources owned by the
            // deleted user in the remaining profiles auto-transfer to the
            // profile owners (profiles are processed earliest-first, so for
            // envelopes referenced by several profiles the earliest-created
            // referencing profile's owner wins).
            $profiles = $user->profiles()->orderBy('financial_profiles.created_at')->get();

            foreach ($profiles as $profile) {
                if ($profile->isOwner($user) && $profile->isSolo()) {
                    $profile->deleteWithResources();

                    continue;
                }

                if ($profile->isOwner($user)) {
                    $profile->transferOwnershipToEarliestJoiner();
                }

                $profile->removeMember($user, accountDeletion: true);
            }

            // Referenced envelopes transfer to the owner of the
            // earliest-created profile referencing them; unreferenced
            // envelopes are deleted with the account (cascade).
            $this->transferReferencedEnvelopes($user);

            // Pending invites the user issued on surviving profiles stay
            // valid, re-attributed to the profile's new owner.
            FinancialProfileInvite::reassignIssuedBy($user);

            // An invite reserved by this (unverified) account is released
            // instead of cascading away: the owner's invite stays usable.
            FinancialProfileInvite::releaseReservationOf($user);

            $user->delete();
        });
    }

    /**
     * Transfer the user's envelopes that are referenced by budgets or
     * transactions to the owner of the earliest-created referencing profile
     * (falling back to its earliest other member), so the account deletion
     * never trips the restrictive foreign keys on referenced envelopes.
     */
    private function transferReferencedEnvelopes(User $user): void
    {
        $envelopes = $user->envelopes()
            ->where(fn (Builder $query) => $query->whereHas('budgetEnvelopes')->orWhereHas('transactions'))
            ->with(['budgetEnvelopes.budget:id,profile_id', 'transactions.ledger:id,profile_id'])
            ->get();

        if ($envelopes->isEmpty()) {
            return;
        }

        $profileIdsByEnvelope = $envelopes->mapWithKeys(fn (Envelope $envelope): array => [
            $envelope->id => $envelope->budgetEnvelopes
                ->map(fn (BudgetEnvelope $assignment) => $assignment->budget->profile_id)
                ->merge($envelope->transactions->map(fn (Transaction $transaction) => $transaction->ledger->profile_id))
                ->unique()
                ->values(),
        ]);

        // One query for every candidate heir: per referencing profile, its
        // owner first, then the other members by join date.
        $candidates = DB::table('financial_profile_user')
            ->join('financial_profiles', 'financial_profiles.id', '=', 'financial_profile_user.profile_id')
            ->whereIn('financial_profile_user.profile_id', $profileIdsByEnvelope->flatten()->unique())
            ->where('financial_profile_user.user_id', '!=', $user->id)
            ->orderBy('financial_profiles.created_at')
            ->orderBy('financial_profiles.id')
            ->orderByDesc('financial_profile_user.is_owner')
            ->orderBy('financial_profile_user.created_at')
            ->get(['financial_profile_user.profile_id', 'financial_profile_user.user_id']);

        $heirs = User::query()->whereIn('id', $candidates->pluck('user_id')->unique())->get()->keyBy('id');

        foreach ($envelopes as $envelope) {
            $profileIds = $profileIdsByEnvelope->get($envelope->id);

            $candidate = $candidates->first(fn (object $row): bool => $profileIds->contains($row->profile_id));

            if ($candidate !== null && $heirs->has($candidate->user_id)) {
                $envelope->transferTo($heirs->get($candidate->user_id));
            }
        }
    }
}
