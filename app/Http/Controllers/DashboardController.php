<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Dashboard\DeleteDashboardWidgetRequest;
use App\Http\Requests\Dashboard\SaveDashboardLayoutRequest;
use App\Http\Requests\Dashboard\WidgetDataRequest;
use App\Models\DashboardLayout;
use App\Models\DashboardWidget;
use App\Models\FinancialProfile;
use App\Services\Dashboard\DashboardAggregator;
use App\Services\Dashboard\DashboardLayoutValidator;
use App\Services\Dashboard\SelectionEnvelopes;
use App\Services\Dashboard\WidgetSelection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The dashboard API (docs/dashboard.md): per-user widget configuration for
 * a financial profile, plus the server-computed aggregate data per widget.
 * Every endpoint requires profile membership; each member manages their
 * own dashboard — 404 for non-members, 403 is never involved here because
 * dashboards are per-member (no owner escalation).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardLayoutValidator $layoutValidator,
        private readonly DashboardAggregator $aggregator,
    ) {}

    /**
     * The caller's widgets for the profile, in display order. Selections
     * referencing deleted envelopes are pruned server-side.
     */
    public function index(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorize('view', $profile);
        $user = $this->currentUser($request);

        // The revision is read BEFORE the widgets: a save committing
        // between the two reads can only pair the revision with widgets
        // newer than it — the next save would be refused and reconcile —
        // never the other way around, where a stale revision would
        // legitimize overwriting newer state (issue #55).
        $revision = $this->currentRevision($user->id, $profile->id);

        $widgets = DashboardWidget::forUserAndProfile(
            $user->id,
            $profile->id,
        )->get();

        $selections = $widgets->mapWithKeys(fn (DashboardWidget $widget): array => [
            $widget->id => WidgetSelection::fromConfig($widget->selection),
        ]);

        // Deleted envelopes are pruned from every widget's selection with a
        // single existence query.
        $existing = SelectionEnvelopes::existingIds(
            $selections->flatMap(fn (WidgetSelection $selection): array => $selection->envelopeIds())->all(),
        );

        return response()->json([
            'widgets' => $widgets
                ->map(function (DashboardWidget $widget) use ($selections, $existing) {
                    // The selections map was built from these same widgets,
                    // so the lookup always resolves.
                    $selection = $selections->get($widget->id);

                    if ($selection === null) {
                        throw new LogicException('The widget selection map must cover every widget.');
                    }

                    return $this->serializeWidget(
                        $widget,
                        $selection->pruned($existing),
                    );
                })
                ->values(),
            // Every write carries this back (issue #55): saves and removals
            // must edit against the layout they read.
            'revision' => $revision,
        ]);
    }

    /**
     * Full layout save: replaces the caller's widget list for the profile
     * (debounced autosave client-side — there is no partial update). The
     * payload order is the display order. Widgets carrying their existing
     * id are updated in place (ids stay stable so in-flight widget-data
     * requests keep resolving); widgets without an id are created; ids
     * missing from the payload are removed.
     *
     * Compare-and-write (issue #55): the request carries the layout revision
     * the client edited against; a stale value is refused with 409 and no
     * mutations, so two tabs — or a stale autosave racing a newer one —
     * cannot overwrite newer state. The first writer wins.
     */
    public function save(SaveDashboardLayoutRequest $request, FinancialProfile $profile): JsonResponse
    {
        $widgets = $request->widgets($this->layoutValidator);
        $expectedRevision = (int) $request->validated('expected_revision');
        $user = $this->currentUser($request);

        DB::transaction(function () use ($user, $profile, $widgets, $expectedRevision): DashboardLayout {
            $layout = $this->lockLayout($user->id, $profile->id);

            $this->ensureRevision($layout, $expectedRevision);

            $existing = DashboardWidget::query()
                ->where('user_id', $user->id)
                ->where('financial_profile_id', $profile->id)
                ->get()
                ->keyBy('id');

            foreach ($widgets as $sortOrder => $widget) {
                // A supplied id must resolve in the caller's own layout: a
                // stale (deleted) or foreign id would otherwise be recreated
                // as a new widget — resurrecting removed state (issue #55).
                // Unresolvable ids are rejected before any mutation; the
                // existence of another member's widget is never exposed.
                if (isset($widget['id']) && ! $existing->contains('id', $widget['id'])) {
                    throw ValidationException::withMessages([
                        'widgets' => __('Some widgets were changed or removed elsewhere. Reload the dashboard to see the latest layout.'),
                    ]);
                }

                $attributes = [
                    'type' => $widget['type'],
                    'title' => $widget['title'],
                    'view_type' => $widget['view_type'],
                    'column_width' => $widget['column_width'],
                    'period_type' => $widget['period']['type'],
                    'period_range' => $widget['period']['range'],
                    'selection' => $widget['selection'],
                    'sort_order' => $sortOrder,
                ];

                $known = isset($widget['id'])
                    ? $existing->get($widget['id'])
                    : null;

                if ($known !== null) {
                    $known->update($attributes);
                    $existing->forget($known->id);

                    continue;
                }

                DashboardWidget::create($attributes + [
                    'user_id' => $user->id,
                    'financial_profile_id' => $profile->id,
                ]);
            }

            // Widgets absent from the payload were removed client-side.
            $existing->each(fn (DashboardWidget $widget) => $widget->delete());

            return $this->advanceRevision($layout);
        });

        return $this->index($request, $profile);
    }

    /**
     * Remove one of the caller's widgets (destructive — the client asks for
     * confirmation). The removal is a layout write like a save: it carries
     * the expected revision and advances it under a lock (issue #55), so a
     * concurrent or stale save cannot resurrect the widget afterwards.
     */
    public function destroy(DeleteDashboardWidgetRequest $request, FinancialProfile $profile, DashboardWidget $widget): JsonResponse
    {
        $user = $this->currentUser($request);
        $expectedRevision = (int) $request->validated('expected_revision');

        $revision = DB::transaction(function () use ($user, $profile, $widget, $expectedRevision): int {
            $layout = $this->lockLayout($user->id, $profile->id);

            $this->ensureRevision($layout, $expectedRevision);

            $widget->delete();

            return $this->advanceRevision($layout)->revision;
        });

        // The new revision travels back so the client keeps editing against
        // the layout it now has.
        return response()->json(['revision' => $revision]);
    }

    /**
     * The server-computed aggregate payload for one of the caller's
     * widgets: amounts decrypted and summed server-side, masked per
     * envelope permissions — raw amounts never reach clients that cannot
     * see them (docs/dashboard.md).
     */
    public function data(WidgetDataRequest $request, FinancialProfile $profile, DashboardWidget $widget): JsonResponse
    {
        return response()->json(
            $this->aggregator->data(
                $widget,
                $profile,
                $this->currentUser($request),
                $request->calendar()->today(),
            ),
        );
    }

    /**
     * The revision the caller's layout currently presents: the durable row's
     * value, or the initial one for a layout that was never written (no row
     * exists — a layout with zero widgets still has a revision, issue #55).
     */
    private function currentRevision(int $userId, int $profileId): int
    {
        return DashboardLayout::forUserAndProfile($userId, $profileId)
            ->value('revision') ?? DashboardLayout::INITIAL_REVISION;
    }

    /**
     * Locks the caller's layout revision row for the duration of the
     * surrounding transaction, creating it if this is the layout's first
     * write. Every concurrent writer serializes on this row: the revision
     * check and the widget mutations it guards happen under the lock, so a
     * pre-transaction check could not slip past (issue #55).
     */
    private function lockLayout(int $userId, int $profileId): DashboardLayout
    {
        // Created on the layout's first write. The insert must be
        // conflict-free at the statement level (ON CONFLICT DO NOTHING):
        // on PostgreSQL, a plain insert losing a concurrent first write
        // would abort the whole transaction, not just fail this insert —
        // the lock below could then never arbitrate. The lock is what
        // serializes the writers; the revision check decides who wins.
        DashboardLayout::query()->insertOrIgnore([
            'user_id' => $userId,
            'financial_profile_id' => $profileId,
            'revision' => DashboardLayout::INITIAL_REVISION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The row exists (or was just inserted above); the lock serializes
        // concurrent writers. Missing row = the profile vanished mid-write
        // and the insert was rolled back: fail loudly.
        /** @var DashboardLayout $layout */
        $layout = DashboardLayout::forUserAndProfile($userId, $profileId)
            ->lockForUpdate()
            ->firstOrFail();

        return $layout;
    }

    /**
     * Compare-and-write: the request must edit against the layout's current
     * revision. Refused with the current state so the client can reconcile
     * instead of silently overwriting newer state (last-write-wins is not
     * enough for dashboards, docs/dashboard.md).
     */
    private function ensureRevision(DashboardLayout $layout, int $expectedRevision): void
    {
        if ($layout->revision !== $expectedRevision) {
            throw new HttpResponseException(response()->json([
                'message' => __('This layout was changed elsewhere while you were saving. Reload the dashboard to see the latest layout.'),
                'revision' => $layout->revision,
            ], 409));
        }
    }

    /**
     * Advances the layout revision once per write, exactly — the lock makes
     * the read-compare-write atomic (issue #55).
     */
    private function advanceRevision(DashboardLayout $layout): DashboardLayout
    {
        // increment() syncs the new value onto the model.
        $layout->increment('revision');

        return $layout;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWidget(DashboardWidget $widget, WidgetSelection $selection): array
    {
        return [
            'id' => $widget->id,
            'type' => $widget->type,
            'title' => $widget->title,
            'view_type' => $widget->view_type,
            'column_width' => $widget->column_width,
            'period' => $widget->periodConfig(),
            'selection' => $selection->items(),
            'order' => $widget->sort_order,
        ];
    }
}
