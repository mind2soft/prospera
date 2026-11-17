<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PaymentReminder;
use App\Models\Budget;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\EnvelopeSchedule;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Payment reminders (issue #15, docs/scheduled-transactions.md): the daily
 * `schedules:remind` run queues one email per (schedule, occurrence,
 * recipient) once `today ≥ occurrence − reminder_days`, and the mailable
 * re-checks the schedule, the recipient and the gate at send time.
 */
final class PaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private FinancialProfile $profile;

    private Envelope $envelope;

    /** The real mailer, resolved before Mail::fake() swaps the facade. */
    private Mailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = $this->app->make('mailer');
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 6, 10)->setTime(9, 30));

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->member = User::factory()->create(['name' => 'Member']);
        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);
        $this->envelope = $this->owner->envelopes()->create(['name' => 'Rent & food', 'type' => 'expense']);
        $this->publish($this->profile, $this->envelope);
    }

    private function publish(FinancialProfile $profile, Envelope $envelope, string $currency = 'CAD'): Budget
    {
        $budget = Budget::create([
            'profile_id' => $profile->id, 'currency' => $currency,
            'effective_year' => 2026, 'effective_month' => 6, 'published_at' => now(),
        ]);
        $budget->envelopes()->create(['envelope_id' => $envelope->id, 'type' => 'fixed', 'amount' => -1000]);

        return $budget;
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(array $attributes = []): EnvelopeSchedule
    {
        return $this->envelope->schedules()->create($attributes + [
            'amount' => -12345, 'period' => 'monthly', 'start_date' => '2026-01-01',
            'day_of_month' => 15, 'reminder_days' => 3,
        ]);
    }

    private function remindOn(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 09:30:00'));
        $this->artisan('schedules:remind')->assertSuccessful();
    }

    /** @return list<array{int, string, int}> queued (schedule, occurrence, recipient) triples */
    private function queued(): array
    {
        return Mail::queued(PaymentReminder::class)
            ->map(fn (PaymentReminder $mail): array => [$mail->scheduleId, $mail->occurrence, $mail->recipientUserId])
            ->values()
            ->all();
    }

    /** Deliver the way the queue worker would; null when suppressed at send time. */
    private function deliver(PaymentReminder $mail): ?string
    {
        $transport = $this->mailer->getSymfonyTransport();
        $before = count($transport->messages());
        $mail->send($this->mailer);
        $messages = $transport->messages();

        return count($messages) === $before ? null : quoted_printable_decode($messages->last()->toString());
    }

    private function lastEmail(): Email
    {
        $email = $this->mailer->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function settings(User $user, string $key, bool $value): void
    {
        $user->notification_settings = $user->notification_settings->with([$key => $value]);
        $user->save();
    }

    public function test_reminders_are_queued_n_days_before_and_not_the_day_before_that(): void
    {
        $schedule = $this->schedule(['reminder_days' => 3]);

        $this->remindOn('2026-06-11');
        $this->assertSame([], $this->queued());

        $this->remindOn('2026-06-12');
        $this->assertSame([
            [$schedule->id, '2026-06-15', $this->owner->id],
            [$schedule->id, '2026-06-15', $this->member->id],
        ], $this->queued());
    }

    public function test_same_day_reminders_are_queued_on_the_occurrence_date_only(): void
    {
        $schedule = $this->schedule(['reminder_days' => 0]);

        $this->remindOn('2026-06-14');
        $this->assertSame([], $this->queued());

        $this->remindOn('2026-06-15');
        $this->assertSame([
            [$schedule->id, '2026-06-15', $this->owner->id],
            [$schedule->id, '2026-06-15', $this->member->id],
        ], $this->queued());
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function dayLeads(): iterable
    {
        // [lead days, day before the window opens, window-open day]
        yield '1 day' => [1, '2026-06-13', '2026-06-14'];
        yield '2 days' => [2, '2026-06-12', '2026-06-13'];
        yield '3 days' => [3, '2026-06-11', '2026-06-12'];
        yield '1 week' => [7, '2026-06-07', '2026-06-08'];
        yield '2 weeks' => [14, '2026-05-31', '2026-06-01'];
        yield '30 days' => [30, '2026-05-15', '2026-05-16'];
    }

    #[DataProvider('dayLeads')]
    public function test_every_day_lead_time_queues_once_when_its_window_opens(
        int $days,
        string $before,
        string $on,
    ): void {
        // A start date without earlier occurrences keeps the runs before the
        // window opens free of catch-up reminders.
        $schedule = $this->schedule(['start_date' => '2026-06-15', 'reminder_days' => $days]);

        $this->remindOn($before);
        $this->assertSame([], $this->queued());

        $this->remindOn($on);
        $this->assertSame([
            [$schedule->id, '2026-06-15', $this->owner->id],
            [$schedule->id, '2026-06-15', $this->member->id],
        ], $this->queued());
    }

    public function test_a_month_lead_queues_one_calendar_month_before(): void
    {
        $schedule = $this->schedule([
            'start_date' => '2026-06-15', 'reminder_days' => 1, 'reminder_unit' => 'month',
        ]);

        $this->remindOn('2026-05-14');
        $this->assertSame([], $this->queued());

        $this->remindOn('2026-05-15');
        $this->assertSame([
            [$schedule->id, '2026-06-15', $this->owner->id],
            [$schedule->id, '2026-06-15', $this->member->id],
        ], $this->queued());

        // Later runs never re-queue the same occurrence.
        $this->remindOn('2026-06-14');
        $this->assertCount(2, $this->queued());
    }

    public function test_a_month_lead_clamps_to_the_end_of_shorter_months(): void
    {
        // The day-31 monthly rule clamps too: February's occurrence is
        // February 28. March 31's reminder window opens on February 28 —
        // the last day of the shorter month — never on March 3 (overflow).
        $schedule = $this->schedule([
            'day_of_month' => 31, 'reminder_days' => 1, 'reminder_unit' => 'month',
        ]);

        $this->remindOn('2026-02-27');
        $this->assertSame([
            [$schedule->id, '2026-02-28', $this->owner->id],
            [$schedule->id, '2026-02-28', $this->member->id],
        ], $this->queued());

        $this->remindOn('2026-02-28');
        $this->assertSame([
            [$schedule->id, '2026-02-28', $this->owner->id],
            [$schedule->id, '2026-02-28', $this->member->id],
            [$schedule->id, '2026-03-31', $this->owner->id],
            [$schedule->id, '2026-03-31', $this->member->id],
        ], $this->queued());
    }

    public function test_a_month_lead_is_not_a_fixed_number_of_days(): void
    {
        // June 31 does not exist: June's occurrence is June 30, and July 31's
        // window opens on June 30 (one calendar month before), not July 1
        // (30 days before).
        $schedule = $this->schedule([
            'day_of_month' => 31, 'reminder_days' => 1, 'reminder_unit' => 'month',
        ]);

        $this->remindOn('2026-06-29');
        $this->assertSame([
            [$schedule->id, '2026-06-30', $this->owner->id],
            [$schedule->id, '2026-06-30', $this->member->id],
        ], $this->queued());

        $this->remindOn('2026-06-30');
        $this->assertSame([
            [$schedule->id, '2026-06-30', $this->owner->id],
            [$schedule->id, '2026-06-30', $this->member->id],
            [$schedule->id, '2026-07-31', $this->owner->id],
            [$schedule->id, '2026-07-31', $this->member->id],
        ], $this->queued());
    }

    public function test_exactly_one_reminder_per_occurrence_and_recipient_across_re_runs(): void
    {
        $schedule = $this->schedule(['reminder_days' => 3]);

        foreach (['2026-06-12', '2026-06-12', '2026-06-13', '2026-06-15'] as $day) {
            $this->remindOn($day);
        }

        $this->assertCount(2, $this->queued());
        $this->assertSame(2, DB::table('schedule_reminders')->where('schedule_id', $schedule->id)->count());

        // The next occurrence is reminded in its own window.
        $this->remindOn('2026-07-12');
        $this->assertCount(4, $this->queued());
        $this->assertSame(['2026-06-15', '2026-06-15', '2026-07-15', '2026-07-15'], array_column($this->queued(), 1));
    }

    public function test_downtime_catches_up_due_reminders_and_skips_past_occurrences(): void
    {
        $this->schedule(['reminder_days' => 3]);

        // Missed June 12 and 13: still before the occurrence → still sent.
        $this->remindOn('2026-06-14');
        $this->assertCount(2, $this->queued());

        // A second schedule whose whole reminder window passed during
        // downtime is skipped, not sent late.
        $late = $this->schedule(['day_of_month' => 16, 'reminder_days' => 5]);
        $this->remindOn('2026-06-17');
        $this->assertSame([], array_filter($this->queued(), fn (array $row): bool => $row[0] === $late->id));
        $this->assertSame(0, DB::table('schedule_reminders')->where('schedule_id', $late->id)->count());
    }

    public function test_an_edit_moving_the_occurrence_re_arms_the_new_date_only(): void
    {
        $schedule = $this->schedule(['reminder_days' => 3]);
        $this->remindOn('2026-06-12');
        $stale = Mail::queued(PaymentReminder::class)->first();
        $this->assertInstanceOf(PaymentReminder::class, $stale);

        $schedule->update(['day_of_month' => 16]);
        $this->remindOn('2026-06-13');

        $this->assertSame([
            [$schedule->id, '2026-06-15', $this->owner->id],
            [$schedule->id, '2026-06-15', $this->member->id],
            [$schedule->id, '2026-06-16', $this->owner->id],
            [$schedule->id, '2026-06-16', $this->member->id],
        ], $this->queued());

        // The reminder queued for the old date no longer matches the
        // schedule: suppressed at send time.
        $this->assertNull($this->deliver($stale));
    }

    public function test_schedules_without_a_reminder_or_soft_deleted_get_none(): void
    {
        $this->schedule(['reminder_days' => null]);
        $this->schedule(['day_of_month' => 14])->delete();

        $this->remindOn('2026-06-12');

        $this->assertSame([], $this->queued());
    }

    public function test_a_schedule_deleted_or_reminder_removed_after_queueing_delivers_nothing(): void
    {
        $deleted = $this->schedule();
        $unreminded = $this->schedule(['day_of_month' => 14]);
        $this->remindOn('2026-06-12');

        $deleted->delete();
        $unreminded->update(['reminder_days' => null]);

        $this->assertCount(4, $this->queued());
        foreach (Mail::queued(PaymentReminder::class) as $mail) {
            $this->assertNull($this->deliver($mail));
        }
    }

    public function test_master_or_payment_reminders_flipped_off_after_queueing_delivers_nothing(): void
    {
        $this->schedule();
        $this->remindOn('2026-06-12');
        [$toOwner, $toMember] = Mail::queued(PaymentReminder::class)->values()->all();

        $this->settings($this->owner, NotificationSettings::MASTER, false);
        $this->settings($this->member, NotificationSettings::PAYMENT_REMINDERS, false);

        $this->assertNull($this->deliver($toOwner));
        $this->assertNull($this->deliver($toMember));
    }

    public function test_users_with_reminders_off_are_not_claimed_and_are_reminded_once_back_on(): void
    {
        $this->schedule();
        $this->settings($this->member, NotificationSettings::PAYMENT_REMINDERS, false);

        $this->remindOn('2026-06-12');
        $this->assertSame([$this->owner->id], array_column($this->queued(), 2));

        $this->settings($this->member, NotificationSettings::PAYMENT_REMINDERS, true);
        $this->remindOn('2026-06-13');
        $this->assertSame([$this->owner->id, $this->member->id], array_column($this->queued(), 2));
    }

    public function test_amounts_are_masked_below_viewer_and_shown_from_viewer_up(): void
    {
        $this->schedule();
        $this->remindOn('2026-06-12');
        [$toOwner, $toMember] = Mail::queued(PaymentReminder::class)->values()->all();

        $owner = $this->deliver($toOwner);
        $this->assertNotNull($owner);
        $this->assertStringContainsString('Amount: CA$123.45', $owner);

        // A profile member without any envelope share: name and date only.
        $member = $this->deliver($toMember);
        $this->assertNotNull($member);
        $this->assertStringContainsString('"Rent & food" is due on June 15, 2026.', $member);
        $this->assertStringNotContainsString('Amount', $member);
        $this->assertStringNotContainsString('123', $member);

        $this->envelope->permissions()->create([
            'user_id' => $this->member->id, 'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);
        $this->assertStringContainsString('Amount: CA$123.45', (string) $this->deliver($toMember));
    }

    public function test_unverified_users_and_ex_members_are_skipped(): void
    {
        $unverified = User::factory()->unverified()->create();
        $this->profile->members()->attach($unverified->id, ['is_owner' => false]);
        $this->schedule();

        $this->remindOn('2026-06-12');
        $this->assertSame([$this->owner->id, $this->member->id], array_column($this->queued(), 2));

        // Left the profile after queueing: re-resolved at send time.
        $toMember = Mail::queued(PaymentReminder::class)->last();
        $this->assertInstanceOf(PaymentReminder::class, $toMember);
        $this->profile->members()->detach($this->member->id);
        $this->assertNull($this->deliver($toMember));

        // And is no longer a recipient of later reminders.
        $this->remindOn('2026-07-12');
        $this->assertSame([$this->owner->id], array_column(array_slice($this->queued(), 2), 2));
    }

    public function test_recipients_are_deduplicated_across_referencing_profiles(): void
    {
        $other = FinancialProfile::createOwnedBy($this->owner, 'Business');
        $other->members()->attach($this->member->id, ['is_owner' => false]);
        $this->publish($other, $this->envelope);

        // A profile only holding a draft budget does not reference it.
        $outsider = User::factory()->create();
        $drafts = FinancialProfile::createOwnedBy($outsider, 'Drafts');
        Budget::create([
            'profile_id' => $drafts->id, 'currency' => 'CAD', 'effective_year' => 0, 'effective_month' => 0,
        ])->envelopes()->create(['envelope_id' => $this->envelope->id, 'type' => 'fixed', 'amount' => -1000]);

        $this->schedule();
        $this->remindOn('2026-06-12');

        $this->assertSame([$this->owner->id, $this->member->id], array_column($this->queued(), 2));
    }

    public function test_the_reminder_is_rendered_in_english_without_html_escaping(): void
    {
        $this->schedule();
        $this->remindOn('2026-06-12');
        $toOwner = Mail::queued(PaymentReminder::class)->first();
        $this->assertInstanceOf(PaymentReminder::class, $toOwner);

        $body = $this->deliver($toOwner);
        $message = $this->lastEmail();

        $this->assertNotNull($body);
        $this->assertSame('[Prospera] Reminder: "Rent & food" is scheduled for June 15, 2026', $message->getSubject());
        $this->assertStringContainsString('Reminder: a scheduled transaction on the envelope "Rent & food" is due on June 15, 2026.', $body);
        $this->assertStringContainsString('Manage your notifications:', $body);
        $this->assertStringContainsString(route('notifications.edit'), $body);
        $this->assertSame($this->owner->email, $message->getTo()[0]->getAddress());
        $this->assertStringNotContainsString('&amp;', $body);
    }

    public function test_the_reminder_is_rendered_in_the_recipient_locale(): void
    {
        $this->owner->forceFill(['locale' => 'fr'])->save();
        $this->schedule();
        $this->remindOn('2026-06-12');
        $toOwner = Mail::queued(PaymentReminder::class)->first();
        $this->assertInstanceOf(PaymentReminder::class, $toOwner);

        $body = $this->deliver($toOwner);
        $subject = $this->lastEmail()->getSubject();

        $this->assertNotNull($body);
        $this->assertSame('[Prospera] Rappel : opération planifiée « Rent & food » le 15 juin 2026', $subject);
        $this->assertStringContainsString('« Rent & food » est prévue le 15 juin 2026.', $body);
        $this->assertStringContainsString('Montant : 123,45', $body);
        $this->assertStringContainsString('Gérer vos notifications :', $body);
        $this->assertStringNotContainsString('&amp;', $body);
        $this->assertStringNotContainsString('mail.payment_reminder', $body);
    }

    public function test_the_queued_mailable_carries_ids_only(): void
    {
        $this->schedule();
        $this->remindOn('2026-06-12');
        $mail = Mail::queued(PaymentReminder::class)->first();

        $serialized = serialize($mail);
        $this->assertStringNotContainsString('12345', $serialized);
        $this->assertStringNotContainsString('123.45', $serialized);
        $this->assertStringNotContainsString('Rent', $serialized);
        $this->assertStringNotContainsString($this->owner->email, $serialized);
    }

    public function test_the_maximum_lead_reminds_366_days_ahead(): void
    {
        $schedule = $this->schedule([
            'period' => 'once', 'start_date' => '2027-06-11', 'day_of_month' => null,
            'reminder_days' => EnvelopeSchedule::MAX_REMINDER_DAYS,
        ]);

        $this->remindOn('2026-06-09');
        $this->assertSame([], $this->queued());

        $this->remindOn('2026-06-10');
        $this->assertSame([$schedule->id, '2027-06-11'], array_slice($this->queued()[0], 0, 2));
    }

    public function test_claims_of_past_occurrences_are_pruned_on_each_run(): void
    {
        $schedule = $this->schedule();
        $this->remindOn('2026-06-12');
        $this->assertSame(2, DB::table('schedule_reminders')->count());

        // Still due today: kept, so no double send.
        $this->remindOn('2026-06-15');
        $this->assertSame(2, DB::table('schedule_reminders')->count());
        $this->assertCount(2, $this->queued());

        $this->travelTo(CarbonImmutable::parse('2026-06-16 09:30:00'));
        $this->artisan('schedules:remind')
            ->expectsOutputToContain('2 past claim(s) pruned')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('schedule_reminders')->where('schedule_id', $schedule->id)->count());
    }

    public function test_a_failing_schedule_is_reported_without_stopping_the_others(): void
    {
        Exceptions::fake();
        $broken = $this->schedule(['day_of_month' => 14]);
        $healthy = $this->schedule();
        // A corrupt stored rule makes the resolver throw for this schedule only.
        DB::table('envelope_schedules')->where('id', $broken->id)->update(['period' => 'daily']);

        $this->travelTo(CarbonImmutable::parse('2026-06-12 09:30:00'));
        $this->artisan('schedules:remind')
            ->expectsOutputToContain("Schedule {$broken->id}: reminders could not be queued")
            ->assertFailed();

        Exceptions::assertReported(InvalidArgumentException::class);
        $this->assertSame([$healthy->id, $healthy->id], array_column($this->queued(), 0));
    }
}
