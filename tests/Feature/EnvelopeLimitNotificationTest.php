<?php

namespace Tests\Feature;

use App\Jobs\CheckEnvelopeLimit;
use App\Mail\EnvelopeLimitExceeded;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\Transaction;
use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "envelope limit exceeded" notification (issue #52,
 * docs/notifications.md): recorded spending for an expense envelope within
 * a month crossing its allocation on that month's published budget — per
 * ledger — queues one email per eligible recipient (envelope owner +
 * contributors, all profile members), claimed at most once per (published
 * budget, envelope, ledger), with every gate and amount re-resolved at
 * send time.
 */
class EnvelopeLimitNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $actor;

    private Envelope $envelope;

    private Ledger $ledger;

    private FinancialProfile $profile;

    /** Strictly increasing published_at per call (deterministic latest-wins ties). */
    private ?CarbonImmutable $lastBudgetPublishedAt = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->actor = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->actor->id, ['is_owner' => false]);

        // Isolate this notification from its sibling: every non-owner
        // recording also triggers the #51 contributor-transaction mail to
        // the ledger owner — suppressed here so the transport only carries
        // envelope-limit mails.
        $this->owner->notification_settings = $this->owner->notification_settings
            ->with([NotificationSettings::CONTRIBUTOR_TRANSACTIONS => false]);
        $this->owner->save();

        $this->ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 5000,
        ]);
        $this->ledger->permissions()->create([
            'user_id' => $this->owner->id,
            'permission' => LedgerPermission::PERMISSION_OWNER,
        ]);
        $this->ledger->permissions()->create([
            'user_id' => $this->actor->id,
            'permission' => LedgerPermission::PERMISSION_CONTRIBUTOR,
        ]);

        // The expense envelope, owned by the ledger owner; the recording
        // contributor holds an explicit contributor share on it.
        $this->envelope = $this->owner->envelopes()->create([
            'name' => 'Rent & food', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $this->envelope->permissions()->create([
            'user_id' => $this->actor->id,
            'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR,
        ]);

        $this->publishBudget();
    }

    /**
     * A published CAD budget for June 2026, allocating -200 (expense
     * convention, currency units) on the envelope.
     */
    private function publishBudget(int $year = 2026, int $month = 6, int $amount = -200): Budget
    {
        // A distinct published_at per call: when two revisions of the same
        // month exist, the checker references the latest published one —
        // the tie must be deterministic.
        $this->lastBudgetPublishedAt ??= now();

        $budget = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => $year,
            'effective_month' => $month,
            'published_at' => $this->lastBudgetPublishedAt,
        ]);
        $budget->permissions()->create([
            'user_id' => $this->owner->id,
            'permission' => BudgetPermission::PERMISSION_OWNER,
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $this->envelope->id,
            'amount' => $amount,
            'type' => 'fixed',
        ]);

        $this->lastBudgetPublishedAt = $this->lastBudgetPublishedAt->addMinute();

        return $budget;
    }

    private function recordTransaction(int|float $amount, string $date = '2026-06-15'): ?int
    {
        $response = $this->actingAs($this->actor)->postJson(
            "/api/ledgers/{$this->ledger->id}/transactions",
            [
                'envelope_id' => $this->envelope->id,
                'amount' => $amount,
                'event_at' => $date,
            ],
        );

        $transactionId = $response->isSuccessful()
            ? (int) $response->json('transaction.id')
            : null;

        // The check is a queued job dispatched **after commit** (the job is
        // marked afterCommit): the wrapping RefreshDatabase transaction of
        // this test never commits, so the deferred dispatch is a no-op here
        // — run the worker's half explicitly instead.
        if ($transactionId !== null) {
            (new CheckEnvelopeLimit($transactionId))->handle();
        }

        return $transactionId;
    }

    /**
     * The raw body of the last mail delivered through the array transport —
     * the sync queue runs the check (and its mails) inline during the POST.
     */
    private function lastDeliveredBody(): ?string
    {
        $transport = $this->app['mailer']->getSymfonyTransport();
        $messages = $transport->messages();

        return $messages->isEmpty()
            ? null
            : quoted_printable_decode($messages->last()->toString());
    }

    private function transportCount(): int
    {
        return count($this->app['mailer']->getSymfonyTransport()->messages());
    }

    /**
     * Deliver the mailable explicitly — the way the queue worker would —
     * and return the raw body, or null when the send-time gate suppressed
     * the delivery. The address is a queued hint only: the mailable
     * re-resolves the recipient's eligibility itself.
     */
    private function deliver(EnvelopeLimitExceeded $mailable, string $hintEmail): ?string
    {
        $transport = $this->app['mailer']->getSymfonyTransport();
        $before = count($transport->messages());

        $mailable->to($hintEmail)->send($this->app['mailer']);

        $messages = $transport->messages();

        return count($messages) === $before
            ? null // suppressed: nothing new was delivered
            : quoted_printable_decode($messages->last()->toString());
    }

    private function mailableFor(User $recipient): EnvelopeLimitExceeded
    {
        $budget = Budget::query()->where('profile_id', $this->profile->id)->firstOrFail();

        return new EnvelopeLimitExceeded(
            budgetId: $budget->id,
            envelopeId: $this->envelope->id,
            ledgerId: $this->ledger->id,
            recipientUserId: $recipient->id,
        );
    }

    private function claimExists(): bool
    {
        return DB::table('envelope_limit_notifications')
            ->where('ledger_id', $this->ledger->id)
            ->where('envelope_id', $this->envelope->id)
            ->exists();
    }

    public function test_a_crossing_transaction_notifies_the_envelope_owner_and_contributors(): void
    {
        // -250 recorded against a -200 allocation: the recording contributor
        // crosses it — the envelope owner and every contributor (including
        // the actor) are notified.
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        $this->assertSame(2, $this->transportCount());
        $this->assertTrue($this->claimExists());

        // Both eligible recipients — the envelope owner and the recording
        // contributor — got one mail each.
        $recipients = array_map(
            fn ($message): string => $message->getEnvelope()->getRecipients()[0]?->getAddress() ?? '',
            iterator_to_array($this->app['mailer']->getSymfonyTransport()->messages()),
        );
        sort($recipients);
        $expected = [$this->actor->email, $this->owner->email];
        sort($expected);
        $this->assertSame($expected, $recipients);

        // One claim row, one notification per (published budget, envelope,
        // ledger).
        $this->assertSame(
            1,
            DB::table('envelope_limit_notifications')->count(),
        );
    }

    public function test_a_transaction_within_the_allocation_sends_nothing(): void
    {
        $transactionId = $this->recordTransaction(100);

        $this->assertNotNull($transactionId);
        $this->assertSame(0, $this->transportCount());
        $this->assertFalse($this->claimExists());
    }

    public function test_the_exact_boundary_is_not_over_but_the_next_minor_unit_is(): void
    {
        // Recorded exactly equals the allocation: remaining allocated is
        // zero — within, not over (docs/notifications.md).
        $transactionId = $this->recordTransaction(200);

        $this->assertNotNull($transactionId);
        $this->assertSame(0, $this->transportCount());

        // -200.01 crosses it.
        $this->recordTransaction('200.01');
        $this->assertSame(2, $this->transportCount());
    }

    public function test_an_already_over_envelope_does_not_notify_again(): void
    {
        $this->recordTransaction(250);
        $this->assertSame(2, $this->transportCount());

        // Later over-limit transactions do not send again: the crossing is
        // the actionable moment.
        $this->recordTransaction(50);
        $this->recordTransaction(10);

        $this->assertSame(2, $this->transportCount());
        $this->assertSame(1, DB::table('envelope_limit_notifications')->count());
    }

    public function test_a_rolled_back_or_failed_insert_never_notifies(): void
    {
        // A closed ledger rejects the write before the check ever runs.
        $this->ledger->update(['closed_at' => now()]);

        $this->assertNull($this->recordTransaction(250));

        $this->assertSame(0, $this->transportCount());
        $this->assertFalse($this->claimExists());
    }

    public function test_a_month_without_a_published_budget_sends_nothing(): void
    {
        // July has no published budget at all.
        $transactionId = $this->recordTransaction(250, '2026-07-10');

        $this->assertNotNull($transactionId);
        $this->assertSame(0, $this->transportCount());
        $this->assertFalse($this->claimExists());
    }

    public function test_an_unpublished_budget_sends_nothing(): void
    {
        Budget::query()->update(['published_at' => null]);

        $this->recordTransaction(250);

        $this->assertSame(0, $this->transportCount());
        $this->assertFalse($this->claimExists());
    }

    public function test_an_unallocated_envelope_sends_nothing(): void
    {
        BudgetEnvelope::query()->delete();

        $this->recordTransaction(250);

        $this->assertSame(0, $this->transportCount());
        $this->assertFalse($this->claimExists());
    }

    public function test_a_revenue_envelope_never_triggers(): void
    {
        $revenue = $this->owner->envelopes()->create([
            'name' => 'Salary', 'type' => 'revenue', 'color' => '#10b981',
        ]);
        $budget = Budget::query()->where('profile_id', $this->profile->id)->firstOrFail();
        $budget->envelopes()->create([
            'envelope_id' => $revenue->id,
            'amount' => 3000,
            'type' => 'fixed',
        ]);
        // The actor needs envelope contributor+ to record at all.
        $revenue->permissions()->create([
            'user_id' => $this->actor->id,
            'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR,
        ]);

        $response = $this->actingAs($this->actor)->postJson(
            "/api/ledgers/{$this->ledger->id}/transactions",
            [
                'envelope_id' => $revenue->id,
                'amount' => 500,
                'event_at' => '2026-06-15',
            ],
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(0, $this->transportCount());
        $this->assertFalse($this->claimExists());
    }

    public function test_a_view_share_and_a_non_member_contributor_receive_nothing(): void
    {
        // A profile member holding only a viewer share: below contributor,
        // never a recipient.
        $viewer = User::factory()->create();
        $this->profile->members()->attach($viewer->id, ['is_owner' => false]);
        $this->envelope->permissions()->create([
            'user_id' => $viewer->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        // An envelope contributor who is not a member of the ledger's
        // financial profile: the profile's ledger activity must not leak
        // outside it.
        $outsider = User::factory()->create();
        $this->envelope->permissions()->create([
            'user_id' => $outsider->id,
            'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR,
        ]);

        $this->recordTransaction(250);

        $this->assertSame(2, $this->transportCount()); // owner + actor only
        foreach ($this->app['mailer']->getSymfonyTransport()->messages() as $message) {
            $body = quoted_printable_decode($message->toString());
            $this->assertStringNotContainsString($viewer->email, $body);
            $this->assertStringNotContainsString($outsider->email, $body);
        }
    }

    public function test_a_new_published_budget_revision_re_arms_the_check(): void
    {
        $this->recordTransaction(250);
        $this->assertSame(2, $this->transportCount());

        // A revision: a new published budget for the same month — a new
        // budget_id — re-arms the check.
        $this->publishBudget(amount: -300); // -300 allocation

        // -310 recorded against -300: a crossing on the revision.
        $this->recordTransaction(60);

        $this->assertSame(4, $this->transportCount());
        $this->assertSame(2, DB::table('envelope_limit_notifications')->count());
    }

    public function test_deleting_transactions_does_not_re_arm_the_check(): void
    {
        $this->recordTransaction(250);
        $this->assertSame(2, $this->transportCount());

        // Spending drops back under (the undo window soft-deletes the row):
        // the claim persists, a new crossing never re-notifies on this
        // budget.
        Transaction::where('ledger_id', $this->ledger->id)->delete();

        $this->recordTransaction(250);

        $this->assertSame(2, $this->transportCount());
        $this->assertSame(1, DB::table('envelope_limit_notifications')->count());
    }

    public function test_running_the_check_twice_for_one_transaction_never_double_sends(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);
        $this->assertSame(2, $this->transportCount());

        // A job retry (or a concurrent recording reaching the claim first)
        // hits the unique claim index and stops.
        (new CheckEnvelopeLimit((int) $transactionId))->handle();

        $this->assertSame(2, $this->transportCount());
        $this->assertSame(1, DB::table('envelope_limit_notifications')->count());
    }

    public function test_the_queued_job_payload_carries_the_transaction_id_only(): void
    {
        config()->set('queue.default', 'database');

        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        // The production dispatch defers to the commit (afterCommit); the
        // test's wrapping transaction never commits, so push the job with
        // the commit guard off — the payload is identical either way.
        $job = new CheckEnvelopeLimit((int) $transactionId);
        $job->beforeCommit();
        dispatch($job);

        $payloads = DB::table('jobs')->pluck('payload')->implode("\n");
        // The job carries the transaction's ID only: no amounts, no names,
        // no emails ride the queue.
        $this->assertStringNotContainsString('250', $payloads);
        $this->assertStringNotContainsString($this->owner->email, $payloads);
        $this->assertStringNotContainsString($this->actor->email, $payloads);

        DB::table('jobs')->delete();
    }

    public function test_the_master_switch_flipped_after_queueing_suppresses_delivery(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        $this->owner->notification_settings = $this->owner->notification_settings->with(['master' => false]);
        $this->owner->save();

        $this->assertNull($this->deliver($this->mailableFor($this->owner), $this->owner->email));
    }

    public function test_the_envelope_limit_toggle_flipped_after_queueing_suppresses_delivery(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        $this->owner->notification_settings = $this->owner->notification_settings
            ->with([NotificationSettings::ENVELOPE_LIMIT_EXCEEDED => false]);
        $this->owner->save();

        $this->assertNull($this->deliver($this->mailableFor($this->owner), $this->owner->email));
    }

    public function test_unverified_recipients_get_nothing(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        $this->owner->forceFill(['email_verified_at' => null])->save();

        $this->assertNull($this->deliver($this->mailableFor($this->owner), $this->owner->email));
    }

    public function test_a_contributor_revoked_before_send_gets_nothing(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        // The contributor share is removed while the mail waits in the
        // queue: the recipient no longer qualifies and the stale share
        // resolves to "none" — below viewer, the whole mail is suppressed
        // rather than risk leaking amounts.
        EnvelopePermission::query()
            ->where('envelope_id', $this->envelope->id)
            ->where('user_id', $this->actor->id)
            ->delete();

        $this->assertNull($this->deliver($this->mailableFor($this->actor), $this->actor->email));
        // The envelope owner still qualifies.
        $this->assertNotNull($this->deliver($this->mailableFor($this->owner), $this->owner->email));
    }

    public function test_the_amounts_match_the_decrypted_values(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        $body = $this->lastDeliveredBody();

        $this->assertNotNull($body);
        // Allocated -200, recorded -250, over 50 — positive, in the
        // published budget's currency, decrypted from the encrypted-at-rest
        // columns.
        $this->assertStringContainsString('CA$200.00', $body);
        $this->assertStringContainsString('CA$250.00', $body);
        $this->assertStringContainsString('CA$50.00', $body);
    }

    public function test_bodies_render_in_en_and_fr_without_html_escaping(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        $en = $this->lastDeliveredBody();
        $this->assertNotNull($en);
        $this->assertStringContainsString('is over its allocation for June 2026', $en);
        $this->assertStringContainsString('Rent & food', $en);
        $this->assertStringNotContainsString('&amp;', $en);
        $this->assertStringContainsString('Manage your notifications', $en);

        $this->owner->update(['locale' => 'fr']);
        $fr = $this->deliver($this->mailableFor($this->owner), $this->owner->email);
        $this->assertNotNull($fr);
        $this->assertStringContainsString('dépasse son allocation pour juin 2026', $fr);
        $this->assertStringNotContainsString('&amp;', $fr);
        $this->assertStringNotContainsString('is over its allocation', $fr);

        // The subject is localized too (asserted through the mailable's
        // locale scope — the raw message header is RFC 2047-encoded).
        $mailable = $this->mailableFor($this->owner);
        $subject = $mailable->withLocale('fr', fn (): string => $mailable->envelope()->subject);
        $this->assertSame(
            '[Prospera] L\'enveloppe « Rent & food » dépasse son budget de juin 2026',
            $subject,
        );
    }

    public function test_no_amounts_when_the_allocation_is_gone_before_send(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        // The budget edit removed the envelope's allocation while the mail
        // was queued: the notification is stale — no amounts, no misleading
        // figures (the mailable still renders, metadata only).
        BudgetEnvelope::query()->delete();

        $body = $this->deliver($this->mailableFor($this->owner), $this->owner->email);

        $this->assertNotNull($body);
        $this->assertStringContainsString('Rent & food', $body);
        $this->assertStringNotContainsString('CA$', $body);
    }

    public function test_no_amounts_when_spending_dropped_back_within_before_send(): void
    {
        $transactionId = $this->recordTransaction(250);
        $this->assertNotNull($transactionId);

        // The undo window soft-deletes the rows while the mail waits in the
        // queue: the month is no longer over the allocation — a "amount
        // over" figure would be wrong, so the mail renders metadata only.
        Transaction::where('ledger_id', $this->ledger->id)->delete();

        $body = $this->deliver($this->mailableFor($this->owner), $this->owner->email);

        $this->assertNotNull($body);
        $this->assertStringContainsString('Rent & food', $body);
        $this->assertStringNotContainsString('CA$', $body);
    }
}
