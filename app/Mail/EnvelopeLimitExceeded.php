<?php

namespace App\Mail;

use App\Mail\Concerns\RetriesDelivery;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope as EnvelopeModel;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use App\Settings\NotificationSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Number;

/**
 * An "envelope limit exceeded" notification (issue #52): recorded spending
 * for an envelope within a month crossed (went strictly past) its
 * allocation on that month's published budget — per ledger, the number the
 * ledger page reports. One email per crossing, per (published budget,
 * envelope, ledger): a new published revision re-arms the check, dropping
 * back under never does (docs/notifications.md).
 *
 * The mailable receives **IDs only** — no names, and never a decrypted
 * amount — so nothing sensitive rides in the (encrypted) queue payload;
 * everything else is re-resolved at send time: the envelope's allocation
 * and the month's recorded total are read in PHP from the
 * encrypted-at-rest columns (docs/encryption.md), which also tolerates
 * `amounts:rotate-keys` running in between, and the recipient must still
 * be the envelope **owner or a contributor** — holders of less than
 * `viewer` get no amounts at all, so the mandatory masking check never
 * leaks a value after a permission change while the mail waited in the
 * queue. Queued, encrypted in the queue (`ShouldBeEncrypted`), localized
 * per recipient (`users.locale`, fallback `en`), with a "Manage your
 * notifications" footer.
 */
