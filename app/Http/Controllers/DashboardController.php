<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dashboard\SaveDashboardLayoutRequest;
use App\Http\Requests\Dashboard\WidgetDataRequest;
use App\Models\DashboardWidget;
use App\Models\FinancialProfile;
use App\Services\Dashboard\DashboardAggregator;
use App\Services\Dashboard\DashboardLayoutValidator;
use App\Services\Dashboard\SelectionEnvelopes;
use App\Services\Dashboard\WidgetSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $widgets = DashboardWidget::forUserAndProfile(
            (int) $request->user()->id,
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
                ->map(fn (DashboardWidget $widget) => $this->serializeWidget(
                    $widget,
                    $selections->get($widget->id)->pruned($existing),
                ))
                ->values(),
        ]);
    }

    /**
     * Full layout save: replaces the caller's widget list for the profile
     * (debounced autosave client-side — there is no partial update). The
     * payload order is the display order. Widgets carrying their existing
     * id are updated in place (ids stay stable so in-flight widget-data
     * requests keep resolving); widgets without an id are created; ids
     * missing from the payload are removed.
     */
    public function save(SaveDashboardLayoutRequest $request, FinancialProfile $profile): JsonResponse
    {
        $widgets = $request->widgets($this->layoutValidator);

        DB::transaction(function () use ($request, $profile, $widgets): void {
            $existing = DashboardWidget::query()
                ->where('user_id', $request->user()->id)
                ->where('financial_profile_id', $profile->id)
                ->get()
                ->keyBy('id');

            foreach ($widgets as $sortOrder => $widget) {
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
                    'user_id' => $request->user()->id,
                    'financial_profile_id' => $profile->id,
                ]);
            }

            // Widgets absent from the payload were removed client-side.
            $existing->each(fn (DashboardWidget $widget) => $widget->delete());
        });

        return $this->index($request, $profile);
    }

    /**
     * Remove one of the caller's widgets (destructive — the client asks for
     * confirmation).
     */
    public function destroy(FinancialProfile $profile, DashboardWidget $widget): JsonResponse
    {
        $this->authorize('delete', [$widget, $profile]);

        $widget->delete();

        return response()->json(status: 204);
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
                $request->user(),
                $request->calendar()->today(),
            ),
        );
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
