<?php

namespace Tests\Feature;

use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private FinancialProfile $profile;

    private Envelope $gas;

    private Envelope $groceries;

    private Envelope $paycheck;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);

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
    }

    /**
     * A widget configuration as sent by the client.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function widgetPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'envelope_spending',
            'title' => null,
            'view_type' => 'single',
            'column_width' => 3,
            'period' => ['type' => 'months', 'range' => 3],
            'selection' => [
                ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
            ],
        ], $overrides);
    }

    public function test_guests_cannot_access_the_dashboard_api(): void
    {
        $this->getJson("/api/dashboard/{$this->profile->id}/widgets")->assertUnauthorized();
        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", ['widgets' => []])->assertUnauthorized();
    }

    public function test_non_members_get_a_404(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        $this->getJson("/api/dashboard/{$this->profile->id}/widgets")->assertNotFound();
    }

    public function test_the_dashboard_starts_empty(): void
    {
        $this->actingAs($this->owner);

        $this->getJson("/api/dashboard/{$this->profile->id}/widgets")
            ->assertOk()
            ->assertJson(['widgets' => []]);
    }

    public function test_a_layout_can_be_saved_and_is_returned_in_order(): void
    {
        $this->actingAs($this->owner);

        $response = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [
                $this->widgetPayload(),
                $this->widgetPayload([
                    'type' => 'radial',
                    'view_type' => 'donut',
                    'column_width' => 2,
                    'period' => ['type' => 'days', 'range' => 7],
                    'selection' => [
                        ['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->gas->id, $this->paycheck->id]],
                    ],
                ]),
            ],
        ]);

        $response->assertOk();

        $widgets = $response->json('widgets');

        $this->assertCount(2, $widgets);
        $this->assertSame(0, $widgets[0]['order']);
        $this->assertSame(1, $widgets[1]['order']);
        $this->assertSame('radial', $widgets[1]['type']);
        $this->assertSame('donut', $widgets[1]['view_type']);
        $this->assertSame(2, $widgets[1]['column_width']);
        $this->assertSame(['type' => 'days', 'range' => 7], $widgets[1]['period']);
        $this->assertSame(
            [['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->gas->id, $this->paycheck->id]]],
            $widgets[1]['selection'],
        );

        $this->assertDatabaseCount('dashboard_widgets', 2);
    }

    public function test_saving_replaces_the_previous_layout(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload(), $this->widgetPayload()],
        ])->assertOk();

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload(['type' => 'income_expenses', 'view_type' => 'line'])],
        ])->assertOk();

        $this->assertDatabaseCount('dashboard_widgets', 1);
        $this->assertDatabaseHas('dashboard_widgets', ['type' => 'income_expenses', 'view_type' => 'line']);
    }

    public function test_saving_keeps_the_ids_of_existing_widgets_stable(): void
    {
        $this->actingAs($this->owner);

        $id = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload(), $this->widgetPayload()],
        ])->json('widgets.0.id');

        // A later save that updates the first widget and drops the second
        // keeps the first widget's id (in-flight data requests keep
        // resolving) and removes only the dropped one.
        $response = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [
                $this->widgetPayload(['title' => 'Updated']) + ['id' => $id],
            ],
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'widgets')
            ->assertJsonPath('widgets.0.id', $id)
            ->assertJsonPath('widgets.0.title', 'Updated');

        $this->assertDatabaseCount('dashboard_widgets', 1);
    }

    public function test_a_foreign_widget_id_is_ignored(): void
    {
        $this->actingAs($this->owner);

        // A widget of another user is not adoptable via a guessed id — the
        // payload entry is created as a new widget of the caller instead.
        $stranger = User::factory()->create();
        $strangerWidget = DashboardWidget::create([
            'user_id' => $stranger->id,
            'financial_profile_id' => FinancialProfile::createOwnedBy($stranger, 'X')->id,
            'type' => 'radial',
            'title' => null,
            'view_type' => 'pie',
            'column_width' => 1,
            'period_type' => 'days',
            'period_range' => 1,
            'selection' => [],
            'sort_order' => 0,
        ]);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload() + ['id' => $strangerWidget->id]],
        ])->assertOk();

        // The stranger's widget is untouched; a fresh row was created.
        $this->assertDatabaseHas('dashboard_widgets', [
            'id' => $strangerWidget->id,
            'user_id' => $stranger->id,
        ]);

        $this->assertDatabaseCount('dashboard_widgets', 2);
    }

    public function test_each_member_manages_their_own_dashboard(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload()],
        ])->assertOk();

        // The member has their own (empty) dashboard and deleting the
        // owner's widget is a 404 — dashboards are per member.
        $this->actingAs($this->member);

        $this->getJson("/api/dashboard/{$this->profile->id}/widgets")
            ->assertOk()
            ->assertJson(['widgets' => []]);

        $widgetId = DashboardWidget::query()->sole()->id;

        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}")->assertNotFound();

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload(['type' => 'radial', 'view_type' => 'pie'])],
        ])->assertOk();

        // The owner's widget is untouched by the member's save.
        $this->actingAs($this->owner);

        $this->getJson("/api/dashboard/{$this->profile->id}/widgets")
            ->assertOk()
            ->assertJsonCount(1, 'widgets');
    }

    public function test_a_widget_can_be_removed(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload()],
        ])->assertOk();

        $widgetId = DashboardWidget::query()->sole()->id;

        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}")->assertNoContent();

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    public function test_titles_are_trimmed_and_blank_titles_become_null(): void
    {
        $this->actingAs($this->owner);

        $response = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [
                $this->widgetPayload(['title' => '  Spending  ']),
                $this->widgetPayload(['type' => 'radial', 'view_type' => 'pie', 'title' => '   ']),
            ],
        ]);

        $response->assertOk();

        $widgets = $response->json('widgets');

        $this->assertSame('Spending', $widgets[0]['title']);
        $this->assertNull($widgets[1]['title']);
    }

    public function test_invalid_widget_configurations_are_rejected(): void
    {
        $this->actingAs($this->owner);

        $cases = [
            'unknown type' => ['type' => 'goals'],
            'view type does not match the widget type' => ['view_type' => 'donut'],
            'view type unknown for charts' => [
                'type' => 'income_expenses', 'view_type' => 'pie',
            ],
            'column width below one' => ['column_width' => 0],
            'column width above five' => ['column_width' => 6],
            'unknown period type' => ['period' => ['type' => 'weeks', 'range' => 2]],
            'range not in the canonical days enumeration' => ['period' => ['type' => 'days', 'range' => 5]],
            'range not in the canonical months enumeration' => ['period' => ['type' => 'months', 'range' => 4]],
            'empty selection kind' => ['selection' => [['kind' => 'goal']]],
        ];

        foreach ($cases as $case => $overrides) {
            $response = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
                'widgets' => [$this->widgetPayload($overrides)],
            ]);

            $response->assertUnprocessable();

            $this->assertDatabaseCount('dashboard_widgets', 0);
        }
    }

    public function test_selections_may_not_reference_envelopes_the_caller_cannot_see(): void
    {
        $stranger = User::factory()->create();

        $hidden = $stranger->envelopes()->create([
            'name' => 'Secret',
            'type' => 'expense',
            'color' => '#000000',
        ]);

        $this->actingAs($this->member);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload([
                'selection' => [['kind' => 'envelope', 'envelope_id' => $hidden->id]],
            ])],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    public function test_selections_may_not_use_an_envelope_twice(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload([
                'selection' => [
                    ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
                    ['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->gas->id, $this->paycheck->id]],
                ],
            ])],
        ])->assertUnprocessable();

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload([
                'selection' => [
                    ['kind' => 'group', 'name' => 'A', 'envelope_ids' => [$this->gas->id, $this->groceries->id]],
                    ['kind' => 'group', 'name' => 'B', 'envelope_ids' => [$this->gas->id]],
                ],
            ])],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    public function test_groups_need_a_name_and_at_least_one_envelope(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload([
                'selection' => [['kind' => 'group', 'name' => '   ', 'envelope_ids' => [$this->gas->id]]],
            ])],
        ])->assertUnprocessable();

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload([
                'selection' => [['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => []]],
            ])],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    public function test_deleted_envelopes_are_pruned_from_selections_on_read(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload([
                'selection' => [
                    ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
                    ['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->groceries->id, $this->paycheck->id]],
                ],
            ])],
        ])->assertOk();

        $this->groceries->delete();

        $widgets = $this->getJson("/api/dashboard/{$this->profile->id}/widgets")
            ->assertOk()
            ->json('widgets');

        $this->assertSame(
            [
                ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
                ['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->paycheck->id]],
            ],
            $widgets[0]['selection'],
        );
    }
}
