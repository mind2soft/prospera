<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RetriesDelivery;
use App\Models\Budget;
use App\Models\Envelope as EnvelopeModel;
use App\Models\EnvelopeSchedule;
use App\Models\User;
use App\Services\ResourcePermissions;
use App\Services\Schedules\ScheduleOccurrenceResolver;
use App\Services\Schedules\ScheduleReminderRecipients;
use App\Services\Schedules\ScheduleRule;
use App\Settings\NotificationSettings;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * A payment reminder (issue #15, docs/scheduled-transactions.md): a
 * scheduled transaction of an envelope comes due on the occurrence date.
 * Queued once per (schedule, occurrence, recipient) by `schedules:remind`.
 *
 * The mailable receives **IDs only** (and the occurrence date) — never a
 * name, an address or a decrypted amount. Everything is re-resolved at send
 * time: the schedule must still be active, still remind and still occur on
 * that date (an edit moving the occurrence suppresses the stale reminder);
 * the recipient must still be the envelope owner or a member of a profile
 * referencing the envelope, verified, and pass the `payment_reminders`
 * toggle (master and per-type). The amount is decrypted in PHP from the
 * schedule model only when the recipient holds at least `viewer` permission
 * on the envelope; below that, the mail carries the envelope name and date
 * only. Queued, encrypted in the queue, localized per recipient
 * (`users.locale`, fallback `en`), with a "Manage your notifications" footer.
 */
class PaymentReminder extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, RetriesDelivery, SerializesModels;

    /**
     * The schedule (trashed included), its envelope and the recipient,
     * loaded once per send.
     *
     * @var array{EnvelopeSchedule|null, EnvelopeModel|null, User|null}|null
     */
    private ?array $resolved = null;

    /**
     * @param  string  $occurrence  the occurrence date, YYYY-MM-DD
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly string $occurrence,
        public readonly int $recipientUserId,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        $envelopeName = ProfileInvite::subjectText($this->envelopeName());

        return new Envelope(
            subject: $envelopeName === ''
                ? __('mail.payment_reminder.subject_generic', ['date' => $this->dateLabel()])
                : __('mail.payment_reminder.subject', ['envelope' => $envelopeName, 'date' => $this->dateLabel()]),
        );
    }

    /**
     * A plain-text view rendered unescaped ({!! !!} — the text part is not
     * HTML, so `&` must not become `&amp;`).
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.payment-reminder',
            with: [
                'envelopeName' => $this->envelopeName(),
                'dateLabel' => $this->dateLabel(),
                // Null when masked: the view renders no amount line at all.
                'amountLabel' => $this->amountLabel(),
                'ledgerUrl' => route('ledger'),
            ],
        );
    }

    /**
     * Deliver the message — unless the reminder is stale or the recipient
     * no longer eligible at send time.
     */
    public function send($mailer)
    {
        // Fresh state on every attempt (retries included).
        $this->resolved = null;
        [$schedule, $envelope, $recipient] = $this->resolved();

        if ($schedule === null
            || $schedule->trashed()
            || $envelope === null
            || $recipient === null
            || $schedule->reminder_days === null
            || $this->occurrence < now()->toDateString()
            || ! $this->stillOccurs($schedule)
            || $recipient->email_verified_at === null
            || ! $recipient->wantsNotification(NotificationSettings::PAYMENT_REMINDERS)
            || ! app(ScheduleReminderRecipients::class)->includes($envelope, $recipient->id)) {
            return null;
        }

        // Resolved here, at send time (`to()` appends, so any queued hint
        // address is cleared first). `Mailable::send()` renders inside
        // this locale.
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

    /** @return array{EnvelopeSchedule|null, EnvelopeModel|null, User|null} */
    private function resolved(): array
    {
        if ($this->resolved === null) {
            $schedule = EnvelopeSchedule::withTrashed()->find($this->scheduleId);
            $this->resolved = [
                $schedule,
                $schedule === null ? null : EnvelopeModel::query()->find($schedule->envelope_id),
                User::query()->find($this->recipientUserId),
            ];
        }

        return $this->resolved;
    }

    private function stillOccurs(EnvelopeSchedule $schedule): bool
    {
        return app(ScheduleOccurrenceResolver::class)
            ->resolve($schedule->rule(), $this->occurrence, $this->occurrence) !== [];
    }

    private function envelopeName(): string
    {
        return (string) $this->resolved()[1]?->name;
    }

    /**
     * The occurrence date localized per the recipient's locale — e.g.
     * "June 15, 2026" / "15 juin 2026".
     */
    private function dateLabel(): string
    {
        return ScheduleRule::parseDate($this->occurrence)
            ->settings(['locale' => app()->getLocale()])
            ->isoFormat('LL');
    }

    /**
     * The amount, positive (sign convention — docs/budget.md), decrypted
     * from the schedule model only for recipients holding at least `viewer`
     * permission on the envelope. Formatted with the currency of the
     * latest published budget assigning the envelope in one of the
     * recipient's profiles (the occurrence month's budget first), else of
     * any profile referencing it, else USD.
     */
    private function amountLabel(): ?string
    {
        [$schedule, $envelope, $recipient] = $this->resolved();

        if ($schedule === null || $envelope === null || $recipient === null
            || $envelope->permissionFor($recipient) === ResourcePermissions::NONE) {
            return null;
        }

        $amount = Money::fromMinorUnits(abs($schedule->amount));
        $currency = $this->currency($envelope->id, $recipient->id);

        return Number::currency($amount, $currency, app()->getLocale()) ?: number_format($amount, 2);
    }

    private function currency(int $envelopeId, int $recipientId): string
    {
        $date = ScheduleRule::parseDate($this->occurrence);
        $budgets = Budget::publishedAssigning($envelopeId)
            ->orderByRaw(
                'CASE WHEN effective_year = ? AND effective_month = ? THEN 0 ELSE 1 END',
                [$date->year, $date->month],
            )
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $currency = (clone $budgets)
            ->whereIn('profile_id', DB::table('financial_profile_user')->where('user_id', $recipientId)->select('profile_id'))
            ->value('currency') ?? $budgets->value('currency');

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }
}
