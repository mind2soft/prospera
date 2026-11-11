<?php

namespace App\Actions;

use App\Models\FinancialProfileInvite;
use App\Models\User;
use Illuminate\Support\Collection;
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
            ->referenced()
            ->get();

        if ($envelopes->isEmpty()) {
            return;
        }

        // Only the distinct (envelope, referencing profile) pairs are
        // needed: no transaction or assignment is hydrated (or decrypted).
        $references = DB::table('budget_envelopes')
            ->join('budgets', 'budgets.id', '=', 'budget_envelopes.budget_id')
            ->whereIn('budget_envelopes.envelope_id', $envelopes->modelKeys())
            ->select('budget_envelopes.envelope_id', 'budgets.profile_id')
            ->union(
                DB::table('transactions')
                    ->join('ledgers', 'ledgers.id', '=', 'transactions.ledger_id')
                    ->whereIn('transactions.envelope_id', $envelopes->modelKeys())
                    ->select('transactions.envelope_id', 'ledgers.profile_id'),
            )
            ->get();

        $profileIdsByEnvelope = $references
            ->groupBy('envelope_id')
            ->map(fn (Collection $rows): Collection => $rows->pluck('profile_id')->map(fn ($id): int => (int) $id)->unique()->values());

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
            $profileIds = $profileIdsByEnvelope->get($envelope->id, collect());

            $candidate = $candidates->first(fn (object $row): bool => $profileIds->contains($row->profile_id));

            if ($candidate !== null && $heirs->has($candidate->user_id)) {
                $envelope->transferTo($heirs->get($candidate->user_id));
            }
        }
    }
}
