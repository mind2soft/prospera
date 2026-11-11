<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use App\Mail\ProfileInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * The queued mailables (docs/notifications.md): what lands in the queue
 * tables and how deliveries are retried.
 */
class QueuedMailTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_KEY = 'raw-invite-key-never-stored';

    private function invite(): ProfileInvite
    {
        return new ProfileInvite(
            inviterName: 'Owner',
            inviterEmail: 'owner@example.com',
            profileName: 'Family',
            role: 'collaborator',
            inviteUrl: 'https://prospera.test/invites/'.self::RAW_KEY,
            acceptUrl: 'https://prospera.test/invites/'.self::RAW_KEY.'/accept',
            expiresAt: now()->addHours(48),
        );
    }

    private function contact(): ContactMessage
    {
        return new ContactMessage(
            contactEmail: 'jane@example.com',
            contactSubject: 'A question',
            contactMessage: 'My salary is confidential',
        );
    }

    public function test_queued_payloads_do_not_hold_the_raw_invite_key_nor_the_contact_message(): void
    {
        config()->set('queue.default', 'database');

        Mail::to('invitee@example.com')->queue($this->invite());
        Mail::to('team@example.com')->queue($this->contact());

        $payloads = DB::table('jobs')->pluck('payload');

        $this->assertCount(2, $payloads);

        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString(self::RAW_KEY, $payload);
            $this->assertStringNotContainsString('salary is confidential', $payload);
            $this->assertStringNotContainsString('jane@example.com', $payload);
        }
    }

    public function test_both_mailables_are_encrypted_in_the_queue(): void
    {
        $this->assertTrue((new SendQueuedMailable($this->invite()))->shouldBeEncrypted);
        $this->assertTrue((new SendQueuedMailable($this->contact()))->shouldBeEncrypted);
    }

    public function test_deliveries_are_retried_with_a_backoff(): void
    {
        config()->set('queue.default', 'database');

        Mail::to('invitee@example.com')->queue($this->invite());
        Mail::to('team@example.com')->queue($this->contact());

        foreach ([$this->invite(), $this->contact()] as $mailable) {
            $job = new SendQueuedMailable($mailable);

            $this->assertSame(5, $job->tries);
            $this->assertSame([60, 300, 900], $job->backoff());
        }

        // The worker reads the policy from the payload.
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $decoded = json_decode($payload, true);

            $this->assertSame(5, $decoded['maxTries']);
            $this->assertSame('60,300,900', $decoded['backoff']);
        }
    }

    public function test_a_failed_delivery_is_logged_without_the_mail_content(): void
    {
        Log::spy();

        (new SendQueuedMailable($this->contact()))->failed(new RuntimeException('SMTP 451 try later'));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Queued mail delivery failed'
            && $context['mailable'] === ContactMessage::class
            && $context['message'] === 'SMTP 451 try later'
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'confidential'));
    }
}
