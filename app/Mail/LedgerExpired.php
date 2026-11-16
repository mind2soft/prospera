<?php

namespace App\Mail;

use App\Mail\Concerns\RetriesDelivery;
use App\Models\FinancialProfile;
use App\Models\LedgerPermission;
use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A "ledger expired" notification (issue #50): an open ledger whose end
 * date has passed, sent once per ledger when the daily
 * `ledgers:notify-expired` run first detects it. Closing the ledger never
 * suppresses a pending send; re-opening it never re-arms one.
 *
 * Recipients — the ledger owner plus every member of the ledger's
 * financial profile — are resolved by the dispatching command; this
 * mailable receives IDs and names only, and re-gates at send time: the
 * recipient must still be a profile member, hold a verified address, and
 * pass the `ledger_expired` toggle (docs/notifications.md — settings can
 * change while the message waits in the queue). Queued, encrypted in the
 * queue (`ShouldBeEncrypted`), localized per recipient (`users.locale`,
 * fallback `en`), with no amounts — no decryption is needed.
 */
class LedgerExpired extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, RetriesDelivery, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  int  $ledgerId  the expired ledger, for the send-time owner
     *                         resolution (the next-step hint follows
     *                         ownership transfers)
     * @param  int  $profileId  the ledger's financial profile, for the
     *                          send-time membership gate
     * @param  CarbonImmutable  $startDate  the ledger's start date
     * @param  CarbonImmutable  $endDate  the ledger's end date
     * @param  int  $recipientUserId  the recipient, for the send-time gate
     * @param  string  $recipientLocale  the recipient's locale, resolved at
     *                                   dispatch time ($users.locale,
     *                                   fallback `en`)
     */
    public function __construct(
        public readonly int $ledgerId,
        public readonly string $ledgerName,
        public readonly int $profileId,
        public readonly string $profileName,
        public readonly CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        public readonly int $recipientUserId,
        public readonly string $recipientLocale = 'en',
    ) {
        $this->locale($recipientLocale);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $ledger = ProfileInvite::subjectText($this->ledgerName);

        return new Envelope(
            subject: $ledger === ''
                ? __('mail.ledger_expired.subject_generic')
                : __('mail.ledger_expired.subject', ['ledger' => $ledger]),
        );
    }

    /**
     * Get the message content definition: a plain-text view, localized
     * through lang/{locale}/mail.php and rendered unescaped ({!! !!} — the
     * text part is not HTML, so `&` must not become `&amp;`). The next-step
     * hint follows the **current** ledger owner at send time: closing is
     * owner-only, and ownership may transfer while the mail sits in the
     * queue.
     */
    public function content(): Content
    {
        $isOwner = LedgerPermission::query()
            ->where('ledger_id', $this->ledgerId)
            ->where('permission', LedgerPermission::PERMISSION_OWNER)
            ->where('user_id', $this->recipientUserId)
            ->exists();

        return new Content(
            text: 'mail.ledger-expired',
            with: [
                // Note: the mailable's public properties (startDate,
                // endDate, …) are merged into the view data too — Laravel
                // adds them **after** this array, so view variables must
                // not share their names with a differently-formatted value.
                'ledgerName' => $this->ledgerName,
                'profileName' => $this->profileName,
                'startLabel' => $this->formatDate($this->startDate),
                'endLabel' => $this->formatDate($this->endDate),
                'ledgerUrl' => route('ledger'),
                'isOwner' => $isOwner,
            ],
        );
    }

    /**
     * The date localized per the recipient's locale (fallback `en`) —
     * e.g. "January 2, 2026" / "2 janvier 2026". `locale($locale)` is
     * avoided: it is declared `static|string`, which defeats the fluent
     * chain; `settings(['locale' => …])` mutates and returns the instance.
     */
    private function formatDate(CarbonImmutable $date): string
    {
        $locale = in_array($this->recipientLocale, config('app.available_locales'), true)
            ? $this->recipientLocale
            : 'en';

        return $date->settings(['locale' => $locale])->isoFormat('LL');
    }

    /**
     * Deliver the message — unless the recipient is no longer eligible at
     * send time: account gone, address unverified, notifications turned
     * off (master switch or the `ledger_expired` toggle), or the user left
     * the ledger's financial profile since the mail was queued.
     */
    public function send($mailer)
    {
        $recipient = User::find($this->recipientUserId);

        if ($recipient === null
            || $recipient->email_verified_at === null
            || ! $recipient->wantsNotification(NotificationSettings::LEDGER_EXPIRED)
            || ! $this->stillMember($recipient->id)) {
            return null;
        }

        return parent::send($mailer);
    }

    /**
     * Whether the recipient is still a member of the ledger's financial
     * profile (the ledger owner must be one too, per the ledger
     * permissions constraint — docs/ledger.md).
     */
    private function stillMember(int $userId): bool
    {
        return FinancialProfile::query()
            ->whereKey($this->profileId)
            ->whereHas('members', fn ($query) => $query->whereKey($userId))
            ->exists();
    }
}
