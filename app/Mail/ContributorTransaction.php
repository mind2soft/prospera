<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RetriesDelivery;
use App\Models\Budget;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use App\Settings\NotificationSettings;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Number;

/**
 * A "contributor transactions" notification (issue #51): another user
 * recorded a transaction in a ledger one owns — one email per transaction.
 * No email for the owner's own recordings, for transactions deleted before
 * send, nor when the **current** ledger owner is the actor — ownership may
 * transfer while the mail waits in the queue, and the transfer is honoured
 * at send time. When #15 lands, schedule-materialized transactions must not
 * dispatch this mail (nothing was recorded by "another user"); the guard
 * belongs to the dispatching path.
 *
 * The mailable receives **IDs only** — no names, and never a decrypted
 * amount — so nothing sensitive rides in the (encrypted) queue payload.
 * Everything else is re-resolved at send time: the recipient (the
 * **current** owner of the ledger, from `ledger_permissions`), the actor's
 * name, the ledger/profile/envelope names, and the recipient's locale
 * (`users.locale`, fallback `en`). The amount is read in PHP from the
 * encrypted-at-rest `Transaction::amount` (docs/encryption.md) and
 * **omitted entirely** when the recipient holds less than `viewer`
 * permission on the envelope (envelope permissions are independent of
 * ledger permissions — docs/envelope.md); no placeholder hints at the
 * masked value. Queued, encrypted in the queue (`ShouldBeEncrypted`),
 * localized per recipient, with a "Manage your notifications" footer.
 */
