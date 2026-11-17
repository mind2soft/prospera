<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\DeleteAccount;
use App\Exceptions\FinancialLockSetChanged;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountKeyManager;
use App\Security\Amounts\AmountKeyRing;
use App\Services\AccountDeletionLocks;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FinancialLockProtocolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FinancialProfile $profile;

    private Envelope $envelope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->user, isDefault: true);
        $this->envelope = $this->user->envelopes()->create(['name' => 'Rent', 'type' => 'expense']);
    }

    private function ledger(?FinancialProfile $profile = null): Ledger
    {
        return Ledger::create([
            'profile_id' => ($profile ?? $this->profile)->id, 'name' => '2026',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'starting_balance' => 500,
        ]);
    }

    private function budget(?FinancialProfile $profile = null, bool $published = false): Budget
    {
        $budget = Budget::create([
            'profile_id' => ($profile ?? $this->profile)->id, 'currency' => 'CAD',
            'effective_year' => $published ? 2026 : 0, 'effective_month' => $published ? 3 : 0,
            'published_at' => $published ? now() : null,
        ]);
        $budget->permissions()->create(['user_id' => $this->user->id, 'permission' => 'owner']);

        return $budget;
    }

    /** @param list<string> $queries */
    private function position(array $queries, string $prefix): int
    {
        foreach ($queries as $index => $query) {
            if (str_starts_with($query, $prefix)) {
                return $index;
            }
        }
        $this->fail('Expected SQL prefix not found: '.$prefix);
    }

    public function test_ledger_lifecycle_locks_its_parent_before_deleting_transactions(): void
    {
        $ledger = $this->ledger();
        $ledger->transactions()->create(['envelope_id' => $this->envelope->id, 'amount' => -1, 'event_at' => '2026-03-01']);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $ledger->deleteWithTransactions();
        $this->assertLessThan($this->position($queries, 'delete from "transactions"'), $this->position($queries, 'select * from "ledgers"'));
        $this->assertModelMissing($ledger);
    }

    public function test_account_roots_precede_user_and_envelope_locks_and_use_global_ledger_order(): void
    {
        $second = FinancialProfile::createOwnedBy($this->user, 'Second');
        $firstLedger = $this->ledger($second);
        $secondLedger = $this->ledger();
        $this->budget();
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $this->assertFalse(app(DeleteAccount::class)->deleteIf($this->user, function (User $fresh) use (&$queries): bool {
            $profiles = $this->position($queries, 'select "id" from "financial_profiles"');
            $ledgers = $this->position($queries, 'select "id" from "ledgers"');
            $budgets = $this->position($queries, 'select "id" from "budgets"');
            $user = $this->position($queries, 'select * from "users"');
            $envelopes = $this->position($queries, 'select "id" from "envelopes"');
            $this->assertSame([$profiles, $ledgers, $budgets, $user, $envelopes], array_values(array_unique([$profiles, $ledgers, $budgets, $user, $envelopes])));
            $this->assertTrue($profiles < $ledgers && $ledgers < $budgets && $budgets < $user && $user < $envelopes);
            $this->assertStringContainsString('order by "id" asc', $queries[$ledgers]);
            $this->assertSame($this->user->id, $fresh->id);

            return false;
        }));
        $this->assertModelExists($firstLedger);
        $this->assertModelExists($secondLedger);
        $this->assertModelExists($this->user);
        $this->assertDatabaseCount('deleted_account_emails', 0);
    }

    public function test_root_plan_includes_external_draft_assignments_and_pending_transactions(): void
    {
        $otherUser = User::factory()->create();
        $assignedProfile = FinancialProfile::createOwnedBy($otherUser, 'Assignment');
        $transactionProfile = FinancialProfile::createOwnedBy($otherUser, 'Pending transaction');
        $budget = $this->budget($assignedProfile);
        $budget->envelopes()->create(['envelope_id' => $this->envelope->id, 'amount' => -1, 'type' => 'fixed']);
        $this->ledger($transactionProfile)->transactions()->create([
            'envelope_id' => $this->envelope->id, 'amount' => -1, 'event_at' => '2026-03-01',
        ])->delete();
        $roots = [];
        DB::listen(function (QueryExecuted $event) use (&$roots): void {
            if (str_starts_with($event->sql, 'select "id" from "financial_profiles"')) {
                $roots = $event->bindings;
            }
        });

        app(DeleteAccount::class)->deleteIf($this->user, fn (User $fresh): bool => false);
        $this->assertEqualsCanonicalizing([$this->profile->id, $assignedProfile->id, $transactionProfile->id], $roots);
    }

    public function test_expanding_reference_set_requires_rollback_instead_of_late_profile_locks(): void
    {
        $other = FinancialProfile::createOwnedBy(User::factory()->create());
        $budget = $this->budget($other);
        $injected = false;
        DB::connection()->beforeExecuting(function (string $query) use ($budget, &$injected): void {
            if (! $injected && str_starts_with($query, 'select "id" from "envelopes"')) {
                $injected = true;
                $budget->envelopes()->create(['envelope_id' => $this->envelope->id, 'amount' => -1, 'type' => 'fixed']);
            }
        });

        try {
            DB::transaction(fn () => app(AccountDeletionLocks::class)->lock($this->user->id));
            $this->fail('An expanded root set was accepted beneath envelope locks.');
        } catch (FinancialLockSetChanged) {
            $this->assertTrue($injected);
            $this->assertSame(0, $budget->envelopes()->count()); // whole attempt rolled back
            $this->assertModelExists($this->user);
        }
    }

    public function test_account_lock_set_retry_restarts_before_any_deletion_effects(): void
    {
        $attempts = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempts): void {
            if (str_starts_with($query, 'select "id" from "financial_profiles"') && ++$attempts === 1) {
                throw new FinancialLockSetChanged;
            }
        });
        app(DeleteAccount::class)->handle($this->user);
        $this->assertSame(2, $attempts);
        $this->assertModelMissing($this->user);
        $this->assertDatabaseCount('deleted_account_emails', 1);
    }

    public function test_deletion_uses_fresh_account_metadata_not_a_stale_model(): void
    {
        $email = $this->user->email;
        $this->user->email = 'stale-unpersisted@example.com';
        app(DeleteAccount::class)->handle($this->user);
        $this->assertDatabaseHas('deleted_account_emails', ['email' => $email]);
        $this->assertDatabaseMissing('deleted_account_emails', ['email' => 'stale-unpersisted@example.com']);
    }

    public function test_exhausted_lock_set_retries_leave_the_account_and_resources_untouched(): void
    {
        $ledger = $this->ledger();
        $attempts = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempts): void {
            if (str_starts_with($query, 'select "id" from "financial_profiles"')) {
                $attempts++;
                throw new FinancialLockSetChanged;
            }
        });
        try {
            app(DeleteAccount::class)->handle($this->user);
            $this->fail('Deletion proceeded without a stable lock set.');
        } catch (FinancialLockSetChanged) {
            $this->assertSame(3, $attempts);
            $this->assertModelExists($this->user);
            $this->assertModelExists($ledger);
            $this->assertDatabaseCount('deleted_account_emails', 0);
        }
    }

    public function test_prune_eligibility_rechecks_new_budget_data_inside_the_stable_root_interval(): void
    {
        $stale = User::factory()->unverified()->create();
        $profile = FinancialProfile::createDefaultFor($stale);
        $this->travel(31)->days();
        $injected = false;
        User::retrieved(function (User $user) use ($stale, $profile, &$injected): void {
            if (! $injected && $user->id === $stale->id) {
                $injected = true;
                Budget::create(['profile_id' => $profile->id, 'currency' => 'CAD', 'effective_year' => 0, 'effective_month' => 0]);
            }
        });
        $this->artisan('users:prune-unverified')->expectsOutput('0 unverified account(s) deleted.')->assertSuccessful();
        $this->assertTrue($injected);
        $this->assertModelExists($stale);
        $this->assertSame(1, Budget::query()->where('profile_id', $profile->id)->count());
    }

    public function test_profile_ownership_and_membership_mutators_lock_the_profile_before_children(): void
    {
        $member = User::factory()->create();
        $this->profile->members()->attach($member->id, ['is_owner' => false]);
        $draft = $this->budget();
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->profile->transferOwnershipTo($member);
        $this->assertLessThan($this->position($queries, 'update "financial_profile_user"'), $this->position($queries, 'select * from "financial_profiles"'));
        $this->assertSame('owner', $draft->permissionFor($member));

        $queries = [];
        $this->profile->removeMember($this->user);
        $this->assertLessThan($this->position($queries, 'select "id" from "envelopes"'), $this->position($queries, 'select * from "financial_profiles"'));
        $this->assertFalse($this->profile->isMember($this->user));
    }

    public function test_transfer_authorization_is_rechecked_after_acquiring_the_profile_root(): void
    {
        $member = User::factory()->create();
        $third = User::factory()->create();
        $this->profile->members()->attach([$member->id => ['is_owner' => false], $third->id => ['is_owner' => false]]);
        $changed = false;
        $reads = 0;
        DB::connection()->beforeExecuting(function (string $query) use ($member, &$changed, &$reads): void {
            if (str_starts_with($query, 'select * from "financial_profiles"') && ++$reads === 2) {
                $changed = true;
                DB::table('financial_profile_user')->where('profile_id', $this->profile->id)->update(['is_owner' => false]);
                DB::table('financial_profile_user')->where('profile_id', $this->profile->id)->where('user_id', $member->id)->update(['is_owner' => true]);
            }
        });
        $this->actingAs($this->user)->postJson("/api/profiles/{$this->profile->id}/transfer-ownership", ['user_id' => $third->id])->assertForbidden();
        $this->assertTrue($changed);
    }

    public function test_prune_does_not_take_a_user_first_transaction_before_the_deletion_action(): void
    {
        $user = User::factory()->unverified()->create();
        FinancialProfile::createDefaultFor($user);
        $this->travel(31)->days();
        $level = DB::transactionLevel();
        $action = new class($level) extends DeleteAccount
        {
            public int $calls = 0;

            public function __construct(private readonly int $level) {}

            public function deleteIf(User $user, Closure $eligible): bool
            {
                Assert::assertSame($this->level, DB::transactionLevel());
                $this->calls++;

                return parent::deleteIf($user, $eligible);
            }
        };
        $this->app->instance(DeleteAccount::class, $action);
        $this->artisan('users:prune-unverified')->assertSuccessful();
        $this->assertSame(1, $action->calls);
        $this->assertModelMissing($user);
    }

    public function test_profile_key_rotation_prelocks_ledgers_then_budgets_before_mutating_keys(): void
    {
        $this->ledger();
        $this->budget();
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        app(AmountKeyManager::class)->rotate($this->profile->id);
        $ledger = $this->position($queries, 'select "id" from "ledgers"');
        $budget = $this->position($queries, 'select "id" from "budgets"');
        $keys = $this->position($queries, 'update "financial_profile_keys"');
        $this->assertTrue($ledger < $budget && $budget < $keys);
    }

    /** @return iterable<string, array{string}> */
    public static function draftOperations(): iterable
    {
        foreach (['add', 'update', 'remove'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('draftOperations')]
    public function test_draft_assignment_changes_reauthorize_fresh_locked_ownership(string $operation): void
    {
        $budget = $this->budget();
        $next = User::factory()->create();
        $this->profile->members()->attach($next->id, ['is_owner' => false]);
        $assignment = $operation === 'add' ? null : $budget->envelopes()->create([
            'envelope_id' => $this->envelope->id, 'amount' => -10, 'type' => 'fixed',
        ]);
        $before = $assignment?->fresh()->getRawOriginal();
        $reads = 0;
        $changed = false;
        DB::connection()->beforeExecuting(function (string $query) use ($next, &$reads, &$changed): void {
            if (! $changed && str_starts_with($query, 'select * from "budgets"') && ++$reads === 2) {
                $changed = true;
                $this->profile->transferOwnershipTo($next);
            }
        });
        $this->actingAs($this->user);
        $url = "/api/budgets/{$budget->id}/envelopes";
        $response = match ($operation) {
            'add' => $this->postJson($url, ['envelope_id' => $this->envelope->id, 'amount' => 99, 'type' => 'fixed']),
            'update' => $this->patchJson($url."/{$this->envelope->id}", ['amount' => 99, 'expected_revision' => 1]),
            default => $this->deleteJson($url."/{$this->envelope->id}"),
        };
        $response->assertForbidden();
        $this->assertTrue($changed);
        $this->assertSame($assignment === null ? 0 : 1, $budget->envelopes()->count());
        if ($assignment !== null) {
            $this->assertSame($before, $assignment->fresh()->getRawOriginal());
        }
    }

    public function test_invite_replacement_reauthorizes_fresh_profile_before_touching_previous_invites(): void
    {
        $next = User::factory()->create();
        $this->profile->members()->attach($next->id, ['is_owner' => false]);
        $invite = FinancialProfileInvite::create([
            'profile_id' => $this->profile->id, 'invited_by' => $this->user->id,
            'email' => 'invitee@example.com', 'invite_key' => FinancialProfileInvite::keyHash('previous'),
            'expires_at' => now()->addDay(),
        ]);
        $reads = 0;
        $changed = false;
        DB::connection()->beforeExecuting(function (string $query) use ($next, &$reads, &$changed): void {
            if (! $changed && str_starts_with($query, 'select * from "financial_profiles"') && ++$reads === 2) {
                $changed = true;
                $this->profile->transferOwnershipTo($next);
            }
        });
        $this->actingAs($this->user)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'invitee@example.com',
        ])->assertForbidden();
        $this->assertTrue($changed);
        $this->assertModelExists($invite);
        $this->assertSame($invite->invite_key, $invite->fresh()->invite_key);
    }

    /** @return iterable<string, array{string}> */
    public static function budgetOperations(): iterable
    {
        foreach (['store', 'clone', 'publish'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('budgetOperations')]
    public function test_budget_operations_reauthorize_against_fresh_locked_ownership(string $operation): void
    {
        $budget = $this->budget(published: $operation !== 'publish');
        $next = User::factory()->create();
        $this->profile->members()->attach($next->id, ['is_owner' => false]);
        $reads = 0;
        $changed = false;
        DB::connection()->beforeExecuting(function (string $query) use ($next, &$reads, &$changed): void {
            if (! $changed && str_starts_with($query, 'select * from "financial_profiles"') && ++$reads === 2) {
                $changed = true;
                $this->profile->transferOwnershipTo($next);
            }
        });
        $url = $operation === 'store' ? '/api/budgets' : "/api/budgets/{$budget->id}/{$operation}";
        $payload = $operation === 'store' ? ['profile_id' => $this->profile->id, 'currency' => 'CAD'] : [];
        $this->actingAs($this->user)->postJson($url, $payload)->assertForbidden();
        $this->assertTrue($changed);
        $this->assertSame(1, Budget::query()->where('profile_id', $this->profile->id)->count());
        $this->assertSame($operation !== 'publish', $budget->fresh()->published_at !== null);
    }

    public function test_pending_completion_locks_profiles_before_user_and_invite_and_refreshes_verification(): void
    {
        $invitee = User::factory()->create();
        $invite = FinancialProfileInvite::create([
            'profile_id' => $this->profile->id, 'invited_by' => $this->user->id,
            'email' => $invitee->email, 'invite_key' => FinancialProfileInvite::keyHash('reserved'),
            'pending_user_id' => $invitee->id, 'expires_at' => now()->addDay(),
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        FinancialProfileInvite::completePendingFor($invitee);
        $profile = $this->position($queries, 'select "id" from "financial_profiles"');
        $user = $this->position($queries, 'select * from "users"');
        $child = $this->position($queries, 'select * from "financial_profile_invites"');
        $this->assertTrue($profile < $user && $user < $child);
        $this->assertTrue($this->profile->isMember($invitee));
        $this->assertModelMissing($invite);

        $unverified = User::factory()->unverified()->create();
        $pending = FinancialProfileInvite::create([
            'profile_id' => $this->profile->id, 'invited_by' => $this->user->id,
            'email' => $unverified->email, 'invite_key' => FinancialProfileInvite::keyHash('unverified'),
            'pending_user_id' => $unverified->id, 'expires_at' => now()->addDay(),
        ]);
        FinancialProfileInvite::completePendingFor($unverified);
        $this->assertFalse($this->profile->isMember($unverified));
        $this->assertModelExists($pending);
    }

    /** @return iterable<string, array{string}> */
    public static function moneyWriters(): iterable
    {
        foreach (['transaction', 'ledger', 'assignment', 'clone'] as $writer) {
            yield $writer => [$writer];
        }
    }

    #[DataProvider('moneyWriters')]
    public function test_writers_refresh_active_key_material_after_their_serialization_lock(string $writer): void
    {
        foreach (['Salary' => 'revenue', 'Food' => 'expense', 'Other' => 'expense'] as $name => $type) {
            $this->user->envelopes()->create(compact('name', 'type'));
        }
        $ledger = $this->ledger();
        $ledger->permissions()->create(['user_id' => $this->user->id, 'permission' => 'owner']);
        $budget = $this->budget(published: $writer === 'clone');
        $assignment = $budget->envelopes()->create(['envelope_id' => $this->envelope->id, 'amount' => -100, 'type' => 'fixed']);
        $stale = new AmountKeyRing(new AmountCipher);
        $stale->activeKey($this->profile->id);
        $manager = app(AmountKeyManager::class);
        $manager->rotate($this->profile->id);
        $this->travel(25)->hours();
        $manager->prune($this->profile->id, now()->subHours(24));
        $this->app->instance(AmountKeyRing::class, $stale);
        $this->actingAs($this->user);

        $row = match ($writer) {
            'transaction' => $this->postJson("/api/ledgers/{$ledger->id}/transactions", [
                'envelope_id' => $this->envelope->id, 'amount' => 1.25, 'event_at' => '2026-03-01',
            ])->assertCreated()->json('transaction.id'),
            'ledger' => $this->postJson('/api/ledgers', [
                'profile_id' => $this->profile->id, 'name' => 'New', 'start_date' => '2026-01-01',
                'end_date' => '2026-12-31', 'starting_balance' => 100, 'starting_balance_type' => 'cash',
            ])->assertCreated()->json('ledger.id'),
            'assignment' => $this->patchJson("/api/budgets/{$budget->id}/envelopes/{$this->envelope->id}", [
                'amount' => 99, 'expected_revision' => 1,
            ])->assertOk()->json('envelope.id'),
            default => $this->postJson("/api/budgets/{$budget->id}/clone", [])->assertCreated()->json('budget.id'),
        };

        $payload = match ($writer) {
            'transaction' => Transaction::query()->findOrFail($row)->getRawOriginal('amount'),
            'ledger' => Ledger::query()->findOrFail($row)->getRawOriginal('starting_balance'),
            'assignment' => $assignment->fresh()->getRawOriginal('amount'),
            default => BudgetEnvelope::query()->where('budget_id', $row)->sole()->getRawOriginal('amount'),
        };
        $this->assertIsString($payload);
        $this->assertSame(2, AmountCipher::keyVersionOf($payload));
    }
}
