<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use App\Models\ContactSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Contact form (issue #17): validation, sanitization, per-IP cooldown,
 * submission log, queued mail and signed-in behavior.
 */
class ContactTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = [
        'email' => 'jane@example.com',
        'subject' => 'A question',
        'message' => 'Hello, I have a question about envelopes.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.contact_address', 'team@example.com');
    }

    public function test_contact_page_renders_for_guests(): void
    {
        $this->get(route('contact.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Contact')->where('email', null));
    }

    public function test_contact_page_prefills_the_account_email_when_signed_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contact.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Contact')->where('email', $user->email));
    }

    public function test_submission_is_logged_and_the_mail_is_queued(): void
    {
        Mail::fake();

        $response = $this->postJson(route('contact.store'), self::PAYLOAD);

        $response->assertOk()->assertJsonPath('redirect', route('contact.sent'));

        $this->assertDatabaseCount('contact_submissions', 1);
        $this->assertDatabaseHas('contact_submissions', [
            'email' => 'jane@example.com',
            'user_id' => null,
        ]);

        // The message is encrypted at rest — the raw text must not appear in
        // the stored column.
        $row = ContactSubmission::query()->sole();
        $this->assertStringNotContainsString(
            'question about envelopes',
            (string) $this->rawColumn('message', $row->id),
        );
        $this->assertSame('Hello, I have a question about envelopes.', $row->message);

        Mail::assertQueued(ContactMessage::class, 1);
        Mail::assertQueued(fn (ContactMessage $mail) => $mail->hasTo('team@example.com')
            && $mail->envelope()->subject === '[Prospera] A question'
            && $mail->contactEmail === 'jane@example.com'
            && $mail->contactMessage === 'Hello, I have a question about envelopes.'
            && $mail->accountId === null);
    }

    public function test_signed_in_submissions_use_the_account_email_and_record_the_user(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Jane Doe']);

        $response = $this->actingAs($user)->postJson(route('contact.store'), [
            // The form field is read-only when signed in; even a tampered
            // value must not win over the account email.
            'email' => 'attacker@example.com',
            ...self::PAYLOAD,
        ]);

        $response->assertOk();

        $submission = ContactSubmission::query()->sole();
        $this->assertSame($user->id, $submission->user_id);
        $this->assertSame($user->email, $submission->email);

        Mail::assertQueued(fn (ContactMessage $mail) => $mail->contactEmail === $user->email
            && $mail->accountName === 'Jane Doe'
            && $mail->accountId === $user->id);
    }

    public function test_subject_and_message_are_sanitized_to_plain_text(): void
    {
        Mail::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson(route('contact.store'), [
                'email' => 'jane@example.com',
                'subject' => '  <b>Hi</b>   <script>alert(1)</script> there &amp; friends  ',
                'message' => "<p>Line one</p>\n&amp;   more  <em>text</em>",
            ]);

        $submission = ContactSubmission::query()->sole();
        $this->assertSame('Hi alert(1) there & friends', $submission->subject);
        $this->assertSame('Line one & more text', $submission->message);

        Mail::assertQueued(fn (ContactMessage $mail) => $mail->contactSubject === 'Hi alert(1) there & friends'
            && $mail->contactMessage === 'Line one & more text');
    }

    public function test_sanitized_away_content_is_rejected(): void
    {
        Mail::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson(route('contact.store'), [
                'email' => 'jane@example.com',
                'subject' => '<b></b>',
                'message' => 'Hello',
            ])->assertInvalid(['subject']);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson(route('contact.store'), [
                'email' => 'jane@example.com',
                'subject' => 'Subject',
                'message' => '<span>   </span>',
            ])->assertInvalid(['message']);

        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_validation_mirrors_the_client_rules(): void
    {
        Mail::fake();

        $this->postJson(route('contact.store'), [
            'email' => 'not-an-email',
            'subject' => str_repeat('a', 101),
            'message' => str_repeat('a', 4001),
        ])->assertInvalid(['email', 'subject', 'message']);

        // The boundary lengths are accepted.
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson(route('contact.store'), [
                'email' => 'jane@example.com',
                'subject' => str_repeat('a', 100),
                'message' => str_repeat('a', 4000),
            ])->assertValid(['subject', 'message']);

        Mail::assertQueued(ContactMessage::class, 1);
    }

    public function test_the_same_ip_is_on_cooldown_for_twelve_hours(): void
    {
        Mail::fake();

        $this->postJson(route('contact.store'), self::PAYLOAD)->assertOk();
        $this->assertDatabaseHas('contact_submissions', ['ip_address' => '127.0.0.1']);

        $response = $this->postJson(route('contact.store'), self::PAYLOAD);

        $response->assertUnprocessable();
        $this->assertSame(
            'You already contacted us recently. Please wait before submitting again.',
            $response->json('errors.error.0'),
        );

        $this->assertDatabaseCount('contact_submissions', 1);
        Mail::assertQueued(ContactMessage::class, 1);
    }

    public function test_the_cooldown_is_per_ip(): void
    {
        Mail::fake();

        $this->postJson(route('contact.store'), self::PAYLOAD)->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->postJson(route('contact.store'), self::PAYLOAD)
            ->assertOk();

        $this->assertDatabaseCount('contact_submissions', 2);
        Mail::assertQueued(ContactMessage::class, 2);
    }

    public function test_the_cooldown_expires(): void
    {
        Mail::fake();

        $this->postJson(route('contact.store'), self::PAYLOAD)->assertOk();

        ContactSubmission::query()->update([
            'created_at' => now()->subHours(12)->subMinute(),
        ]);

        $this->postJson(route('contact.store'), self::PAYLOAD)->assertOk();
        $this->assertDatabaseCount('contact_submissions', 2);
    }

    public function test_submissions_without_a_resolvable_ip_are_rejected(): void
    {
        Mail::fake();

        $this->withServerVariables(['REMOTE_ADDR' => ''])
            ->postJson(route('contact.store'), self::PAYLOAD)
            ->assertUnprocessable();

        $this->assertDatabaseCount('contact_submissions', 0);
        Mail::assertNothingQueued();
    }

    public function test_the_route_is_throttled(): void
    {
        Mail::fake();

        // Five requests are allowed per minute per IP: the first one is a
        // successful submission, the next four are rejected by the cooldown
        // (but still counted by the throttle), the sixth is throttled.
        foreach (range(1, 5) as $i) {
            $response = $this->postJson(route('contact.store'), self::PAYLOAD);

            $i === 1
                ? $response->assertOk()
                : $response->assertUnprocessable();
        }

        $this->postJson(route('contact.store'), self::PAYLOAD)->assertTooManyRequests();
        Mail::assertQueued(ContactMessage::class, 1);
    }

    public function test_contact_prune_deletes_submissions_past_the_retention_window(): void
    {
        config()->set('prospera.contact_retention_days', 90);

        $stale = ContactSubmission::create([
            'email' => 'old@example.com',
            'subject' => 'Old',
            'message' => 'Old message',
            'ip_address' => '192.0.2.1',
        ]);
        $stale->forceFill(['created_at' => now()->subDays(91)])->save();

        $fresh = ContactSubmission::create([
            'email' => 'new@example.com',
            'subject' => 'New',
            'message' => 'New message',
            'ip_address' => '192.0.2.2',
        ]);

        $this->artisan('contact:prune')->expectsOutput('1 contact submission(s) deleted.');

        $this->assertDatabaseMissing('contact_submissions', ['id' => $stale->id]);
        $this->assertDatabaseHas('contact_submissions', ['id' => $fresh->id]);
    }

    /**
     * Read a column straight from the storage layer, bypassing casts.
     */
    private function rawColumn(string $column, int $id): ?string
    {
        $row = ContactSubmission::query()->getConnection()->selectOne(
            "select {$column} from contact_submissions where id = ?",
            [$id],
        );

        return $row?->{$column};
    }
}
