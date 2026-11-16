<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use App\Mail\ProfileInvite;
use App\Models\ContactSubmission;
use App\Models\User;
use App\Security\Amounts\AmountKeyRing;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * APP_KEY rotation regression for the retained data the amount checks do not
 * cover (issue #83, docs/encryption.md): the contact messages (encrypted
 * cast) and the encrypted queued mail payloads (ShouldBeEncrypted mailables
 * in jobs/failed_jobs). Only disposable, randomly generated keys are used.
 */
class AppKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'My salary is confidential';

    protected function setUp(): void
    {
        parent::setUp();

        // The real queue tables: Mail::fake alone never exercises payload
        // encryption (docs/encryption.md).
        config(['queue.default' => 'database']);
    }

    // ------------------------------------------------------------------
    // Rotation
    // ------------------------------------------------------------------

    public function test_contact_messages_and_queued_mail_survive_an_app_key_rotation(): void
    {
        $old = (string) config('app.key');
        $new = $this->newAppKey();
        $submission = $this->seedUnderCurrentKey();
        $this->queueMails();

        // The contact message is unreadable with the new key alone, and the
        // queue still depends on the old one: the gate refuses removal.
        $this->useAppKey($new, [$old]);
        $this->artisan('encryption:verify')->expectsOutputToContain('jobs #')->assertFailed();

        $this->artisan('contact:reencrypt')->assertSuccessful();
        $this->assertTrue($this->decryptsWith($new, $this->rawMessage($submission)));

        // The queued payloads are not re-encrypted in place: they are drained
        // through the real worker, whose payload decode is what proves the
        // migration (Mail::fake records the delivery only).
        Mail::fake();
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);

        // Old key removed: everything retained reads with the new key alone.
        $this->useAppKey($new, []);
        $this->assertSame(self::MESSAGE, ContactSubmission::query()->findOrFail($submission->id)->message);
        $this->artisan('encryption:verify')->assertSuccessful();
    }

    public function test_a_rotation_does_not_touch_contact_metadata(): void
    {
        $old = (string) config('app.key');
        $submission = $this->seedUnderCurrentKey();
        $before = (array) DB::table('contact_submissions')->where('id', $submission->id)->first();

        $this->useAppKey($this->newAppKey(), [$old]);
        $this->artisan('contact:reencrypt')->assertSuccessful();

        $after = (array) DB::table('contact_submissions')->where('id', $submission->id)->first();

        foreach (['email', 'subject', 'ip_address', 'user_id', 'created_at'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column);
        }

        $this->assertNotSame($before['message'], $after['message']);
    }

    public function test_reencrypting_is_idempotent(): void
    {
        $old = (string) config('app.key');
        $new = $this->newAppKey();
        $first = $this->seedUnderCurrentKey();
        $second = ContactSubmission::create([
            'email' => 'other@example.com',
            'subject' => 'Another question',
            'message' => 'Another message',
            'ip_address' => '127.0.0.1',
        ]);

        $this->useAppKey($new, [$old]);
        $this->artisan('contact:reencrypt')->expectsOutputToContain('2 contact message(s) re-encrypted')->assertSuccessful();

        // A rerun — e.g. after an interrupted rotation — touches nothing new.
        $this->artisan('contact:reencrypt')->expectsOutputToContain('0 contact message(s) re-encrypted')->assertSuccessful();

        $this->useAppKey($new, []);
        $this->assertSame(self::MESSAGE, ContactSubmission::query()->findOrFail($first->id)->message);
        $this->assertSame('Another message', ContactSubmission::query()->findOrFail($second->id)->message);
        $this->artisan('encryption:verify')->assertSuccessful();
    }

    public function test_a_corrupted_contact_row_blocks_the_gate_without_being_deleted(): void
    {
        Log::spy();

        $old = (string) config('app.key');
        $new = $this->newAppKey();
        $good = $this->seedUnderCurrentKey();
        $corrupt = ContactSubmission::create([
            'email' => 'corrupt@example.com',
            'subject' => 'Corrupted',
            'message' => 'Will be corrupted',
            'ip_address' => '127.0.0.1',
        ]);

        $this->useAppKey($new, [$old]);
        DB::table('contact_submissions')->where('id', $corrupt->id)->update(['message' => 'not-a-payload']);

        // The corrupted row is reported by id and left untouched; the good
        // row is migrated anyway. The rotation must not be declared complete.
        $this->artisan('contact:reencrypt')
            ->expectsOutputToContain("contact_submissions #{$corrupt->id}")
            ->assertFailed();
        $this->assertSame('not-a-payload', $this->rawMessage($corrupt));
        $this->assertTrue($this->decryptsWith($new, $this->rawMessage($good)));

        $this->artisan('encryption:verify')
            ->expectsOutputToContain("contact_submissions #{$corrupt->id}")
            ->assertFailed();

        // No plaintext, no key material, ever reaches the logs.
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Will be corrupted'));
    }

    public function test_failed_jobs_keep_the_previous_key_until_resolved(): void
    {
        $old = (string) config('app.key');
        $new = $this->newAppKey();
        $this->seedUnderCurrentKey();
        $this->queueMails();

        // A queued delivery failed (e.g. after exhausting its retries) and was
        // parked in failed_jobs, still encrypted with the old key.
        $payload = DB::table('jobs')->where('queue', 'default')->orderBy('id')->first()->payload;
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->insert([
            'uuid' => json_decode($payload, true)['uuid'],
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $payload,
            'exception' => 'RuntimeException: SMTP 451 try later',
            'failed_at' => now(),
        ]);

        $this->useAppKey($new, [$old]);
        $this->artisan('contact:reencrypt')->assertSuccessful();

        // The failed job still depends on the old key: the gate refuses.
        $this->artisan('encryption:verify')->expectsOutputToContain('failed_jobs #')->assertFailed();

        // Retrying re-pushes the stored payload without re-encrypting it.
        $this->artisan('queue:retry', ['id' => [json_decode($payload, true)['uuid']]])->assertSuccessful();
        $this->artisan('encryption:verify')->expectsOutputToContain('jobs #')->assertFailed();

        // The retried job is drained through the real worker.
        Mail::fake();
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        $this->useAppKey($new, []);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->artisan('encryption:verify')->assertSuccessful();
    }

    public function test_2fa_secrets_are_part_of_the_gate(): void
    {
        $old = (string) config('app.key');
        $new = $this->newAppKey();
        $user = User::factory()->create([
            // Fortify stores the 2FA columns with the serialize-based
            // encrypt(), through no Eloquent cast.
            'two_factor_secret' => Crypt::encrypt('the-2fa-secret'),
            'two_factor_recovery_codes' => Crypt::encrypt(json_encode(['code-1'])),
        ]);

        $this->useAppKey($new, [$old]);
        $this->artisan('encryption:verify')->expectsOutputToContain("users #{$user->id}")->assertFailed();

        // The runbook's step-5 re-encryption one-liner, run verbatim.
        $user->forceFill([
            'two_factor_secret' => encrypt(decrypt($user->two_factor_secret)),
            'two_factor_recovery_codes' => $user->two_factor_recovery_codes === null ? null : encrypt(decrypt($user->two_factor_recovery_codes)),
        ])->saveQuietly();

        $this->useAppKey($new, []);
        $this->assertSame('the-2fa-secret', decrypt($user->fresh()->two_factor_secret));
        $this->assertSame(['code-1'], json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true));
        $this->artisan('encryption:verify')->assertSuccessful();
    }

    public function test_a_delayed_encrypted_job_blocks_the_gate_until_it_is_drained(): void
    {
        $old = (string) config('app.key');
        $new = $this->newAppKey();
        $submission = $this->seedUnderCurrentKey();

        // A delayed mail is parked in the jobs table, encrypted with the key
        // it was queued under — the gate must see it like any pending job.
        Mail::to('team@example.com')->later(now()->addHour(), new ContactMessage(
            contactEmail: 'jane@example.com',
            contactSubject: 'A question',
            contactMessage: self::MESSAGE,
        ));

        $this->useAppKey($new, [$old]);
        $this->artisan('contact:reencrypt')->assertSuccessful();
        $this->artisan('encryption:verify')->expectsOutputToContain('jobs #')->assertFailed();

        // Once the delay has passed, the scheduler's drain clears it.
        DB::table('jobs')->update(['available_at' => 0]);
        Mail::fake();
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        $this->useAppKey($new, []);
        $this->assertSame(self::MESSAGE, ContactSubmission::query()->findOrFail($submission->id)->message);
        $this->artisan('encryption:verify')->assertSuccessful();
    }

    public function test_plain_and_malformed_job_payloads_do_not_fail_the_gate(): void
    {
        $this->seedUnderCurrentKey();

        // A plain serialized job (no encryption) and a malformed row are not
        // encrypted payloads: the gate ignores them, and their own handling
        // is the worker's business — it must not block a rotation.
        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => json_encode([
                    'uuid' => 'plain-1',
                    'displayName' => 'stdClass',
                    'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                    'data' => ['commandName' => 'stdClass', 'command' => serialize(new \stdClass)],
                ]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => 0,
                'created_at' => now()->getTimestamp(),
            ],
            [
                'queue' => 'default',
                'payload' => 'not-json',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => 0,
                'created_at' => now()->getTimestamp(),
            ],
        ]);

        $this->artisan('encryption:verify')->assertSuccessful();
    }

    public function test_the_deploy_gate_passes_a_rotation_window_and_refuses_a_premature_removal(): void
    {
        $old = (string) config('app.key');
        $new = $this->newAppKey();
        $this->seedUnderCurrentKey();
        $this->queueMails();

        // Mid-rotation, with the previous key still configured: payloads that
        // are still on the old key do not block a deploy (--allow-previous-
        // keys is what the deploy runs), but the strict runbook gate does.
        $this->useAppKey($new, [$old]);
        $this->artisan('encryption:verify', ['--allow-previous-keys' => true])->assertSuccessful();
        $this->artisan('encryption:verify')->assertFailed();

        // Old key removed with the queue not yet drained: the deploy refuses.
        $this->useAppKey($new, []);
        $this->artisan('encryption:verify', ['--allow-previous-keys' => true])
            ->expectsOutputToContain('jobs #')
            ->assertFailed();

        // The operator restores the old key and follows the runbook: migrate
        // the contact messages, then drain the queue while the old key is
        // still configured (the worker must be able to resolve the payloads).
        $this->useAppKey($new, [$old]);
        $this->artisan('contact:reencrypt')->assertSuccessful();
        Mail::fake();
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        $this->useAppKey($new, []);
        $this->artisan('encryption:verify')->assertSuccessful();
        $this->artisan('encryption:verify', ['--allow-previous-keys' => true])->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function seedUnderCurrentKey(): ContactSubmission
    {
        return ContactSubmission::create([
            'email' => 'jane@example.com',
            'subject' => 'A question',
            'message' => self::MESSAGE,
            'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * Both real mailables, queued to the database with the current key.
     */
    private function queueMails(): void
    {
        Mail::to('team@example.com')->queue(new ContactMessage(
            contactEmail: 'jane@example.com',
            contactSubject: 'A question',
            contactMessage: self::MESSAGE,
        ));

        Mail::to('invitee@example.com')->queue(new ProfileInvite(
            inviterName: 'Owner',
            inviterEmail: 'owner@example.com',
            profileName: 'Family',
            role: 'collaborator',
            inviteUrl: 'https://prospera.test/invites/raw-key-never-stored',
            expiresAt: now()->addHours(48),
        ));
    }

    /**
     * Raw stored payload of a contact message.
     */
    private function rawMessage(ContactSubmission $submission): string
    {
        return (string) DB::table('contact_submissions')->where('id', $submission->id)->value('message');
    }

    /**
     * Whether a raw stored payload decrypts with the given key alone (the
     * encrypted-cast format: the plain value encrypted, no serialization).
     */
    private function decryptsWith(string $key, string $raw): bool
    {
        try {
            $this->freshEncrypter($key)->decryptString($raw);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A fresh encrypter for the given key alone.
     */
    private function freshEncrypter(string $key): Encrypter
    {
        return new Encrypter(
            Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:')) : $key,
            (string) config('app.cipher'),
        );
    }

    /**
     * A fresh app key, as Laravel's key:generate writes it.
     */
    private function newAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * @param  list<string>  $previousKeys
     */
    private function useAppKey(string $key, array $previousKeys = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        app(AmountKeyRing::class)->forget();
    }
}
