<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CountsQueries;
use Tests\TestCase;

class DashboardDataTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private FinancialProfile $profile;

    private FinancialProfile $otherProfile;

    private Ledger $ledger;

    private Envelope $gas;

    private Envelope $groceries;

    private Envelope $paycheck;

    private Envelope $entertainment;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic "today": 2026-11-10. Every period/window assertion
        // below is written against this date.
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);

        // A second profile of the owner: transactions recorded in its
        // ledgers must never leak into the selected profile's aggregates.
        $this->otherProfile = FinancialProfile::createOwnedBy($this->owner, 'Business');
        $otherLedger = Ledger::create([
            'profile_id' => $this->otherProfile->id,
            'name' => 'Business ledger',
            'start_date' => '2025-01-01',
            'end_date' => '2027-12-31',
            'starting_balance' => 0,
        ]);

        $this->gas = $this->owner->envelopes()->create([
            'name' => 'Gas',
            'type' => 'expense',
            'color' => '#ef4444',
        ]);

        $this->groceries = $this->owner->envelopes()->create([
            'name' => 'Groceries',
            'type' => 'expense',
            'color' => '#22c55e',
        ]);

        $this->paycheck = $this->owner->envelopes()->create([
            'name' => 'Paycheck',
            'type' => 'revenue',
            'color' => '#3b82f6',
        ]);

        // Not allocated on any budget — the progress bar stays empty.
        $this->entertainment = $this->owner->envelopes()->create([
            'name' => 'Entertainment',
            'type' => 'expense',
            'color' => '#a855f7',
        ]);

        $this->ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => 'Main ledger',
            'start_date' => '2025-01-01',
            'end_date' => '2027-12-31',
            'starting_balance' => 0,
        ]);

        // Inside the periods: gas $120 today, gas $80 three days ago,
        // groceries $543 two days ago, paycheck $2500 today,
        // entertainment $50 today.
        $this->record($this->gas, -120, Carbon::parse('2026-11-10'));
        $this->record($this->gas, -80, Carbon::parse('2026-11-07'));
        $this->record($this->groceries, -543, Carbon::parse('2026-11-08'));
        $this->record($this->paycheck, 2500, Carbon::parse('2026-11-10'));
        $this->record($this->entertainment, -50, Carbon::parse('2026-11-10'));

        // Outside every widget period (the longest spans 12 months, back to
        // 2025-12-01):
        $this->record($this->gas, -999, Carbon::parse('2025-10-21'));

        // In the other profile's ledger — must not count.
        $otherLedger->transactions()->create([
            'user_id' => $this->owner->id,
            'envelope_id' => $this->gas->id,
            'amount' => -777,
            'event_at' => Carbon::parse('2026-11-10 00:00:00'),
        ]);

        // Published budgets for the current and the previous month; gas is
        // only allocated on the current month's budget.
        $this->publishedBudget(Carbon::parse('2026-11-01'), [
            [$this->gas, -200],
            [$this->groceries, -600],
            [$this->paycheck, 2000],
        ]);

        $this->publishedBudget(Carbon::parse('2026-10-01'), [
            [$this->groceries, -600],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function record(Envelope $envelope, int $amount, Carbon $date): void
    {
        $this->ledger->transactions()->create([
            'user_id' => $this->owner->id,
            'envelope_id' => $envelope->id,
            'amount' => $amount,
            'event_at' => $date->startOfDay(),
        ]);
    }

    /**
     * @param  list<array{0: Envelope, 1: int}>  $allocations
     */
    private function publishedBudget(Carbon $month, array $allocations): Budget
    {
        $budget = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => $month->year,
            'effective_month' => $month->month,
            'published_at' => now(),
        ]);

        foreach ($allocations as [$envelope, $amount]) {
            $budget->envelopes()->create([
                'envelope_id' => $envelope->id,
                'amount' => $amount,
                'type' => 'fixed',
            ]);
        }

        return $budget;
    }

    /**
     * @param  array{type: 'days'|'months', range: int}  $period
     * @param  list<array<string, mixed>>  $selection
     */
    private function createWidget(string $type, string $viewType, array $period, array $selection, ?User $user = null): DashboardWidget
    {
        return DashboardWidget::create([
            'user_id' => ($user ?? $this->owner)->id,
            'financial_profile_id' => $this->profile->id,
            'type' => $type,
            'title' => null,
            'view_type' => $viewType,
            'column_width' => 3,
            'period_type' => $period['type'],
            'period_range' => $period['range'],
            'selection' => $selection,
            'sort_order' => 0,
        ]);
    }

    private function dataUrl(DashboardWidget $widget): string
    {
        return "/api/dashboard/{$this->profile->id}/widgets/{$widget->id}/data";
    }

    public function test_today_is_the_requesters_calendar_day(): void
    {
        // 2026-11-10 20:00 UTC is already 2026-11-11 in Auckland (UTC+13).
        Carbon::setTestNow(Carbon::parse('2026-11-10 20:00:00', 'UTC'));
        $this->record($this->gas, -33, Carbon::parse('2026-11-11'));

        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'days', 'range' => 1], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
        ]);

        // Server day (UTC): the 10th.
        $this->actingAs($this->owner)->getJson($this->dataUrl($widget))
            ->assertOk()->assertJsonPath('rows.0.spent', -120);

        $this->actingAs($this->owner)->getJson($this->dataUrl($widget).'?timezone=Pacific/Auckland')
            ->assertOk()->assertJsonPath('rows.0.spent', -33);

        $history = $this->createWidget('income_expenses', 'line', ['type' => 'days', 'range' => 1], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
        ]);

        $this->actingAs($this->owner)->getJson($this->dataUrl($history).'?timezone=Pacific/Auckland')
            ->assertOk()
            ->assertJsonPath('series.0.bucket', '2026-11-11')
            ->assertJsonPath('series.0.expenses', -33);

        $this->actingAs($this->owner)->getJson($this->dataUrl($widget).'?timezone=Nowhere/Land')
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
    }

    // ---- Envelope spending ----

    public function test_envelope_spending_sums_transactions_and_allocations(): void
    {
        // Months=2 covers October and November: groceries is allocated on
        // both budgets (-600 each), gas only on November's.
        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'months', 'range' => 2], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'envelope', 'envelope_id' => $this->groceries->id],
            ['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->paycheck->id]],
        ]);

        $this->actingAs($this->owner)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJson([
                'type' => 'envelope_spending',
                'rows' => [
                    [
                        'kind' => 'envelope',
                        'envelope_id' => $this->gas->id,
                        'name' => 'Gas',
                        'direction' => 'expense',
                        'masked' => false,
                        'spent' => -200,
                        'allocated' => -200,
                    ],
                    [
                        'kind' => 'envelope',
                        'envelope_id' => $this->groceries->id,
                        'masked' => false,
                        'spent' => -543,
                        'allocated' => -1200, // sum across the two covered months
                    ],
                    [
                        'kind' => 'group',
                        'name' => 'Combine',
                        'masked' => false,
                        'spent' => 2500,
                        'allocated' => 2000,
                    ],
                ],
            ]);
    }

    public function test_envelope_spending_reports_no_allocation_without_a_covering_budget(): void
    {
        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'months', 'range' => 2], [
            ['kind' => 'envelope', 'envelope_id' => $this->entertainment->id],
        ]);

        // The spent total still shows; the progress bar is empty.
        $this->actingAs($this->owner)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJsonPath('rows.0.spent', -50)
            ->assertJsonPath('rows.0.allocated', null);
    }

    public function test_envelope_spending_masks_rows_for_envelopes_without_amount_permission(): void
    {
        // The member's own widget selecting the owner's envelopes (visible
        // as metadata via profile membership).
        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'months', 'range' => 1], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->paycheck->id, $this->groceries->id]],
        ], $this->member);

        $payload = $this->actingAs($this->member)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->json();

        // The profile member has no envelope shares — every row is masked,
        // including the whole group (sums never leak member amounts).
        $this->assertTrue($payload['rows'][0]['masked']);
        $this->assertNull($payload['rows'][0]['spent']);
        $this->assertNull($payload['rows'][0]['allocated']);
        $this->assertSame('Gas', $payload['rows'][0]['name']); // metadata stays

        $this->assertTrue($payload['rows'][1]['masked']);
        $this->assertNull($payload['rows'][1]['spent']);

        // The owner's own (identical) widget shows the amounts.
        $ownWidget = $this->createWidget('envelope_spending', 'single', ['type' => 'months', 'range' => 1], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->paycheck->id, $this->groceries->id]],
        ]);

        $this->actingAs($this->owner)
            ->getJson($this->dataUrl($ownWidget))
            ->assertOk()
            ->assertJsonPath('rows.1.masked', false)
            ->assertJsonPath('rows.1.spent', 1957);
    }

    // ---- Income & expenses history ----

    public function test_income_expenses_buckets_per_day_for_days_periods(): void
    {
        $widget = $this->createWidget('income_expenses', 'stacked_bars', ['type' => 'days', 'range' => 7], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'envelope', 'envelope_id' => $this->paycheck->id],
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson($this->dataUrl($widget))
            ->assertOk();

        $series = $response->json('series');

        self::assertIsArray($series);
        $this->assertCount(7, $series);
        $this->assertSame(false, $response->json('masked'));
        $this->assertSame('income_expenses', $response->json('type'));

        $byBucket = [];

        foreach ($series as $bucket) {
            $byBucket[$bucket['bucket']] = $bucket;
        }

        $this->assertSame(-120, $byBucket['2026-11-10']['expenses']);
        $this->assertSame(2500, $byBucket['2026-11-10']['revenue']);
        $this->assertSame(-80, $byBucket['2026-11-07']['expenses']);
        $this->assertSame(0, $byBucket['2026-11-09']['revenue']);
    }

    public function test_income_expenses_buckets_per_month_for_months_periods(): void
    {
        $widget = $this->createWidget('income_expenses', 'line', ['type' => 'months', 'range' => 2], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
        ]);

        $payload = $this->actingAs($this->owner)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->json();

        $this->assertCount(2, $payload['series']);
        $this->assertSame('2026-10', $payload['series'][0]['bucket']);
        $this->assertSame('2026-11', $payload['series'][1]['bucket']);
        $this->assertSame(-200, $payload['series'][1]['expenses']);
    }

    public function test_income_expenses_masks_the_whole_series_for_masked_envelopes(): void
    {
        $widget = $this->createWidget('income_expenses', 'stacked_bars', ['type' => 'days', 'range' => 3], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'envelope', 'envelope_id' => $this->paycheck->id],
        ], $this->member);

        $payload = $this->actingAs($this->member)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->json();

        $this->assertTrue($payload['masked']);

        foreach ($payload['series'] as $bucket) {
            $this->assertTrue($bucket['masked']);
            $this->assertNull($bucket['revenue']);
            $this->assertNull($bucket['expenses']);
        }
    }

    // ---- Radial ----

    public function test_radial_reports_absolute_amounts(): void
    {
        $widget = $this->createWidget('radial', 'pie', ['type' => 'days', 'range' => 14], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'group', 'name' => 'Income', 'envelope_ids' => [$this->paycheck->id]],
        ]);

        $this->actingAs($this->owner)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJson([
                'type' => 'radial',
                'rows' => [
                    ['kind' => 'envelope', 'name' => 'Gas', 'masked' => false, 'value' => 200],
                    ['kind' => 'group', 'name' => 'Income', 'masked' => false, 'value' => 2500],
                ],
            ]);
    }

    public function test_radial_masks_rows_without_amount_permission(): void
    {
        $widget = $this->createWidget('radial', 'donut', ['type' => 'days', 'range' => 14], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
        ], $this->member);

        $this->actingAs($this->member)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJson([
                'rows' => [
                    ['kind' => 'envelope', 'name' => 'Gas', 'masked' => true, 'value' => null],
                ],
            ]);
    }

    // ---- Masking, history and scoping edge cases ----

    public function test_revoking_a_share_masks_the_widget_after_the_fact(): void
    {
        // The member's widget was built while they had viewer access to the
        // gas envelope; the share is revoked afterwards — the aggregate must
        // mask on read (permissions are recomputed per request).
        $this->gas->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'days', 'range' => 14], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
        ], $this->member);

        $this->actingAs($this->member)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJsonPath('rows.0.masked', false)
            ->assertJsonPath('rows.0.spent', -200);

        $this->gas->permissions()->where('user_id', $this->member->id)->first()->delete();

        $this->actingAs($this->member)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJsonPath('rows.0.masked', true)
            ->assertJsonPath('rows.0.spent', null);
    }

    public function test_a_partially_masked_group_is_masked_as_a_whole(): void
    {
        // The member can amount-see the gas envelope but not the paycheck —
        // the group summing both must be masked even though one member is
        // visible (sums never leak member amounts by omission).
        $this->gas->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'days', 'range' => 14], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'group', 'name' => 'Mix', 'envelope_ids' => [$this->gas->id, $this->paycheck->id]],
        ], $this->member);

        $this->actingAs($this->member)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJsonPath('rows.0.masked', false)
            ->assertJsonPath('rows.0.spent', -200)
            ->assertJsonPath('rows.1.masked', true)
            ->assertJsonPath('rows.1.spent', null);
    }

    public function test_closed_ledgers_still_count_toward_the_history(): void
    {
        // History is history: a closed ledger's transactions are part of the
        // profile's aggregates.
        $closed = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => 'Old ledger',
            'start_date' => '2025-01-01',
            'end_date' => '2027-12-31',
            'starting_balance' => 0,
            'closed_at' => now(),
        ]);

        $closed->transactions()->create([
            'user_id' => $this->owner->id,
            'envelope_id' => $this->gas->id,
            'amount' => -33,
            'event_at' => Carbon::parse('2026-11-09 00:00:00'),
        ]);

        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'days', 'range' => 14], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
        ]);

        $this->actingAs($this->owner)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJsonPath('rows.0.spent', -233); // -200 (open) - 33 (closed)
    }

    // ---- Scoping, pruning and ownership ----

    public function test_only_the_owning_user_can_read_a_widgets_data(): void
    {
        $widget = $this->createWidget('radial', 'pie', ['type' => 'days', 'range' => 7], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
        ]);

        // A profile member has their own dashboard — the owner's widget
        // data is not theirs to read.
        $this->actingAs($this->member)->getJson($this->dataUrl($widget))->assertNotFound();

        // A stranger is not even a member.
        $this->actingAs(User::factory()->create())->getJson($this->dataUrl($widget))->assertNotFound();
    }

    public function test_empty_selections_render_an_empty_payload(): void
    {
        $widget = $this->createWidget('income_expenses', 'stacked_bars', ['type' => 'days', 'range' => 3], []);

        $payload = $this->actingAs($this->owner)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->json();

        $this->assertFalse($payload['masked']);
        $this->assertCount(3, $payload['series']);

        $spending = $this->createWidget('envelope_spending', 'single', ['type' => 'days', 'range' => 3], []);

        $this->actingAs($this->owner)
            ->getJson($this->dataUrl($spending))
            ->assertOk()
            ->assertJson(['type' => 'envelope_spending', 'rows' => []]);
    }

    public function test_widget_data_queries_do_not_grow_with_ledgers_budgets_or_envelopes(): void
    {
        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'months', 'range' => 12], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'group', 'name' => 'Food', 'envelope_ids' => [$this->groceries->id]],
        ]);
        $this->actingAs($this->owner);

        $before = count($this->queriesDuring(fn () => $this->getJson($this->dataUrl($widget))->assertOk()));

        // More ledgers (each a profile lookup when decrypting), more covering
        // budgets, more selected envelopes shared with share rows.
        foreach (range(1, 4) as $i) {
            $ledger = Ledger::create([
                'profile_id' => $this->profile->id,
                'name' => "Ledger {$i}",
                'start_date' => '2025-01-01',
                'end_date' => '2027-12-31',
                'starting_balance' => 0,
            ]);
            $ledger->transactions()->create([
                'user_id' => $this->owner->id,
                'envelope_id' => $this->gas->id,
                'amount' => -$i,
                'event_at' => Carbon::parse('2026-11-0'.$i),
            ]);
            $this->publishedBudget(Carbon::parse('2026-0'.($i + 4).'-01'), [[$this->gas, -10]]);
        }

        $widget->update(['selection' => [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'group', 'name' => 'Food', 'envelope_ids' => [$this->groceries->id, $this->entertainment->id]],
            ['kind' => 'envelope', 'envelope_id' => $this->paycheck->id],
        ]]);

        $queries = $this->queriesDuring(fn () => $this->getJson($this->dataUrl($widget))->assertOk()
            ->assertJsonPath('rows.0.spent', -210)
            ->assertJsonPath('rows.0.allocated', -240));

        $this->assertCount($before, $queries);
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "envelopes"')));
    }

    public function test_deleted_envelopes_disappear_from_the_data(): void
    {
        // An envelope without transactions (transactions restrict envelope
        // deletion) referenced by a group.
        $cancelled = $this->owner->envelopes()->create([
            'name' => 'Cancelled',
            'type' => 'expense',
            'color' => '#64748b',
        ]);

        $widget = $this->createWidget('envelope_spending', 'single', ['type' => 'days', 'range' => 14], [
            ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ['kind' => 'group', 'name' => 'Fun', 'envelope_ids' => [$cancelled->id]],
        ]);

        $cancelled->delete();

        $this->actingAs($this->owner)
            ->getJson($this->dataUrl($widget))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.envelope_id', $this->gas->id);
    }
}
