<?php

namespace Tests\Feature;

use App\Models\DashboardLayout;
use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CountsQueries;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use CountsQueries;
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

        // A layout that was never written presents its initial revision
        // (issue #55): a first save edits against it.
        $this->getJson("/api/dashboard/{$this->profile->id}/widgets")
            ->assertOk()
            ->assertJson(['widgets' => [], 'revision' => 1]);
    }

    public function test_a_layout_can_be_saved_and_is_returned_in_order(): void
    {
        $this->actingAs($this->owner);

        $response = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
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

        // The write advanced the layout revision exactly once.
        $this->assertSame(2, $response->json('revision'));

        $this->assertDatabaseCount('dashboard_widgets', 2);
    }

    public function test_saving_replaces_the_previous_layout(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload(), $this->widgetPayload()],
        ])->assertOk();

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 2,
            'widgets' => [$this->widgetPayload(['type' => 'income_expenses', 'view_type' => 'line'])],
        ])->assertOk();

        $this->assertDatabaseCount('dashboard_widgets', 1);
        $this->assertDatabaseHas('dashboard_widgets', ['type' => 'income_expenses', 'view_type' => 'line']);
    }

    public function test_saving_keeps_the_ids_of_existing_widgets_stable(): void
    {
        $this->actingAs($this->owner);

        $id = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload(), $this->widgetPayload()],
        ])->json('widgets.0.id');

        // A later save that updates the first widget and drops the second
        // keeps the first widget's id (in-flight data requests keep
        // resolving) and removes only the dropped one.
        $response = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 2,
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

    public function test_a_foreign_widget_id_is_rejected(): void
    {
        $this->actingAs($this->owner);

        // A widget of another user is not adoptable via a guessed id: an
        // unresolvable id is refused (issue #55) — recreating it as a new
        // widget of the caller would resurrect removed state, and a lookup
        // in another member's layout must never happen.
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
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload() + ['id' => $strangerWidget->id]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('widgets');

        // The stranger's widget is untouched, and nothing was created.
        $this->assertDatabaseHas('dashboard_widgets', [
            'id' => $strangerWidget->id,
            'user_id' => $stranger->id,
        ]);

        $this->assertDatabaseCount('dashboard_widgets', 1);
    }

    public function test_each_member_manages_their_own_dashboard(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload()],
        ])->assertOk();

        // The member has their own (empty) dashboard with its own revision,
        // and deleting the owner's widget is a 404 — dashboards are per
        // member.
        $this->actingAs($this->member);

        $this->getJson("/api/dashboard/{$this->profile->id}/widgets")
            ->assertOk()
            ->assertJson(['widgets' => [], 'revision' => 1]);

        $widgetId = DashboardWidget::query()->sole()->id;

        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}")->assertNotFound();

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
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
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload()],
        ])->assertOk();

        $widgetId = DashboardWidget::query()->sole()->id;

        // The removal is a layout write: it carries the expected revision
        // and returns the new one (issue #55).
        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}?expected_revision=2")
            ->assertOk()
            ->assertJson(['revision' => 3]);

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    public function test_titles_are_trimmed_and_blank_titles_become_null(): void
    {
        $this->actingAs($this->owner);

        $response = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
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
                'expected_revision' => 1,
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
            'expected_revision' => 1,
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
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload([
                'selection' => [
                    ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
                    ['kind' => 'group', 'name' => 'Combine', 'envelope_ids' => [$this->gas->id, $this->paycheck->id]],
                ],
            ])],
        ])->assertUnprocessable();

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
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
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload([
                'selection' => [['kind' => 'group', 'name' => '   ', 'envelope_ids' => [$this->gas->id]]],
            ])],
        ])->assertUnprocessable();

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
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
            'expected_revision' => 1,
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

    /**
     * Two writers editing against the same revision (two tabs, or a stale
     * autosave racing a newer save): the first writer wins, the stale one
     * changes nothing (issue #55).
     */
    public function test_a_save_refuses_a_stale_revision(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload()],
        ])->assertOk()->assertJsonPath('revision', 2);

        // The second writer still edits against revision 1: refused with the
        // current revision, and the saved layout is untouched.
        $response = $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload(['type' => 'radial', 'view_type' => 'pie'])],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('revision', 2);

        $this->assertDatabaseCount('dashboard_widgets', 1);
        $this->assertDatabaseHas('dashboard_widgets', ['type' => 'envelope_spending']);
    }

    public function test_the_save_requires_the_revision_the_edit_was_made_against(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'widgets' => [$this->widgetPayload()],
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 0,
            'widgets' => [$this->widgetPayload()],
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 'two',
            'widgets' => [$this->widgetPayload()],
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    /**
     * A create whose response was lost must not create a second copy when
     * the client retries the same save (issue #55).
     */
    public function test_replaying_a_successful_save_does_not_duplicate_it(): void
    {
        $this->actingAs($this->owner);

        $payload = [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload()],
        ];

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", $payload)
            ->assertOk()
            ->assertJsonPath('revision', 2);

        // The retry (same payload, same revision) cannot apply twice: the
        // revision has moved on, the replay is refused.
        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", $payload)
            ->assertStatus(409);

        $this->assertDatabaseCount('dashboard_widgets', 1);
    }

    /**
     * Two initial saves of an empty layout: exactly one may win — the row
     * that arbitrates exists even for a layout with zero widgets (issue #55).
     */
    public function test_two_initial_saves_of_an_empty_layout_cannot_both_win(): void
    {
        $this->actingAs($this->owner);

        $payload = ['expected_revision' => 1, 'widgets' => []];

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", $payload)
            ->assertOk()
            ->assertJsonPath('revision', 2);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", $payload)
            ->assertStatus(409);

        $this->assertDatabaseCount('dashboard_widgets', 0);

        // The layout row exists once and carries the advanced revision.
        $this->assertSame(1, DashboardLayout::query()->count());
        $this->assertSame(2, DashboardLayout::query()->sole()->revision);
    }

    /**
     * A PUT captured before a DELETE cannot resurrect the removed widget
     * once the delete has landed (issue #55).
     */
    public function test_a_late_save_cannot_resurrect_a_deleted_widget(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload(), $this->widgetPayload()],
        ])->assertOk();

        $widgetId = DashboardWidget::query()->first()->id;

        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}?expected_revision=2")
            ->assertOk()
            ->assertJsonPath('revision', 3);

        // The stale PUT still references the deleted widget id and the
        // revision it was captured against: refused on the revision — the
        // widget is not recreated.
        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 2,
            'widgets' => [
                $this->widgetPayload(['title' => 'Resurrected']) + ['id' => $widgetId],
            ],
        ])->assertStatus(409);

        $this->assertDatabaseCount('dashboard_widgets', 1);
        $this->assertDatabaseMissing('dashboard_widgets', ['id' => $widgetId]);

        // Even a full save without the id cannot sneak past the stale
        // revision — the removal stands.
        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 2,
            'widgets' => [$this->widgetPayload(['title' => 'Fresh'])],
        ])->assertStatus(409);

        $this->assertDatabaseCount('dashboard_widgets', 1);
    }

    public function test_a_save_referencing_a_widget_deleted_elsewhere_is_rejected(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload()],
        ])->assertOk();

        $widgetId = DashboardWidget::query()->sole()->id;

        // Removed from another device (the revision advanced to 3).
        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}?expected_revision=2")
            ->assertOk();

        // The client's next autosave still carries the deleted id: refused
        // — it must reconcile with the current layout instead of silently
        // recreating the widget under a new identity (issue #55).
        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 3,
            'widgets' => [
                $this->widgetPayload(['title' => 'Back?']) + ['id' => $widgetId],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('widgets');

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    public function test_a_widget_removal_refuses_a_stale_revision(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload()],
        ])->assertOk();

        $widgetId = DashboardWidget::query()->sole()->id;

        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}?expected_revision=1")
            ->assertStatus(409)
            ->assertJsonPath('revision', 2);

        $this->assertDatabaseCount('dashboard_widgets', 1);
    }

    /**
     * Removing the last widget is a layout write like any other: the
     * revision advances even though the widget list ends up empty (issue
     * #55), and the empty layout presents its revision on read.
     */
    public function test_removing_the_last_widget_still_advances_the_revision(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload()],
        ])->assertOk();

        $widgetId = DashboardWidget::query()->sole()->id;

        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}?expected_revision=2")
            ->assertOk()
            ->assertJsonPath('revision', 3);

        $this->getJson("/api/dashboard/{$this->profile->id}/widgets")
            ->assertOk()
            ->assertJson(['widgets' => [], 'revision' => 3]);
    }

    public function test_a_widget_removal_requires_the_expected_revision(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("/api/dashboard/{$this->profile->id}/widgets", [
            'expected_revision' => 1,
            'widgets' => [$this->widgetPayload()],
        ])->assertOk();

        $widgetId = DashboardWidget::query()->sole()->id;

        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_revision');

        $this->deleteJson("/api/dashboard/{$this->profile->id}/widgets/{$widgetId}?expected_revision=0")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_revision');

        $this->assertDatabaseCount('dashboard_widgets', 1);
    }

    public function test_listing_widgets_runs_a_constant_number_of_queries(): void
    {
        $url = "/api/dashboard/{$this->profile->id}/widgets";
        $this->actingAs($this->owner);

        $this->actingAs($this->owner)->putJson($url, ['expected_revision' => 1, 'widgets' => [$this->widgetPayload()]])->assertOk();
        $one = count($this->queriesDuring(fn () => $this->getJson($url)->assertOk()->assertJsonCount(1, 'widgets')));

        $this->putJson($url, ['expected_revision' => 2, 'widgets' => array_map(fn (int $i): array => $this->widgetPayload([
            'selection' => [
                ['kind' => 'envelope', 'envelope_id' => $this->gas->id],
                ['kind' => 'group', 'name' => "Group {$i}", 'envelope_ids' => [$this->groceries->id, $this->paycheck->id]],
            ],
        ]), range(1, 12))])->assertOk();

        $queries = $this->queriesDuring(fn () => $this->getJson($url)->assertOk()->assertJsonCount(12, 'widgets'));

        $this->assertCount($one, $queries);
        // Pruning checks every widget's envelopes with one query.
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "envelopes"')));
    }
}
