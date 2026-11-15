<?php

namespace Tests\Feature;

use App\Models\DeletedAccountEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class DeletedAccountEmailBlacklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_account_blacklists_its_email_with_the_account_dates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $blacklisted = DeletedAccountEmail::query()->sole();

        $this->assertTrue(DeletedAccountEmail::sameEmail($blacklisted->email, $user->email));
        $this->assertTrue($blacklisted->account_created_at?->equalTo($user->created_at));
        $this->assertNotNull($blacklisted->account_deleted_at);
    }

    public function test_a_blacklisted_email_cannot_register_a_new_account(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $deleted = User::factory()->create();
        $this->actingAs($deleted)
            ->delete(route('profile.destroy'), ['password' => 'password']);

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => $deleted->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => $deleted->email]);

        // The message must not reveal that an account previously existed
        // for the address (issue #71 review): it stays generic.
        $this->assertSame(
            'This email address is invalid.',
            session('errors')->first('email'),
        );
    }

    public function test_the_blacklist_matches_emails_case_insensitively(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $deleted = User::factory()->create(['email' => 'Deleted.User@Example.com']);
        $this->actingAs($deleted)
            ->delete(route('profile.destroy'), ['password' => 'password']);

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'deleted.user@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_email_that_was_never_deleted_can_still_register(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_a_blacklisted_email_cannot_become_another_accounts_email(): void
    {
        $deleted = User::factory()->create();
        $this->actingAs($deleted)
            ->delete(route('profile.destroy'), ['password' => 'password']);

        $user = User::factory()->create(['email' => 'other@example.com']);

        $response = $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $deleted->email,
                'locale' => $user->locale,
            ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame('other@example.com', $user->refresh()->email);
        $this->assertSame(
            'This email address is invalid.',
            session('errors')->first('email'),
        );
    }

    public function test_the_prune_of_unverified_accounts_blacklists_their_emails(): void
    {
        config(['prospera.unverified_account_retention_days' => 30]);

        $stale = User::factory()->unverified()->create();
        $this->travel(31)->days();

        $this->artisan('users:prune-unverified')->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertDatabaseHas('deleted_account_emails', ['email' => $stale->email]);
    }
}
