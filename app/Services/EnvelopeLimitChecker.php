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
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountKeyRing;
use App\Settings\NotificationSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
 * ledger) is written **before** the mails are queued after commit, so
 * concurrent recordings and job retries can never double-send. The mailables carry
 * IDs only — names, amounts and recipients are re-resolved at send time,
 * tolerating `amounts:rotate-keys` running in between.
 *
 * This is the reusable entry point both recording paths call — the
 * transaction endpoint (one row) and schedule reconciliation (a batch of
 * generated rows per month and envelope, docs/scheduled-transactions.md).
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
     * The row at which an ascending-ID prefix of the group's exact
     * minor-unit amounts last crosses the allocation, provided the final
     * total is strictly over it; null when the group ends within its
     * allocation. Ascending IDs make atomic batches deterministic: three
     * rows of -60 against -100 cross on the second, even when all three
     * were committed together.
     *
     * @param  iterable<int, int>  $amountsById  signed minor units keyed by
     *                                           transaction id, ascending
     */
    public static function crossingCandidate(int $allocatedMinor, iterable $amountsById): ?int
    {
        $total = 0;
        $candidate = null;

        foreach ($amountsById as $id => $amount) {
            if (self::isCrossing($allocatedMinor, $total, $amount)) {
                $candidate = $id;
            }
            $total += $amount;
        }

        return $total < $allocatedMinor ? $candidate : null;
    }

    /**
     * Run the check for a freshly recorded transaction: it notifies only
     * when the crossing happens on this row or a later one, so recording on
     * an already-over envelope stays silent. Never throws for unmet
     * preconditions — they simply mean "nothing to check".
     */
    public function check(Transaction $transaction): void
    {
        $this->checkGroup(
            AmountContext::id($transaction->ledger_id, 'ledger_id'),
            AmountContext::id($transaction->envelope_id, 'envelope_id'),
            $transaction->event_at->format('Y-m'),
            $transaction->id,
        );
    }

    /**
     * Check one ledger × expense envelope × month group whose recorded
     * spending grew from row `$fromTransactionId` on (a manual recording, or
     * a batch of generated rows inserted or increased by schedule
     * reconciliation). It notifies when the final total is strictly over
     * the allocation and the crossing row is at or after that changed row —
     * including a crossing that moved onto a later manual row because an
     * earlier generated amount increased. Rows that were already over
     * before the change never notify.
     *
     * Lock order (docs/financial-locking.md): ledger (shared) → published
     * budget (shared) → envelope (shared); the profile key memo is
     * refreshed beneath the ledger lock. The persistent claim is taken inside this transaction; mails
     * are queued only after the outermost transaction commits.
     *
     * @param  string  $month  "Y-m"
     */
    public function checkGroup(int $ledgerId, int $envelopeId, string $month, int $fromTransactionId): void
    {
        $mails = DB::transaction(function () use ($ledgerId, $envelopeId, $month, $fromTransactionId): array {
            $ledger = Ledger::query()->whereKey($ledgerId)->sharedLock()->first();

            if ($ledger === null) {
                return [];
            }

            $ring = app(AmountKeyRing::class);
            $ring->forget($ledger->profile_id);

            $start = CarbonImmutable::createFromFormat('!Y-m', $month);

            if (! $start instanceof CarbonImmutable) {
                return [];
            }

            // The published budget of the ledger's profile for that month —
            // SQL filters non-encrypted columns only (docs/encryption.md).
            $budget = Budget::query()
                ->where('profile_id', $ledger->profile_id)
                ->where('effective_year', $start->year)
                ->where('effective_month', $start->month)
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->sharedLock()
                ->first();

            if ($budget === null) {
                return []; // no published budget: nothing to check
            }

            $envelope = Envelope::query()->whereKey($envelopeId)->sharedLock()->first();

            // Revenue envelopes never trigger this notification — it is
            // about spending (docs/notifications.md).
            if ($envelope === null || $envelope->type !== Envelope::DIRECTION_EXPENSE) {
                return [];
            }

            $allocation = BudgetEnvelope::query()
                ->where('budget_id', $budget->id)
                ->where('envelope_id', $envelope->id)
                ->first();

            if ($allocation === null) {
                return []; // unallocated envelope on this month's budget
            }

            // Decrypted exact minor units in ascending id order, summed in
            // PHP like the ledger page's reporting (docs/ledger.md).
            $amounts = $ledger->transactions()
                ->select(['id', 'ledger_id', 'envelope_id', 'event_at', 'amount'])
                ->where('envelope_id', $envelope->id)
                ->whereBetween('event_at', [$start, $start->endOfMonth()->endOfDay()])
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (Transaction $row): array => [$row->id => $row->amountMinorUnits()]);

            $candidate = self::crossingCandidate(Money::toMinorUnits($allocation->amount), $amounts);

            if ($candidate === null || $candidate < $fromTransactionId) {
                return []; // within the allocation, or over before this change
            }

            if (! $this->claim($budget, $envelope, $ledger)) {
                return []; // already claimed — never double-send
            }

            return $this->mailsFor($budget, $envelope->load('permissions'), $ledger);
        });

        if ($mails !== []) {
            DB::afterCommit(function () use ($mails): void {
                foreach ($mails as $mail) {
                    Mail::queue($mail);
                }
            });
        }
    }

    /**
     * The dedup claim: one row per (published budget, envelope, ledger),
     * ever — the unique index is the atomic claim. A concurrent crossing or
     * a job retry lands here and stops; a newly published budget revision
     * (a new budget_id) re-arms the check.
     *
     * The claim insert runs in its own nested transaction: the expected
     * unique violation must roll back that savepoint instead of aborting
     * the enclosing transaction (PostgreSQL refuses every further statement
     * after a failed one; SQLite only tolerates it). The catch lives outside
     * the transaction so the violation is the exception that triggers the
     * savepoint rollback.
     */
    private function claim(Budget $budget, Envelope $envelope, Ledger $ledger): bool
    {
        try {
            DB::transaction(function () use ($budget, $envelope, $ledger): void {
                DB::table('envelope_limit_notifications')->insert([
                    'budget_id' => $budget->id,
                    'envelope_id' => $envelope->id,
                    'ledger_id' => $ledger->id,
                    'notified_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            // Only a unique-constraint violation means "already claimed" —
            // every other database error surfaces instead of being masked
            // as a dedup claim (it lands in failed_jobs for a visible,
            // retryable run).
            if (! preg_match('/unique|duplicate/i', $exception->getMessage())) {
                throw $exception;
            }

            return false;
        }

        return true;
    }

    /**
     * One mail per eligible recipient: the envelope **owner**
     * (`envelopes.user_id`) plus every explicit envelope share at
     * contributor level or above, restricted to members of the ledger's
     * financial profile (envelopes are user-owned and can be referenced
     * from several profiles — a profile's ledger activity must not leak
     * outside it), deduplicated. Verified addresses and the notification
     * toggle are pre-filtered here; the mailable re-checks everything at
     * send time, when it actually delivers.
     *
     * @return list<EnvelopeLimitExceeded>
     */
    private function mailsFor(Budget $budget, Envelope $envelope, Ledger $ledger): array
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

        $mails = [];

        foreach ($users as $user) {
            if ($user->email_verified_at === null
                || ! $user->wantsNotification(NotificationSettings::ENVELOPE_LIMIT_EXCEEDED)) {
                continue;
            }

            $mails[] = new EnvelopeLimitExceeded(
                budgetId: $budget->id,
                envelopeId: $envelope->id,
                ledgerId: $ledger->id,
                recipientUserId: $user->id,
            );
        }

        return $mails;
    }
}