class EnvelopeLimitExceeded extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, RetriesDelivery, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  int  $budgetId  the month's published budget the allocation
     *                         was crossed on; a new revision is a new id
     *                         and re-arms the check
     * @param  int  $envelopeId  the expense envelope that crossed
     * @param  int  $ledgerId  the ledger the crossing was recorded in (the
     *                         check is per ledger — docs/notifications.md)
     * @param  int  $recipientUserId  the recipient — the envelope owner or
     *                                a contributor at dispatch time; both
     *                                are re-checked at send time
     */
    public function __construct(
        public readonly int $budgetId,
        public readonly int $envelopeId,
        public readonly int $ledgerId,
        public readonly int $recipientUserId,
    ) {
        //
    }

    /**
     * Get the message envelope. The envelope's name is sanitized for the
     * subject the same way as every other mailable
     * (ProfileInvite::subjectText), re-resolved from the ID at send time.
     */
    public function envelope(): Envelope
    {
        $envelopeName = ProfileInvite::subjectText($this->envelopeName());

        if ($envelopeName === '') {
            return new Envelope(
                subject: __('mail.envelope_limit_exceeded.subject_generic'),
            );
        }

        return new Envelope(
            subject: __('mail.envelope_limit_exceeded.subject', [
                'envelope' => $envelopeName,
                'month' => $this->monthLabel(),
            ]),
        );
    }

    /**
     * Get the message content definition: a plain-text view, localized
     * through lang/{locale}/mail.php and rendered unescaped ({!! !!} — the
     * text part is not HTML, so `&` must not become `&amp;`). The allocated,
     * spent and over amounts are only read — and only decrypted — when the
     * recipient's envelope permission still resolves to `viewer` or above.
     */
    public function content(): Content
    {
        $totals = $this->totals();

        return new Content(
            text: 'mail.envelope-limit-exceeded',
            with: [
                'envelopeName' => $this->envelopeName(),
                'ledgerName' => $this->ledgerName(),
                'profileName' => $this->profileName(),
                'monthLabel' => $this->monthLabel(),
                'ledgerUrl' => route('ledger'),
                // Null unless every amount could be resolved and the
                // recipient holds viewer+ permission on the envelope — the
                // view then renders no amount line at all, and the
                // decrypted values never leave this class until the view
                // renders them.
                'allocatedLabel' => $totals === null ? null : $this->formatAmount($totals['allocatedMinor']),
                'spentLabel' => $totals === null ? null : $this->formatAmount($totals['spentMinor']),
                'overLabel' => $totals === null ? null : $this->formatAmount($totals['overMinor']),
            ],
        );
    }

    /**
     * Deliver the message — unless the recipient is no longer eligible at
     * send time: the budget or the envelope's allocation on it is gone (a
     * budget edit while the mail was queued — the notification is stale),
     * the recipient no longer holds the envelope **owner or contributor**
     * permission (holders of less than `viewer` get no amounts at all, so
     * a downgrade while queued suppresses the whole mail rather than leak
     * a value), the account is gone or unverified, or the
     * `envelope_limit_exceeded` toggle (master and per-type) was flipped
     * while the mail waited in the queue.
     */
    public function send($mailer)
    {
        $recipient = User::find($this->recipientUserId);

        if ($recipient === null
            || $recipient->email_verified_at === null
            || ! $recipient->wantsNotification(NotificationSettings::ENVELOPE_LIMIT_EXCEEDED)
            || Budget::query()->whereKey($this->budgetId)->whereNotNull('published_at')->doesntExist()
            || Ledger::query()->whereKey($this->ledgerId)->doesntExist()
            || $this->permissionFor($recipient) === null) {
            return null;
        }

        // The recipient and the locale are resolved **here**, at send time
        // (`to()` appends, so any queued hint address is cleared first).
        // `Mailable::send()` renders inside this locale.
        $this->to = [];
        $this->to($recipient->email);
        $this->locale(
            array_key_exists($recipient->locale, config('app.available_locales'))
                ? $recipient->locale
                : 'en',
        );

        return parent::send($mailer);
    }

    /**
     * The recipient's permission on the envelope, re-resolved at send time:
     * `"owner"` or `"contributor"` keeps the mail eligible (both are at
     * least envelope-`viewer`, so the amounts may be shown); anything below
     * — a stale share resolving to `"none"` — suppresses it entirely, so a
     * permission downgrade while queued can never leak amounts. Null when
     * the envelope is gone.
     */
    private function permissionFor(User $recipient): ?string
    {
        $envelope = EnvelopeModel::query()->find($this->envelopeId);

        if ($envelope === null) {
            return null;
        }

        $permission = $envelope->permissionFor($recipient);

        return in_array($permission, [ResourcePermissions::OWNER, ResourcePermissions::CONTRIBUTOR], true)
            ? $permission
            : null;
    }

    /**
     * The month's allocated / spent / over amounts in minor units, read in
     * PHP from the encrypted-at-rest columns (docs/encryption.md) — the
     * same arithmetic the ledger page's "remaining allocated" performs.
     * Null when the allocation is gone (a budget edit removed the envelope
     * while the mail was queued — the notification is stale) or the budget
     * no longer exists.
     *
     * @return array{allocatedMinor: int, spentMinor: int, overMinor: int}|null
     */
    private function totals(): ?array
    {
        $budget = Budget::query()->find($this->budgetId);
        $allocation = BudgetEnvelope::query()
            ->where('budget_id', $this->budgetId)
            ->where('envelope_id', $this->envelopeId)
            ->first();

        if ($budget === null || $allocation === null) {
            return null;
        }

        $allocatedMinor = Money::toMinorUnits($allocation->amount);
        $spentMinor = Transaction::query()
            ->select(['id', 'ledger_id', 'envelope_id', 'event_at', 'amount'])
            ->where('ledger_id', $this->ledgerId)
            ->where('envelope_id', $this->envelopeId)
            ->whereBetween('event_at', [
                CarbonImmutable::create($budget->effective_year, $budget->effective_month, 1)->startOfDay(),
                CarbonImmutable::create($budget->effective_year, $budget->effective_month, 1)->endOfMonth()->endOfDay(),
            ])
            ->get()
            ->sum(fn (Transaction $transaction): int => Money::toMinorUnits($transaction->amount));

        // Positive when over: remaining allocated = allocated - recorded.
        $overMinor = $allocatedMinor - $spentMinor;

        // The mail was queued on a crossing; if the month's spending has
        // since dropped back within the allocation (the undo window's
        // deletions), the notification is stale — the same metadata-only
        // rule a removed allocation gets: no amounts rather than a wrong
        // "amount over".
        if ($overMinor <= 0) {
            return null;
        }

        return [
            'allocatedMinor' => $allocatedMinor,
            'spentMinor' => abs($spentMinor),
            'overMinor' => $overMinor,
        ];
    }

    /**
     * The month, from the budget's effective year/month, localized per the
     * recipient's locale (fallback `en`) — e.g. "June 2026" / "juin 2026".
     * Rendering runs inside `Mailable::send()`'s locale scope
     * (`withLocale`), which the send-time recipient resolution set.
     */
    private function monthLabel(): string
    {
        $budget = Budget::query()->find($this->budgetId);

        if ($budget === null) {
            return '';
        }

        return CarbonImmutable::create($budget->effective_year, $budget->effective_month, 1)
            ->settings(['locale' => app()->getLocale()])
            ->isoFormat('MMMM Y');
    }

    /**
     * The envelope's current name — re-resolved from the ID at send time.
     */
    private function envelopeName(): string
    {
        return (string) EnvelopeModel::query()->whereKey($this->envelopeId)->value('name');
    }

    /**
     * The ledger's current name — Ledgers have no update endpoint today,
     * so the name cannot change while the mail is queued (docs/ledger.md).
     */
    private function ledgerName(): string
    {
        return (string) Ledger::query()->whereKey($this->ledgerId)->value('name');
    }

    /**
     * The ledger's financial profile name, for the body's context line.
     */
    private function profileName(): string
    {
        $profileId = Ledger::query()
            ->whereKey($this->ledgerId)
            ->value('profile_id');

        return $profileId === null
            ? ''
            : (string) FinancialProfile::query()->whereKey($profileId)->value('name');
    }

    /**
     * An amount in minor units, positive and formatted with the budget's
     * currency per the recipient locale (the currency the ledger page
     * reports — docs/ledger.md).
     */
    private function formatAmount(int $minorUnits): string
    {
        $currency = (string) Budget::query()->whereKey($this->budgetId)->value('currency');

        return Number::currency(Money::fromMinorUnits(abs($minorUnits)), $currency ?: 'USD', app()->getLocale())
            ?: number_format(Money::fromMinorUnits(abs($minorUnits)), 2);
    }
}
