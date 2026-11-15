<?php

/**
 * Shared helpers for the e2e seed scripts, required with
 * `require_once __DIR__.'/seed-helpers.php';` (seed scripts that loop, like
 * seed-ledger-plain.php, may reach the require more than once).
 */

use App\Models\BudgetEnvelope;
use App\Models\Transaction;
use App\Models\User;

/**
 * Deletes the user's envelopes together with every reference that
 * restricts their deletion: the budget assignments of every profile's
 * budget (the same envelope can be assigned to drafts of several profiles —
 * see seed-budget-profiles.php) and the transactions recorded against them
 * in every ledger, soft-deleted ones included. The seeds rebuild the
 * user's financial state from scratch, so dropping the cross-profile
 * references here keeps every seed consumable by any other seed regardless
 * of what an earlier seed left behind.
 */
function clear_envelopes_of(User $user): void
{
    $envelopeIds = $user->envelopes()->select('id')->pluck('id');

    Transaction::withTrashed()->whereIn('envelope_id', $envelopeIds)->delete();
    BudgetEnvelope::whereIn('envelope_id', $envelopeIds)->delete();
    $user->envelopes()->delete();
}
