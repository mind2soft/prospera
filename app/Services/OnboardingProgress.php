<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Security\Amounts\AmountKeyRing;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * First-budget setup rules and the encrypted-amount-safe seed for a new
 * profile's first draft budget.
 */
final class OnboardingProgress
{
    public const REQUIRED_REVENUE_ENVELOPES = 1;

    public const REQUIRED_EXPENSE_ENVELOPES = 3;

    public const REQUIRED_HISTORY_DAYS = 30;

    /**
     * The profile owner is the person who creates its first ledger and
     * budget. Envelopes are account-owned (not profile-owned), so setup uses
     * the current owner's envelopes for a predictable first-run checklist.
     *
     * @return array<string, int|bool>
     */
    public function forProfile(FinancialProfile $profile): array
    {
        $owner = $profile->ownerUser();
        $envelopes = $owner === null
            ? collect()
            : Envelope::query()->where('user_id', $owner->id)->get(['id', 'type']);

        $revenueCount = $envelopes->where('type', Envelope::DIRECTION_REVENUE)->count();
        $expenseCount = $envelopes->where('type', Envelope::DIRECTION_EXPENSE)->count();
        $envelopeIds = $envelopes->pluck('id');
        $ledgerIds = Ledger::query()->where('profile_id', $profile->id)->pluck('id');

        $firstRecordedAt = $envelopeIds->isEmpty()
            ? null
            : Transaction::query()
                ->whereIn('ledger_id', $ledgerIds)
                ->whereIn('envelope_id', $envelopeIds)
                ->min('created_at');

        $transactedEnvelopeIds = $envelopeIds->isEmpty()
            ? collect()
            : Transaction::query()
                ->whereIn('ledger_id', $ledgerIds)
                ->whereIn('envelope_id', $envelopeIds)
                ->distinct()
                ->pluck('envelope_id');

        $elapsedHistoryDays = $firstRecordedAt === null
            ? 0
            : (int) floor(max(0, Carbon::parse($firstRecordedAt)->diffInDays(now(), false)));
        $daysOfHistory = min(self::REQUIRED_HISTORY_DAYS, $elapsedHistoryDays);
        $missingEnvelopeTransactions = $envelopeIds->diff($transactedEnvelopeIds)->count();
        $missingRevenue = max(0, self::REQUIRED_REVENUE_ENVELOPES - $revenueCount);
        $missingExpenses = max(0, self::REQUIRED_EXPENSE_ENVELOPES - $expenseCount);

        return [
            'revenue_envelopes' => $revenueCount,
            'expense_envelopes' => $expenseCount,
            'missing_revenue_envelopes' => $missingRevenue,
            'missing_expense_envelopes' => $missingExpenses,
            'can_create_ledger' => $missingRevenue === 0 && $missingExpenses === 0,
            'has_ledger' => $ledgerIds->isNotEmpty(),
            'history_days' => $daysOfHistory,
            'history_days_remaining' => self::REQUIRED_HISTORY_DAYS - $daysOfHistory,
            'envelopes_with_transactions' => $envelopeIds->count() - $missingEnvelopeTransactions,
            'envelopes_missing_transactions' => $missingEnvelopeTransactions,
            'first_budget_created' => $profile->initial_budget_created_at !== null,
            'can_create_budget' => $profile->initial_budget_created_at !== null
                || ($daysOfHistory >= self::REQUIRED_HISTORY_DAYS && $missingEnvelopeTransactions === 0),
        ];
    }

    /**
     * Sum the owner's last 30 days of transactions in PHP. `amount` is
     * encrypted at rest and must never be aggregated by the database.
     *
     * @return Collection<int, int>
     */
    public function initialBudgetAmounts(FinancialProfile $profile): Collection
    {
        $owner = $profile->ownerUser();

        if ($owner === null) {
            return collect();
        }

        $envelopeIds = Envelope::query()
            ->where('user_id', $owner->id)
            ->pluck('id');
        $ledgerIds = Ledger::query()
            ->where('profile_id', $profile->id)
            ->pluck('id');

        if ($envelopeIds->isEmpty() || $ledgerIds->isEmpty()) {
            return collect();
        }

        app(AmountKeyRing::class)->rememberLedgerProfiles($ledgerIds, $profile->id);

        return Transaction::query()
            ->whereIn('ledger_id', $ledgerIds)
            ->whereIn('envelope_id', $envelopeIds)
            ->whereBetween('event_at', [now()->subDays(30), now()])
            ->get(['ledger_id', 'envelope_id', 'event_at', 'amount'])
            ->groupBy('envelope_id')
            ->map(fn (Collection $transactions): int => (int) round(
                (int) $transactions->sum(fn (Transaction $transaction): int => Money::toMinorUnits($transaction->amount)) / 100,
                0,
                PHP_ROUND_HALF_UP,
            ));
    }
}
