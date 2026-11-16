<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\EnvelopeLimitExceeded;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Settings\NotificationSettings;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The "envelope limit exceeded" notification (issue #52,
 * docs/notifications.md): when a transaction is recorded on an **expense**
 * envelope, the recorded spending for the transaction's month is compared
 * to the envelope's allocation on that month's published budget — per
 * ledger, the number the ledger page reports — and the **crossing** is
 * notified: the envelope going from within its allocation to strictly over
 * it. Already-over envelopes never notify again (until a new published
 * budget revision re-arms the check), so the mail is one actionable signal
 * per month and budget, not noise on every transaction.
 *
 * Both the allocation and the recorded total are **decrypted model values
 * summed in PHP** (docs/encryption.md): no `SUM(amount)`, no `WHERE
 * amount < …` — SQL only ever filters non-encrypted columns. The dedup
 * claim (`envelope_limit_notifications`, unique over budget × envelope ×
 * ledger) is written **before** the mails are queued, so concurrent
 * recordings and job retries can never double-send. The mailables carry
 * IDs only — names, amounts and recipients are re-resolved at send time,
 * tolerating `amounts:rotate-keys` running in between.
 *
 * This is the reusable entry point both recording paths call — the
 * transaction endpoint today, schedule-materialized transactions when
 * #15 lands.
 */
final class EnvelopeLimitChecker
{
    /**
     * Whether recording `$additionalMinor` on top of the month's
     * `recordedBeforeMinor` **crosses** the `allocatedMinor` allocation:
     * the envelope goes from within its allocation to strictly over it.
     * Already-over envelopes do not cross again — later over-limit
     * transactions never notify (the crossing is the actionable moment).
     *
     * @param  int  $allocatedMinor  the allocation on the month's published
     *                               budget, signed (expenses negative)
     * @param  int  $recordedBeforeMinor  the month's recorded total for the
     *                                    envelope, **excluding** the new
     *                                    transaction, signed
     * @param  int  $additionalMinor  the new transaction's amount, signed
     */
    public static function isCrossing(int $allocatedMinor, int $recordedBeforeMinor, int $additionalMinor): bool
    {
        return $recordedBeforeMinor >= $allocatedMinor
            && ($recordedBeforeMinor + $additionalMinor) < $allocatedMinor;
    }

    /**
     * Run the check for a freshly recorded transaction. Never throws for
     * unmet preconditions — they simply mean "nothing to check": a revenue
     * envelope, a month without a published budget, an unallocated
     * envelope, or an envelope that does not cross its allocation.
     */
    public function check(Transaction $transaction): void
    {
        $envelope = Envelope::query()->with('permissions')->find($transaction->envelope_id);

        // Revenue envelopes never trigger this notification — it is about
        // spending (docs/notifications.md).
        if ($envelope === null || $envelope->type !== Envelope::DIRECTION_EXPENSE) {
            return;
        }

        $ledger = Ledger::query()->find($transaction->ledger_id);

        if ($ledger === null) {
            return;
        }

        // The month containing the transaction's event date.
        $month = $transaction->event_at->startOfMonth();

        // The published budget of the ledger's profile for that month —
        // SQL filters non-encrypted columns only (docs/encryption.md).
        $budget = Budget::query()
            ->where('profile_id', $ledger->profile_id)
            ->where('effective_year', $month->year)
            ->where('effective_month', $month->month)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->first();

        if ($budget === null) {
            return; // no published budget: nothing to check
        }

        $allocation = BudgetEnvelope::query()
            ->where('budget_id', $budget->id)
            ->where('envelope_id', $envelope->id)
            ->first();

        if ($allocation === null) {
            return; // unallocated envelope on this month's budget
        }

        // The month's recorded total for the envelope **before** this
        // transaction — decrypted amounts summed in PHP, exactly like the
        // ledger page's reporting (docs/ledger.md). The query excludes the
        // new transaction itself.
        $recordedBeforeMinor = $ledger->transactions()
            ->select(['id', 'ledger_id', 'envelope_id', 'event_at', 'amount'])
            ->where('envelope_id', $envelope->id)
            ->whereBetween('event_at', [$month, $month->copy()->endOfMonth()->endOfDay()])
            ->whereKeyNot($transaction->getKey())
            ->get()
            ->sum(fn (Transaction $row): int => Money::toMinorUnits($row->amount));

        if (! self::isCrossing(
            Money::toMinorUnits($allocation->amount),
            $recordedBeforeMinor,
            Money::toMinorUnits($transaction->amount),
        )) {
            return; // within the allocation, or already over it
        }

        // The dedup claim: one row per (published budget, envelope, ledger),
        // ever — the unique index is the atomic claim. A concurrent crossing
        // or a job retry lands here and stops; a newly published budget
        // revision (a new budget_id) re-arms the check.
        try {
            DB::table('envelope_limit_notifications')->insert([
                'budget_id' => $budget->id,
                'envelope_id' => $envelope->id,
                'ledger_id' => $ledger->id,
                'notified_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Only a unique-constraint violation means "already claimed" —
            // every other database error surfaces instead of being masked
            // as a dedup claim (it lands in failed_jobs for a visible,
            // retryable run).
            if (! preg_match('/unique|duplicate/i', $exception->getMessage())) {
                throw $exception;
            }

            return; // already claimed — never double-send
        }

        $this->dispatchMails($budget, $envelope, $ledger, $transaction);
    }

    /**
     * Queue one mail per eligible recipient: the envelope **owner**
     * (`envelopes.user_id`) plus every explicit envelope share at
     * contributor level or above, restricted to members of the ledger's
     * financial profile (envelopes are user-owned and can be referenced
     * from several profiles — a profile's ledger activity must not leak
     * outside it), deduplicated. Verified addresses and the notification
     * toggle are pre-filtered here; the mailable re-checks everything at
     * send time, when it actually delivers.
     */
    private function dispatchMails(Budget $budget, Envelope $envelope, Ledger $ledger, Transaction $transaction): void
    {
        $recipients = collect([$envelope->user_id])
            ->merge(
                $envelope->permissions
                    ->whereIn('permission', [EnvelopePermission::PERMISSION_OWNER, EnvelopePermission::PERMISSION_CONTRIBUTOR])
                    ->pluck('user_id'),
            )
            ->filter(fn (mixed $userId): bool => $userId !== null)
            ->map(fn (mixed $userId): int => (int) $userId)
            ->unique()
            ->values();

        $users = User::query()
            ->whereIntegerInRaw('id', $recipients->all())
            ->get()
            ->filter(fn (User $user): bool => FinancialProfile::query()
                ->whereKey($ledger->profile_id)
                ->whereHas('members', fn ($query) => $query->whereKey($user->id))
                ->exists());

        foreach ($users as $user) {
            if ($user->email_verified_at === null
                || ! $user->wantsNotification(NotificationSettings::ENVELOPE_LIMIT_EXCEEDED)) {
                continue;
            }

            Mail::queue(new EnvelopeLimitExceeded(
                budgetId: $budget->id,
                envelopeId: $envelope->id,
                ledgerId: $ledger->id,
                recipientUserId: $user->id,
            ));
        }
    }
}
