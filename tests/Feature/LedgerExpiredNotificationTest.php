<?php

namespace Tests\Feature;

use App\Mail\LedgerExpired;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The "ledger expired" notification (issue #50, docs/notifications.md): a
 * daily run notifies the owner and the profile members of every open
 * ledger past its end date, once per ledger ever, and re-gates everything
 * at send time.
 */
class LedgerExpiredNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $stranger;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);
    }

    private function createLedger(array $attributes = []): Ledger
    {
        $ledger = Ledger::create(array_merge([
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'starting_balance' => 5000,
        ], $attributes));

        $ledger->permissions()->create([
            'user_id' => $this->owner->id,
            'permission' => LedgerPermission::PERMISSION_OWNER,
        ]);

        return $ledger;
    }

    private function runCommand(): void
    {
        $this->artisan('ledgers:notify-expired')->assertSuccessful();
    }

    public function test_an_expired_open_ledger_queues_one_mail_per_recipient(): void
    {
        Mail::fake();
        $ledger = $this->createLedger();
        $this->member->update(['locale' => 'fr']);

        $this->runCommand();

        Mail::assertQueued(LedgerExpired::class, 2);
        Mail::assertQueued(fn (LedgerExpired $mail): bool => $mail->hasTo($this->owner->email));
        Mail::assertQueued(fn (LedgerExpired $mail): bool => $mail->hasTo($this->member->email));
        $this->assertNotNull($ledger->fresh()->expired_notified_at);
    }

    public function test_not_expired_closed_and_already_notified_ledgers_send_nothing(): void
    {
        Mail::fake();
        // Not yet expired.
        $this->createLedger(['end_date' => now()->addDay()->toDateString()]);
        // Expired, but closed.
        $this->createLedger(['closed_at' => now()]);
        // Expired, but already notified.
        $this->createLedger()->forceFill(['expired_notified_at' => now()->subDay()])->save();

        $this->runCommand();

        Mail::assertNothingQueued();
    }

    public function test_running_the_command_twice_notifies_once_per_recipient(): void
    {
        Mail::fake();
        // Expired ten days ago: a catch-up over every missed day, on the
        // first run that sees it.
        $ledger = $this->createLedger(['end_date' => now()->subDays(10)->toDateString()]);

        $this->runCommand();

        Mail::assertQueued(LedgerExpired::class, 2);
        $notifiedAt = $ledger->fresh()->expired_notified_at;
        $this->assertNotNull($notifiedAt);

        // The second run claims nothing: the marker never moves.
        $this->runCommand();

        Mail::assertQueued(LedgerExpired::class, 2);
        $this->assertTrue($notifiedAt->equalTo($ledger->fresh()->expired_notified_at));
    }

    public function test_a_reopened_expired_ledger_is_notified_on_the_next_run(): void
    {
        Mail::fake();
        $ledger = $this->createLedger();

        // Closed before the run: nothing is sent.
        $ledger->update(['closed_at' => now()]);
        $this->runCommand();
        Mail::assertNothingQueued();

        // Re-opened after expiry, never notified: first detected now.
        $ledger->update(['closed_at' => null]);
        $this->runCommand();
        Mail::assertQueued(LedgerExpired::class, 2);

        // Closing and re-opening again never re-arms the notification.
        $ledger->update(['closed_at' => now()]);
        $ledger->update(['closed_at' => null]);
        $this->runCommand();
        Mail::assertQueued(LedgerExpired::class, 2);
    }

    public function test_the_marker_survives_a_close_and_reopen_cycle(): void
    {
        $ledger = $this->createLedger();
        $this->runCommand();
        $notifiedAt = $ledger->fresh()->expired_notified_at;

        $ledger->update(['closed_at' => now()]);
        $ledger->update(['closed_at' => null]);

        $this->assertTrue($notifiedAt->equalTo($ledger->fresh()->expired_notified_at));
    }

    /**
     * Deliver the mailable through the array transport and return the raw
     * body — or null when the send-time gate suppressed the delivery.
     */
    private function deliver(Mailable $mailable, string $email): ?string
    {
        $transport = $this->app['mailer']->getSymfonyTransport();
        $mailable->to($email)->send($this->app['mailer']);

        $messages = $transport->messages();

        return $messages->isEmpty()
            ? null
            : quoted_printable_decode($messages->last()->toString());
    }

    private function mailableFor(User $recipient, ?Ledger $ledger = null): LedgerExpired
    {
        $ledger ??= $this->createLedger();

        return new LedgerExpired(
            ledgerId: $ledger->id,
            ledgerName: $ledger->name,
            profileId: $this->profile->id,
            profileName: $this->profile->name,
            startDate: $ledger->start_date,
            endDate: $ledger->end_date,
            recipientUserId: $recipient->id,
            recipientLocale: $recipient->locale ?? 'en',
        );
    }

    public function test_the_master_switch_flipped_after_queuing_suppresses_delivery(): void
    {
        $mailable = $this->mailableFor($this->owner);

        // The toggle was on when the mail was queued, off when it is sent.
        $this->owner->notification_settings = $this->owner->notification_settings->with(['master' => false]);
        $this->owner->save();

        $this->assertNull($this->deliver($mailable, $this->owner->email));
    }

    public function test_the_ledger_expired_toggle_flipped_after_queuing_suppresses_delivery(): void
    {
        $mailable = $this->mailableFor($this->owner);

        $this->owner->notification_settings = $this->owner->notification_settings
            ->with([NotificationSettings::LEDGER_EXPIRED => false]);
        $this->owner->save();

        $this->assertNull($this->deliver($mailable, $this->owner->email));
    }

    public function test_unverified_recipients_get_nothing(): void
    {
        $this->owner->forceFill(['email_verified_at' => null])->save();

        $this->assertNull($this->deliver($this->mailableFor($this->owner), $this->owner->email));
    }

    public function test_a_member_who_left_the_profile_gets_nothing(): void
    {
        $mailable = $this->mailableFor($this->member);

        $this->profile->members()->detach($this->member->id);

        $this->assertNull($this->deliver($mailable, $this->member->email));
    }

    public function test_a_verified_member_with_toggles_on_receives_the_body(): void
    {
        $body = $this->deliver($this->mailableFor($this->member), $this->member->email);

        $this->assertNotNull($body);
        $this->assertStringContainsString('has reached its end date', $body);
        $this->assertStringContainsString('Manage your notifications', $body);
    }

    public function test_bodies_render_in_en_and_fr_without_html_escaping(): void
    {
        $ledger = $this->createLedger(['name' => 'Smith & Co 2026']);

        $en = $this->deliver($this->mailableFor($this->owner, $ledger), $this->owner->email);
        $this->assertStringContainsString('The open ledger "Smith & Co 2026" of your financial profile "Family"', $en);
        $this->assertStringNotContainsString('&amp;', $en);

        $this->member->update(['locale' => 'fr']);
        $mailable = $this->mailableFor($this->member, $ledger);
        $fr = $this->deliver($mailable, $this->member->email);
        $this->assertStringContainsString('a atteint sa date de fin', $fr);
        $this->assertStringContainsString('« Smith & Co 2026 »', $fr);
        $this->assertStringNotContainsString('&amp;', $fr);
        $this->assertStringNotContainsString('has reached its end date', $fr);

        // The subject is localized too (asserted through the mailable's
        // locale scope — the raw message header is RFC 2047-encoded).
        $subject = $mailable->withLocale('fr', fn (): string => $mailable->envelope()->subject);
        $this->assertSame('[Prospera] Le grand livre « Smith & Co 2026 » a atteint sa date de fin', $subject);
    }

    public function test_the_next_step_hint_follows_the_current_owner_at_send_time(): void
    {
        $ledger = $this->createLedger();

        // Ownership transfers to the member while the mail sits in the
        // queue: the old owner (still a profile member) reads the member
        // hint, the new owner reads the owner hint.
        $ledger->permissions()->where('user_id', $this->owner->id)->update(['permission' => 'contributor']);
        $ledger->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => LedgerPermission::PERMISSION_OWNER,
        ]);

        $formerOwner = $this->deliver($this->mailableFor($this->owner, $ledger), $this->owner->email);
        $this->assertStringContainsString('The ledger owner can close it', $formerOwner);

        $newOwner = $this->deliver($this->mailableFor($this->member, $ledger), $this->member->email);
        $this->assertStringContainsString('As the ledger owner, you can close it', $newOwner);
    }

    public function test_the_subject_carries_a_sanitized_ledger_name(): void
    {
        $mailable = $this->mailableFor($this->owner, $this->createLedger(['name' => "Evil\r\nBcc: x@example.com"]));

        $this->assertSame('[Prospera] Ledger "Evil Bcc:" has reached its end date', $mailable->envelope()->subject);
    }
}
