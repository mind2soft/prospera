<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Envelope;
use App\Models\EnvelopeAmountKey;
use App\Models\EnvelopeSchedule;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EnvelopeScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Envelope $envelope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($this->owner, isDefault: true);
        $profile->members()->attach($this->member->id, ['is_owner' => false, 'is_default' => true]);
        $this->envelope = $this->owner->envelopes()->create(['name' => 'Rent', 'type' => 'expense']);
        $this->travelTo(now()->setDate(2026, 3, 1)->startOfDay());
    }

    private function url(?EnvelopeSchedule $schedule = null, ?Envelope $envelope = null): string
    {
        return '/api/envelopes/'.($envelope ?? $this->envelope)->id.'/schedules'.($schedule === null ? '' : '/'.$schedule->id);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['period' => 'monthly', 'start_date' => '2026-03-01', 'day_of_month' => 31, 'amount' => 12345];
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(array $attributes = []): EnvelopeSchedule
    {
        return $this->envelope->schedules()->create($attributes + ['amount' => -12345, 'period' => 'monthly', 'start_date' => '2026-03-01', 'day_of_month' => 31]);
    }

    public function test_owner_crud_keeps_amounts_encrypted_and_soft_deletes_definitions(): void
    {
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonCount(0, 'schedules');
        $response = $this->postJson($this->url(), $this->payload())->assertCreated()
            ->assertJsonPath('schedule.amount', -12345)
            ->assertJsonPath('schedule.next_occurrences', ['2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31']);
        $schedule = EnvelopeSchedule::query()->findOrFail($response->json('schedule.id'));
        $this->assertTrue(AmountCipher::looksEncrypted($schedule->getRawOriginal('amount')));
        $this->assertSame(-12345, $schedule->fresh()?->amount);
        $this->assertSame(1, EnvelopeAmountKey::query()->count());

        $this->patchJson($this->url($schedule), ['amount' => 25000, 'reminder_days' => 0])->assertOk()
            ->assertJsonPath('schedule.amount', -25000)->assertJsonPath('schedule.reminder_days', 0);
        $this->getJson($this->url())->assertOk()->assertJsonPath('schedules.0.amount', -25000)->assertJsonPath('meta.total', 1);
        $this->deleteJson($this->url($schedule))->assertNoContent();
        $this->assertSoftDeleted($schedule);
        $this->getJson($this->url())->assertJsonCount(0, 'schedules');
        $this->patchJson($this->url($schedule), ['amount' => 1])->assertNotFound();
        $this->deleteJson($this->url($schedule))->assertNotFound();
        $this->assertSame(-25000, EnvelopeSchedule::withTrashed()->findOrFail($schedule->id)->amount);
        $this->assertSame(0, DB::table('transactions')->count());
    }

    public function test_amount_is_a_minor_unit_magnitude_signed_from_the_envelope(): void
    {
        $this->envelope->update(['type' => 'revenue']);
        $this->actingAs($this->owner)->postJson($this->url(), $this->payload())->assertCreated()->assertJsonPath('schedule.amount', 12345);
        $this->postJson($this->url(), array_replace($this->payload(), ['amount' => 0]))->assertCreated()->assertJsonPath('schedule.amount', 0);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function validDefinitions(): iterable
    {
        yield 'once' => [['period' => 'once']];
        yield 'weekly' => [['period' => 'weekly', 'weekdays' => [1, 4]]];
        yield 'biweekly' => [['period' => 'biweekly', 'weekdays' => [7]]];
        yield 'monthly' => [['period' => 'monthly', 'day_of_month' => 31]];
        yield 'yearly' => [['period' => 'yearly', 'day_of_month' => 29, 'month' => 2]];
    }

    /** @param array<string, mixed> $definition */
    #[DataProvider('validDefinitions')]
    public function test_all_periods_can_be_created_with_upcoming_previews(array $definition): void
    {
        $response = $this->actingAs($this->owner)->postJson($this->url(), $definition + ['amount' => 1, 'start_date' => '2026-03-01'])
            ->assertCreated()->assertJsonPath('schedule.period', $definition['period']);
        $this->assertNotEmpty($response->json('schedule.next_occurrences'));
        $this->assertLessThanOrEqual(5, count($response->json('schedule.next_occurrences')));
        $this->assertArrayNotHasKey('wrapped_key', $response->json('schedule'));
    }

    public function test_a_new_envelope_owner_gains_access_and_the_former_owner_loses_it(): void
    {
        $schedule = $this->schedule();
        $this->envelope->transferOwnershipTo($this->member);
        $this->actingAs($this->owner)->getJson($this->url())->assertForbidden();
        $this->actingAs($this->member)->getJson($this->url())->assertOk()->assertJsonPath('schedules.0.amount', -12345);
        $this->patchJson($this->url($schedule), ['amount' => 100])->assertOk()->assertJsonPath('schedule.amount', -100);
    }

    public function test_an_unauthorized_caller_cannot_trigger_decryption_of_a_corrupt_amount(): void
    {
        $schedule = $this->schedule();
        DB::table('envelope_schedules')->where('id', $schedule->id)->update(['amount' => 'corrupted-secret-marker']);
        $this->actingAs($this->member)->getJson($this->url())->assertForbidden()->assertDontSee('corrupted-secret-marker');
        config(['app.debug' => false]);
        $this->actingAs($this->owner)->getJson($this->url())->assertServerError()->assertDontSee('corrupted-secret-marker');
    }

    /** @return iterable<string, array{string}> */
    public static function permissions(): iterable
    {
        foreach (['none', 'viewer', 'contributor'] as $permission) {
            yield $permission => [$permission];
        }
    }

    #[DataProvider('permissions')]
    public function test_co_members_cannot_read_or_mutate_schedules_even_with_envelope_grants(string $permission): void
    {
        if ($permission !== 'none') {
            $this->envelope->permissions()->create(['user_id' => $this->member->id, 'permission' => $permission]);
        }
        $schedule = $this->schedule();
        $this->actingAs($this->member)->getJson($this->url())->assertForbidden()->assertDontSee('12345');
        $this->postJson($this->url(), [])->assertForbidden();
        $this->patchJson($this->url($schedule), ['amount' => -1])->assertForbidden();
        $this->deleteJson($this->url($schedule))->assertForbidden();
        $this->assertSame(-12345, $schedule->fresh()?->amount);
        $this->assertSame(1, EnvelopeSchedule::query()->count());
    }

    public function test_strangers_and_stale_grantees_get_404_without_validation_or_amount_disclosure(): void
    {
        $stranger = User::factory()->create();
        $this->envelope->permissions()->create(['user_id' => $stranger->id, 'permission' => 'contributor']);
        $schedule = $this->schedule();
        $this->actingAs($stranger)->getJson($this->url())->assertNotFound()->assertDontSee('12345');
        $this->postJson($this->url(), [])->assertNotFound();
        $this->patchJson($this->url($schedule), ['period' => 'invalid'])->assertNotFound();
        $this->deleteJson($this->url($schedule))->assertNotFound();
    }

    public function test_a_member_of_a_referencing_profile_without_owner_co_membership_still_gets_403(): void
    {
        $other = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($other);
        $profile->members()->attach($this->member->id, ['is_owner' => false]);
        $ownerProfile = $this->owner->profiles()->firstOrFail();
        $ownerProfile->members()->detach($this->member->id);
        $budget = Budget::create(['profile_id' => $profile->id, 'currency' => 'CAD', 'effective_year' => 2026, 'effective_month' => 3]);
        $budget->envelopes()->create(['envelope_id' => $this->envelope->id, 'amount' => -100, 'type' => 'fixed']);
        $this->actingAs($this->member)->getJson($this->url())->assertForbidden();
    }

    public function test_nested_binding_rejects_another_envelopes_schedule(): void
    {
        $schedule = $this->schedule();
        $other = $this->owner->envelopes()->create(['name' => 'Other', 'type' => 'expense']);
        $this->actingAs($this->owner)->patchJson($this->url($schedule, $other), ['amount' => 1])->assertNotFound();
        $this->deleteJson($this->url($schedule, $other))->assertNotFound();
        $this->assertSame(-12345, $schedule->fresh()?->amount);
    }

    public function test_guests_and_unverified_accounts_cannot_use_the_api(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url(), $this->payload())->assertUnauthorized();
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->owner)->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->assertSame(0, EnvelopeAmountKey::query()->count());
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_fail_without_persisting_data(array $change, string $field): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), array_replace($this->payload(), $change))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(0, EnvelopeSchedule::withTrashed()->count());
        $this->assertSame(0, EnvelopeAmountKey::query()->count());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'unknown period' => [['period' => 'daily'], 'period'];
        yield 'null period' => [['period' => null], 'period'];
        foreach ([-1, 1.5, '12345', true, 100000000000, 'pa1.1.fake'] as $index => $amount) {
            yield "amount $index" => [['amount' => $amount], 'amount'];
        }
        foreach (['2026-02-29', '0000-01-01', 'tomorrow', '2026-03-01T00:00:00Z'] as $date) {
            yield "start $date" => [['start_date' => $date], 'start_date'];
        }
        yield 'end before start' => [['end_date' => '2026-02-28'], 'end_date'];
        yield 'invalid end' => [['end_date' => '2026-04-31'], 'end_date'];
        yield 'monthly day missing' => [['day_of_month' => null], 'day_of_month'];
        yield 'fractional day' => [['day_of_month' => 1.5], 'day_of_month'];
        yield 'yearly month missing' => [['period' => 'yearly'], 'month'];
        yield 'invalid month' => [['period' => 'yearly', 'month' => 13], 'month'];
        yield 'once with irrelevant day' => [['period' => 'once'], 'day_of_month'];
        yield 'monthly irrelevant month' => [['month' => 1], 'month'];
        yield 'monthly irrelevant weekdays' => [['weekdays' => [1]], 'weekdays'];
        yield 'weekly missing weekdays' => [['period' => 'weekly', 'day_of_month' => null], 'weekdays'];
        yield 'weekly invalid weekday' => [['period' => 'weekly', 'day_of_month' => null, 'weekdays' => [0]], 'weekdays.0'];
        yield 'weekly duplicate weekdays' => [['period' => 'weekly', 'day_of_month' => null, 'weekdays' => [1, 1]], 'weekdays.0'];
        yield 'weekly numeric strings' => [['period' => 'weekly', 'day_of_month' => null, 'weekdays' => ['1']], 'weekdays.0'];
        yield 'weekday object, not list' => [['weekdays' => ['monday' => 1]], 'weekdays'];
        yield 'negative reminder' => [['reminder_days' => -1], 'reminder_days'];
        yield 'oversized reminder' => [['reminder_days' => 367], 'reminder_days'];
        foreach (['id', 'envelope_id', 'profile_id', 'user_id', 'deleted_at', 'created_at', 'updated_at'] as $field) {
            yield "reserved $field" => [[$field => null], $field];
        }
        yield 'invalid timezone' => [['timezone' => 'Not/AZone'], 'timezone'];
    }

    public function test_create_requires_the_core_fields(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'period', 'start_date']);
    }

    public function test_partial_edits_preserve_other_fields_and_rebind_date_changes_explicitly(): void
    {
        $schedule = $this->schedule(['end_date' => '2026-12-31', 'reminder_days' => 3]);
        $raw = $schedule->getRawOriginal('amount');
        $this->actingAs($this->owner)->patchJson($this->url($schedule), ['reminder_days' => null])->assertOk()
            ->assertJsonPath('schedule.reminder_days', null)->assertJsonPath('schedule.end_date', '2026-12-31');
        $this->assertSame($raw, $schedule->fresh()?->getRawOriginal('amount'));
        $this->patchJson($this->url($schedule), ['start_date' => '2026-04-01'])->assertOk()->assertJsonPath('schedule.amount', -12345);
        $this->assertNotSame($raw, $schedule->fresh()?->getRawOriginal('amount'));
        $this->patchJson($this->url($schedule), ['start_date' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->patchJson($this->url($schedule), [])->assertOk();
    }

    public function test_period_changes_clear_only_omitted_irrelevant_fields(): void
    {
        $schedule = $this->schedule(['period' => 'yearly', 'month' => 3]);
        $this->actingAs($this->owner)->patchJson($this->url($schedule), ['period' => 'weekly', 'weekdays' => [4, 1]])
            ->assertOk()->assertJsonPath('schedule.weekdays', [1, 4])->assertJsonPath('schedule.day_of_month', null)->assertJsonPath('schedule.month', null);
        $this->patchJson($this->url($schedule), ['period' => 'once', 'weekdays' => [1]])->assertUnprocessable()->assertJsonValidationErrors('weekdays');
        $this->patchJson($this->url($schedule), ['period' => 'monthly'])->assertUnprocessable()->assertJsonValidationErrors('day_of_month');
        $this->patchJson($this->url($schedule), ['period' => 'once'])->assertOk()->assertJsonPath('schedule.weekdays', []);
    }

    public function test_a_partial_patch_uses_fresh_definition_fields_after_acquiring_the_owner_lock(): void
    {
        $schedule = $this->schedule(['reminder_days' => 3]);
        $reads = 0;
        $changed = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$reads, &$changed, $schedule): void {
            if (str_starts_with($query, 'select * from "envelopes"') && ++$reads === 2) {
                $changed = true;
                DB::table('envelope_schedules')->where('id', $schedule->id)->update(['reminder_days' => 7]);
            }
        });
        $this->actingAs($this->owner)->patchJson($this->url($schedule), ['day_of_month' => 15])->assertOk()
            ->assertJsonPath('schedule.reminder_days', 7)->assertJsonPath('schedule.day_of_month', 15);
        $this->assertTrue($changed);
    }

    public function test_pagination_is_bounded_and_excludes_deleted_and_other_envelope_rows(): void
    {
        $first = $this->schedule();
        $second = $this->schedule();
        $this->schedule()->delete();
        $other = $this->owner->envelopes()->create(['name' => 'Other', 'type' => 'expense']);
        $other->schedules()->create(['period' => 'once', 'start_date' => '2026-03-01', 'amount' => -1]);
        $this->actingAs($this->owner)->getJson($this->url().'?per_page=1')->assertOk()
            ->assertJsonCount(1, 'schedules')->assertJsonPath('schedules.0.id', $first->id)->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson($this->url().'?per_page=1&page=2')->assertOk()->assertJsonPath('schedules.0.id', $second->id);
        $this->getJson($this->url().'?per_page=101')->assertUnprocessable();
        $this->getJson($this->url().'?page=0')->assertUnprocessable();
        $this->getJson($this->url().'?timezone=bad')->assertUnprocessable();
    }

    public function test_preview_uses_the_requesting_owners_calendar_not_the_server_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-02 02:00:00', 'UTC'));
        $this->schedule(['period' => 'once', 'day_of_month' => null]);
        $this->actingAs($this->owner)->getJson($this->url().'?timezone=America/Toronto')->assertOk()
            ->assertJsonPath('schedules.0.next_occurrences', ['2026-03-01']);
        $this->getJson($this->url().'?timezone=UTC')->assertOk()->assertJsonPath('schedules.0.next_occurrences', []);
    }

    public function test_french_date_validation_and_permission_errors_are_localized(): void
    {
        $this->owner->update(['locale' => 'fr']);
        $this->actingAs($this->owner)->postJson($this->url(), array_replace($this->payload(), ['start_date' => '0000-01-01']))
            ->assertUnprocessable()->assertJsonPath('errors.start_date.0', "La date de l'opération planifiée doit être une date valide au format AAAA-MM-JJ.");
        $this->member->update(['locale' => 'fr']);
        $this->actingAs($this->member)->getJson($this->url())->assertForbidden()->assertJsonPath('message', "Vous n'avez pas l'autorisation d'effectuer cette action.");
    }

    public function test_authorization_is_rechecked_after_a_transfer_before_the_owner_lock(): void
    {
        $schedule = $this->schedule();
        $reads = 0;
        $transferred = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$reads, &$transferred): void {
            if (str_starts_with($query, 'select * from "envelopes"') && ++$reads === 2) {
                $transferred = true;
                DB::table('envelopes')->where('id', $this->envelope->id)->update(['user_id' => $this->member->id]);
            }
        });
        $this->actingAs($this->owner)->patchJson($this->url($schedule), ['amount' => 1])->assertForbidden();
        $this->assertTrue($transferred);
        $this->assertSame(-12345, $schedule->fresh()?->amount);
    }

    public function test_the_index_migration_is_reversible_without_data_loss(): void
    {
        $schedule = $this->schedule();
        $migration = require database_path('migrations/2026_11_17_000002_index_envelope_schedules_for_owner_queries.php');
        $this->assertTrue(Schema::hasIndex('envelope_schedules', ['envelope_id', 'id']));
        $migration->down();
        $this->assertFalse(Schema::hasIndex('envelope_schedules', ['envelope_id', 'id']));
        $migration->up();
        $this->assertSame(-12345, $schedule->fresh()?->amount);
    }
}