class ContributorTransaction extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, RetriesDelivery, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  int  $transactionId  the recorded transaction, re-resolved at
     *                              send time (a deleted transaction sends
     *                              nothing — docs/notifications.md)
     * @param  int  $ledgerId  the ledger the transaction was recorded in;
     *                         its **current** owner is the recipient
     */
    public function __construct(
        public readonly int $transactionId,
        public readonly int $ledgerId,
    ) {
        //
    }

    /**
     * Get the message envelope. Names are sanitized for the subject the
     * same way as every other mailable (ProfileInvite::subjectText) — they
     * are re-resolved here from the IDs, so the subject reflects the state
     * at send time.
     */
    public function envelope(): Envelope
    {
        $transaction = $this->transaction();
        $ledgerName = ProfileInvite::subjectText($this->ledgerName());

        if ($transaction === null || $ledgerName === '') {
            return new Envelope(
                subject: __('mail.contributor_transaction.subject_generic'),
            );
        }

        $actorName = ProfileInvite::subjectText($this->actorName($transaction));

        if ($actorName === '') {
            return new Envelope(
                subject: __('mail.contributor_transaction.subject_generic_named', [
                    'ledger' => $ledgerName,
                ]),
            );
        }

        return new Envelope(
            subject: __('mail.contributor_transaction.subject', [
                'actor' => $actorName,
                'ledger' => $ledgerName,
            ]),
        );
    }

    /**
     * Get the message content definition: a plain-text view, localized
     * through lang/{locale}/mail.php and rendered unescaped ({!! !!} — the
     * text part is not HTML, so `&` must not become `&amp;`). The amount is
     * only read — and only decrypted — when the recipient holds envelope-
     * `viewer`+ permission; below `viewer` the view receives no amount at
     * all (metadata only, the ledger API's masking rule).
     */
    public function content(): Content
    {
        $transaction = $this->transaction();

        $owner = $this->recipient();
        $envelope = $transaction?->envelope;
        $masked = $transaction === null
            || $envelope === null
            || $owner === null
            || $envelope->permissionFor($owner) === ResourcePermissions::NONE;
        $actorName = $transaction === null ? '' : $this->actorName($transaction);

        return new Content(
            text: 'mail.contributor-transaction',
            with: [
                // An actor whose account was deleted while the mail waited
                // in the queue (transactions.user_id is null-on-delete)
                // renders with a localized generic actor — the recording
                // remains a fact.
                'actorName' => $actorName === ''
                    ? __('mail.contributor_transaction.actor_generic')
                    : $actorName,
                'ledgerName' => $this->ledgerName(),
                'profileName' => $this->profileName(),
                'envelopeName' => $envelope === null ? '' : (string) $envelope->name,
                'direction' => $envelope === null ? '' : (string) $envelope->type,
                'eventDateLabel' => $transaction === null ? '' : $this->formatDate($transaction->event_at),
                'ledgerUrl' => route('ledger'),
                // Null when masked: the view renders no amount line at all,
                // and the decrypted value never leaves this class until the
                // view renders it.
                'amountLabel' => $masked ? null : $this->formatAmount($transaction),
            ],
        );
    }

    /**
     * Deliver the message — unless the recipient is no longer eligible at
     * send time: the transaction is gone (deleted — its rows are
     * soft-deleted, so the default query skips trashed rows), the ledger
     * owner is gone, the owner **is** the actor, or the owner does not
     * hold a verified address or pass the `contributor_transactions`
     * toggle (master and per-type — settings can change while the mail
     * waits in the queue).
     */
    public function send($mailer)
    {
        $transaction = $this->transaction();

        if ($transaction === null) {
            return null;
        }

        $recipient = $this->recipient();

        if ($recipient === null
            || $recipient->id === $transaction->user_id
            || $recipient->email_verified_at === null
            || ! $recipient->wantsNotification(NotificationSettings::CONTRIBUTOR_TRANSACTIONS)) {
            return null;
        }

        // The recipient and the locale are resolved **here**, at send time:
        // a queued mail follows the current ledger owner, not the one it
        // was queued for (`to()` appends, so any queued hint address is
        // cleared first). `Mailable::send()` renders inside this locale.
        $locale = $recipient->locale;

        $this->to = [];
        $this->to($recipient->email);
        $this->locale(
            $locale !== null && array_key_exists($locale, config('app.available_locales'))
                ? $locale
                : 'en',
        );

        return parent::send($mailer);
    }

    /**
     * The recorded transaction, or null when it no longer exists (deleted
     * before send — its row is purged after the undo window). The envelope
     * relation is loaded for the masking check; the transaction's amount
     * column is only decrypted once the masking check passed
     * (docs/encryption.md).
     */
    private function transaction(): ?Transaction
    {
        return Transaction::query()
            ->with('envelope:id,user_id,name,type')
            ->find($this->transactionId);
    }

    /**
     * The ledger's current owner — resolved at send time from the `owner`
     * row in `ledger_permissions`, so ownership transfers while the mail is
     * queued are honoured. Null when the owner row or the user is gone.
     */
    private function recipient(): ?User
    {
        $ownerId = LedgerPermission::query()
            ->where('ledger_id', $this->ledgerId)
            ->where('permission', LedgerPermission::PERMISSION_OWNER)
            ->value('user_id');

        return $ownerId === null ? null : User::query()->find((int) $ownerId);
    }

    /**
     * The ledger's current name — Ledgers have no update endpoint today,
     * so the name cannot change while the mail is queued (docs/ledger.md).
     */
    private function ledgerName(): string
    {
        return (string) Ledger::query()
            ->whereKey($this->ledgerId)
            ->value('name');
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
     * The actor's display name — re-resolved from the transaction's
     * `user_id` at send time, so a rename while queued is reflected.
     */
    private function actorName(Transaction $transaction): string
    {
        return (string) (User::query()->whereKey($transaction->user_id)->value('name') ?? '');
    }

    /**
     * The date localized per the recipient's locale (fallback `en`) —
     * e.g. "January 2, 2026" / "2 janvier 2026". Rendering runs inside
     * `Mailable::send()`'s locale scope (`withLocale`), which the send-time
     * recipient resolution set to the recipient's locale.
     */
    private function formatDate(CarbonInterface $date): string
    {
        return $date->settings(['locale' => app()->getLocale()])->isoFormat('LL');
    }

    /**
     * The amount, positive (sign convention — docs/budget.md), formatted
     * with the currency of the published budget effective for the
     * transaction's month — the number the ledger page reports
     * (docs/ledger.md). A profile with no published budget for that month
     * falls back to its latest published budget, then to USD.
     */
    private function formatAmount(Transaction $transaction): string
    {
        $profileId = Ledger::query()->whereKey($this->ledgerId)->value('profile_id');

        $currency = $profileId === null
            ? null
            : Budget::query()
                ->where('profile_id', $profileId)
                ->whereNotNull('published_at')
                ->orderByRaw(
                    // The budget effective for the transaction's month wins;
                    // ties fall to the latest published budget (the ledger
                    // page reports the latest published budget's currency).
                    'CASE WHEN effective_year = ? AND effective_month = ? THEN 0 ELSE 1 END',
                    [$transaction->event_at->year, $transaction->event_at->month],
                )
                ->orderByDesc('published_at')
                ->limit(1)
                ->value('currency');

        return Number::currency(
            abs((float) $transaction->amount),
            is_string($currency) && $currency !== '' ? $currency : 'USD',
            app()->getLocale(),
        ) ?: number_format(abs((float) $transaction->amount), 2);
    }
}
