<?php

namespace Tests\Feature;

use App\Mail\ContributorTransaction;
use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\Transaction;
use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The "contributor transactions" notification (issue #51,
 * docs/notifications.md): a recording by anyone but the ledger owner queues
 * one email to the ledger's current owner, re-gated at send time — toggles,
 * verified address, ownership transfer, deleted transaction — with the
 * amount masked for recipients below envelope-viewer.
 */
class ContributorTransactionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $actor;

    private Envelope $envelope;

    private Ledger $ledger;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->actor = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->actor->id, ['is_owner' => false]);

        $this->ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
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

        // The envelope belongs to the recording contributor: the ledger
        // owner holds no envelope share by default, so the amount is
        // masked — grant an explicit row to unmask (docs/envelope.md).
        $this->envelope = $this->actor->envelopes()->create([
            'name' => 'Rent & food', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
    }

    private function recordTransaction(array $payload = [], ?User $as = null): ?int
    {
        $response = $this->actingAs($as ?? $this->actor)->postJson(
            "/api/ledgers/{$this->ledger->id}/transactions",
            array_merge([
                'envelope_id' => $this->envelope->id,
                'amount' => 42.5,
                'event_at' => '2026-06-15',
            ], $payload),
        );

        return $response->isSuccessful()
            ? (int) $response->json('transaction.id')
            : null;
    }

    /**
     * The raw body of the last mail delivered through the array transport —
     * the sync queue delivers the POST's mail during the request; `deliver`
     * (called explicitly, like the queue worker would) adds one more, which
     * `last()` picks up.
     */
    private function lastDeliveredBody(): ?string
    {
        $transport = $this->app['mailer']->getSymfonyTransport();
        $messages = $transport->messages();

        return $messages->isEmpty()
            ? null
            : quoted_printable_decode($messages->last()->toString());
    }

    /**
     * Deliver the mailable explicitly — the way the queue worker would —
     * and return the raw body, or null when the send-time gate suppressed
     * the delivery. The address is a queued hint only: the mailable
     * re-resolves the current ledger owner itself.
     */
    private function deliver(ContributorTransaction $mailable, string $hintEmail): ?string
    {
        $transport = $this->app['mailer']->getSymfonyTransport();
        $before = count($transport->messages());

        $mailable->to($hintEmail)->send($this->app['mailer']);

        $messages = $transport->messages();

        return count($messages) === $before
            ? null // suppressed: nothing new was delivered
            : quoted_printable_decode($messages->last()->toString());
    }

    private function mailableFor(int $transactionId): ContributorTransaction
    {
        return new ContributorTransaction($transactionId, $this->ledger->id);
    }

    private function publishedBudget(): Budget
    {
        $budget = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => 2026,
            'effective_month' => 6,
            'published_at' => now(),
        ]);
        $budget->permissions()->create([
            'user_id' => $this->owner->id,
            'permission' => BudgetPermission::PERMISSION_OWNER,
        ]);

        return $budget;
    }

    public function test_a_contributor_recording_a_transaction_notifies_the_owner(): void
    {
        $this->publishedBudget();

        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        $body = $this->lastDeliveredBody();

        $this->assertNotNull($body);
        // One mail, addressed to the ledger owner — the mailable resolved
        // the recipient itself, from the owner row in ledger_permissions.
        $this->assertStringContainsString($this->owner->email, $body);
        $this->assertStringNotContainsString($this->actor->email, $body);
        // Exactly one delivery for the request (sync queue).
        $this->assertCount(1, $this->app['mailer']->getSymfonyTransport()->messages());
    }

    public function test_the_owner_recording_a_transaction_sends_nothing(): void
    {
        // The ledger owner recording with the envelope they own.
        $envelope = $this->owner->envelopes()->create([
            'name' => 'Housing', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        $transactionId = $this->recordTransaction(['envelope_id' => $envelope->id], as: $this->owner);
        $this->assertNotNull($transactionId);

        $this->assertNull($this->lastDeliveredBody());
    }

    public function test_validation_failures_and_forbidden_attempts_send_nothing(): void
    {
        // Unknown envelope: 422.
        $this->actingAs($this->actor)->postJson("/api/ledgers/{$this->ledger->id}/transactions", [
            'envelope_id' => 999999,
            'amount' => 42.5,
            'event_at' => '2026-06-15',
        ])->assertUnprocessable();
        // A profile member without ledger-contributor access: 403.
        $member = User::factory()->create();
        $this->profile->members()->attach($member->id, ['is_owner' => false]);
        $this->actingAs($member)
            ->postJson("/api/ledgers/{$this->ledger->id}/transactions", [
                'envelope_id' => $this->envelope->id,
                'amount' => 42.5,
                'event_at' => '2026-06-15',
            ])->assertForbidden();

        $this->assertNull($this->lastDeliveredBody());
    }

    public function test_the_queue_payload_carries_ids_only_and_is_encrypted(): void
    {
        config()->set('queue.default', 'database');
        $this->publishedBudget();

        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        $payload = (string) DB::table('jobs')->value('payload');
        // The mailable is encrypted in the queue, and carries no decrypted
        // amount nor email address anyway — IDs only.
        $this->assertTrue((new SendQueuedMailable($this->mailableFor((int) $transactionId)))->shouldBeEncrypted);
        $this->assertStringNotContainsString('42.5', $payload);
        $this->assertStringNotContainsString('-4250', $payload);
        $this->assertStringNotContainsString($this->owner->email, $payload);
    }

    public function test_master_switch_flipped_after_queueing_suppresses_delivery(): void
    {
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        // The toggles were on when the mail was queued, off when it is sent.
        $this->owner->notification_settings = $this->owner->notification_settings->with(['master' => false]);
        $this->owner->save();

        $this->assertNull($this->deliver($this->mailableFor((int) $transactionId), $this->owner->email));
    }

    public function test_contributor_transactions_toggle_flipped_after_queueing_suppresses_delivery(): void
    {
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        $this->owner->notification_settings = $this->owner->notification_settings
            ->with([NotificationSettings::CONTRIBUTOR_TRANSACTIONS => false]);
        $this->owner->save();

        $this->assertNull($this->deliver($this->mailableFor((int) $transactionId), $this->owner->email));
    }

    public function test_unverified_recipients_get_nothing(): void
    {
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        $this->owner->forceFill(['email_verified_at' => null])->save();

        $this->assertNull($this->deliver($this->mailableFor((int) $transactionId), $this->owner->email));
    }

    public function test_a_transaction_deleted_before_send_sends_nothing(): void
    {
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        Transaction::whereKey($transactionId)->delete();

        $this->assertNull($this->deliver($this->mailableFor((int) $transactionId), $this->owner->email));
    }

    public function test_an_actor_who_deleted_their_account_renders_a_generic_body(): void
    {
        // transactions.user_id is nullable-on-delete: the recording
        // survives an account deletion with no actor, while the mail waits
        // in the queue. The body renders a localized generic actor instead
        // of an empty one.
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);
        Transaction::whereKey($transactionId)->update(['user_id' => null]);

        $body = $this->deliver($this->mailableFor((int) $transactionId), $this->owner->email);

        $this->assertNotNull($body);
        $this->assertStringContainsString('A member who has since deleted their account', $body);
        $this->assertStringNotContainsString('&amp;', $body);
    }

    public function test_ownership_transferred_before_send_notifies_the_new_owner(): void
    {
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        // Ownership moves to a third member while the mail sits in the
        // queue: the **current** owner receives the mail (the old owner's
        // address is the queued hint only), and nothing goes to the old
        // owner.
        $newOwner = User::factory()->create();
        $this->profile->members()->attach($newOwner->id, ['is_owner' => false]);
        $this->ledger->permissions()->create([
            'user_id' => $newOwner->id,
            'permission' => LedgerPermission::PERMISSION_OWNER,
        ]);
        $this->ledger->permissions()
            ->where('user_id', $this->owner->id)
            ->update(['permission' => LedgerPermission::PERMISSION_VIEWER]);
        $this->ledger->permissions()
            ->where('user_id', $this->actor->id)
            ->update(['permission' => LedgerPermission::PERMISSION_CONTRIBUTOR]);

        $body = $this->deliver($this->mailableFor((int) $transactionId), $this->owner->email);

        $this->assertNotNull($body);
        $this->assertStringContainsString($newOwner->email, $body);
        $this->assertStringNotContainsString($this->owner->email, $body);
    }

    public function test_nothing_is_sent_when_the_current_owner_is_the_actor(): void
    {
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        $this->ledger->permissions()
            ->where('user_id', $this->owner->id)
            ->update(['permission' => LedgerPermission::PERMISSION_VIEWER]);
        $this->ledger->permissions()
            ->where('user_id', $this->actor->id)
            ->update(['permission' => LedgerPermission::PERMISSION_OWNER]);

        // The current owner **is** the recording contributor.
        $this->assertNull($this->deliver($this->mailableFor((int) $transactionId), $this->owner->email));
    }

    public function test_an_owner_without_envelope_viewer_gets_no_amount(): void
    {
        $this->publishedBudget();
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        $body = $this->lastDeliveredBody();

        $this->assertNotNull($body);
        // Metadata only — name and direction — and no amount, no hint at it.
        $this->assertStringContainsString('Rent & food', $body);
        $this->assertStringContainsString('expense', $body);
        $this->assertStringNotContainsString('42.50', $body);
        $this->assertStringNotContainsString('42,50', $body);
        $this->assertStringNotContainsString('$', $body);
    }

    public function test_an_owner_with_envelope_viewer_sees_the_decrypted_amount(): void
    {
        $this->publishedBudget();
        $this->envelope->permissions()->create([
            'user_id' => $this->owner->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        $body = $this->lastDeliveredBody();

        $this->assertNotNull($body);
        // Positive, in the published budget's currency (CAD), two decimals.
        $this->assertStringContainsString('42.50', $body);
        $this->assertStringContainsString('CA$', $body);
    }

    public function test_the_envelope_owner_recipient_sees_the_amount_too(): void
    {
        // The ledger owner is the envelope owner — the common case; the
        // permission check resolves to owner, never masked.
        $this->publishedBudget();
        $envelope = $this->owner->envelopes()->create([
            'name' => 'Housing', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        // The actor needs envelope contributor+ to record.
        $envelope->permissions()->create([
            'user_id' => $this->actor->id,
            'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR,
        ]);
        $transactionId = $this->recordTransaction(['envelope_id' => $envelope->id]);
        $this->assertNotNull($transactionId);

        $body = $this->lastDeliveredBody();

        $this->assertNotNull($body);
        $this->assertStringContainsString('CA$', $body);
    }

    public function test_bodies_render_in_en_and_fr_without_html_escaping(): void
    {
        $this->publishedBudget();
        $this->envelope->permissions()->create([
            'user_id' => $this->owner->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);
        $transactionId = $this->recordTransaction();
        $this->assertNotNull($transactionId);

        $en = $this->lastDeliveredBody();
        $this->assertNotNull($en);
        $this->assertStringContainsString('recorded a transaction in "2026"', $en);
        $this->assertStringContainsString('Rent & food', $en);
        $this->assertStringNotContainsString('&amp;', $en);
        $this->assertStringContainsString('Manage your notifications', $en);

        $this->owner->update(['locale' => 'fr']);
        $fr = $this->deliver($this->mailableFor((int) $transactionId), $this->owner->email);
        $this->assertNotNull($fr);
        $this->assertStringContainsString('a enregistré une transaction dans « 2026 »', $fr);
        $this->assertStringNotContainsString('&amp;', $fr);
        $this->assertStringNotContainsString('recorded a transaction', $fr);

        // The subject is localized too (asserted through the mailable's
        // locale scope — the raw message header is RFC 2047-encoded).
        $mailable = $this->mailableFor((int) $transactionId);
        $subject = $mailable->withLocale('fr', fn (): string => $mailable->envelope()->subject);
        $this->assertSame(
            '[Prospera] '.$this->actor->name.' a enregistré une transaction dans « 2026 »',
            $subject,
        );
    }
}
