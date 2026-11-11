<?php

namespace Tests\Feature;

use App\Actions\DeleteAccount;
use App\Models\Budget;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * WF-11: unverified accounts can delete themselves, and stale empty ones are
 * pruned on a schedule.
 */
class UnverifiedAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unverified_user_can_delete_their_account(): void
    {
        $user = User::factory()->unverified()->create();
        FinancialProfile::createDefaultFor($user);

        $this->actingAs($user)
            ->delete('/settings/account', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertModelMissing($user);
    }

    public function test_an_unverified_user_still_needs_their_password_to_delete(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->delete('/settings/account', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->assertModelExists($user);
    }

    public function test_deleting_an_invite_registered_account_releases_its_reservation(): void
    {
        $owner = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($owner, 'Family');
        $user = User::factory()->unverified()->create(['email' => 'newbie@example.com']);
        $invite = FinancialProfileInvite::create([
            'profile_id' => $profile->id,
            'invite_key' => FinancialProfileInvite::keyHash('k'),
            'email' => 'newbie@example.com',
            'invited_by' => $owner->id,
            'pending_user_id' => $user->id,
            'expires_at' => now()->addHours(48),
        ]);

        // The invite registration has no profile until verification.
        $this->actingAs($user)
            ->delete('/settings/account', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertModelMissing($user);
        $this->assertNull($invite->fresh()?->pending_user_id);
        $this->assertNotNull(FinancialProfileInvite::contextFor('k'));
    }

    public function test_the_prune_deletes_stale_empty_unverified_accounts_only(): void
    {
        config(['prospera.unverified_account_retention_days' => 30]);

        $stale = User::factory()->unverified()->create();
        FinancialProfile::createDefaultFor($stale);
        $staleInvitee = User::factory()->unverified()->create();
        $verified = User::factory()->create();
        FinancialProfile::createDefaultFor($verified);

        // Unverified (e.g. changed email) but holding data: never pruned.
        $withData = User::factory()->unverified()->create();
        $withDataProfile = FinancialProfile::createDefaultFor($withData);
        Budget::create(['profile_id' => $withDataProfile->id, 'currency' => 'CAD', 'effective_year' => 0, 'effective_month' => 0]);
        $withEnvelope = User::factory()->unverified()->create();
        $withEnvelope->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#000000']);

        // Unverified but member of a shared profile: never pruned.
        $member = User::factory()->unverified()->create();
        FinancialProfile::query()->whereHas('members', fn ($q) => $q->whereKey($verified->id))->sole()
            ->members()->attach($member->id, ['is_owner' => false]);

        $this->travel(31)->days();

        $recent = User::factory()->unverified()->create();
        FinancialProfile::createDefaultFor($recent);

        $this->artisan('users:prune-unverified')
            ->expectsOutput('2 unverified account(s) deleted.')
            ->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelMissing($staleInvitee);
        $this->assertDatabaseMissing('financial_profile_user', ['user_id' => $stale->id]);
        $this->assertModelExists($recent);
        $this->assertModelExists($verified);
        $this->assertModelExists($withData);
        $this->assertModelExists($withEnvelope);
        $this->assertModelExists($member);
    }

    public function test_the_prune_re_checks_each_account_right_before_deleting_it(): void
    {
        config(['prospera.unverified_account_retention_days' => 30]);

        $verifiesMeanwhile = User::factory()->unverified()->create();
        $addsAnEnvelopeMeanwhile = User::factory()->unverified()->create();
        $stale = User::factory()->unverified()->create();
        $this->travel(31)->days();

        // RES-R2-4: both accounts act between the selection of the
        // candidates and their deletion.
        $touched = [];
        User::retrieved(function (User $user) use (&$touched, $verifiesMeanwhile, $addsAnEnvelopeMeanwhile): void {
            if (isset($touched[$user->id])) {
                return;
            }

            $touched[$user->id] = true;

            if ($user->id === $verifiesMeanwhile->id) {
                DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
            } elseif ($user->id === $addsAnEnvelopeMeanwhile->id) {
                DB::table('envelopes')->insert([
                    'user_id' => $user->id,
                    'name' => 'Rent',
                    'type' => 'expense',
                    'color' => '#000000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->artisan('users:prune-unverified')
            ->expectsOutput('1 unverified account(s) deleted.')
            ->assertSuccessful();

        $this->assertModelExists($verifiesMeanwhile);
        $this->assertModelExists($addsAnEnvelopeMeanwhile);
        $this->assertModelMissing($stale);
    }

    public function test_a_failing_account_does_not_abort_the_prune(): void
    {
        Exceptions::fake();
        config(['prospera.unverified_account_retention_days' => 30]);

        $failing = User::factory()->unverified()->create();
        FinancialProfile::createDefaultFor($failing);
        $other = User::factory()->unverified()->create();
        FinancialProfile::createDefaultFor($other);
        $this->travel(31)->days();

        $real = app(DeleteAccount::class);
        $this->app->instance(DeleteAccount::class, new class($real, $failing->id) extends DeleteAccount
        {
            public function __construct(private readonly DeleteAccount $real, private readonly int $failingId) {}

            public function handle(User $user): void
            {
                if ($user->id === $this->failingId) {
                    // Part of the deletion ran before the failure: it must
                    // be rolled back with the account's own transaction.
                    $user->profiles()->sole()->delete();

                    throw new RuntimeException('Transient database error');
                }

                $this->real->handle($user);
            }
        });

        $this->artisan('users:prune-unverified')
            ->expectsOutput('1 unverified account(s) deleted.')
            ->assertSuccessful();

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Transient database error');
        $this->assertModelExists($failing);
        $this->assertSame(1, $failing->profiles()->count());
        $this->assertModelMissing($other);
    }

    public function test_the_retention_window_is_configurable(): void
    {
        config(['prospera.unverified_account_retention_days' => 7]);

        $user = User::factory()->unverified()->create();
        $this->travel(8)->days();

        $this->artisan('users:prune-unverified')->assertSuccessful();

        $this->assertModelMissing($user);
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn (Event $event): bool => str_contains((string) $event->command, 'users:prune-unverified')
                && $event->expression === '0 0 * * *');

        $this->assertTrue($scheduled);
    }
}
