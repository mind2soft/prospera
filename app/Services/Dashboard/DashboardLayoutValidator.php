<?php

namespace App\Services\Dashboard;

use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes a full dashboard layout payload
 * (docs/dashboard.md): an ordered list of widget configurations. The order
 * is the payload order — the server assigns `sort_order` from the index.
 *
 * Validation enforces, per widget:
 * - `view_type` is one of the widget type's view types;
 * - `period.range` belongs to the canonical enumeration of `period.type`;
 * - `column_width` is 1..5;
 * - every selected envelope exists and is visible to the requester;
 * - each envelope appears at most once per widget (envelope row or group
 *   member — no double counting);
 * - groups are flat (a group cannot contain another group) and named.
 */
final class DashboardLayoutValidator
{
    /** Maximum widgets on one dashboard. */
    public const MAX_WIDGETS = 20;

    /** Maximum selection items per widget. */
    public const MAX_SELECTION_ITEMS = 50;

    /**
     * Validate and normalize the layout; returns the widget configurations
     * in payload order. Widgets may carry their existing `id` — the save is
     * an in-place update for known ids (so server-side ids stay stable and
     * in-flight widget-data requests never 404 mid-flight), an insert for
     * new ones. The payload shape itself (a bounded `widgets` array) is
     * validated by SaveDashboardLayoutRequest.
     *
     * @param  array<mixed>  $widgets
     * @return list<array{id?: int, type: string, title: string|null, view_type: string, column_width: int, period: array{type: string, range: int}, selection: list<array<string, mixed>>}>
     */
    public function validate(array $widgets, User $user): array
    {
        $visibleIds = $this->visibleEnvelopeIds($widgets, $user);

        $normalized = [];
        $seenWidgetIds = [];

        foreach ($widgets as $index => $widget) {
            $normalized[] = $normalizedWidget = $this->validateWidget($index, $widget, $visibleIds);

            // The same existing widget may not appear twice in one payload —
            // the save updates in place by id.
            $id = $normalizedWidget['id'] ?? null;

            if ($id !== null) {
                if (isset($seenWidgetIds[$id])) {
                    throw $this->error($index, 'id', __('The widget configuration is invalid.'));
                }

                $seenWidgetIds[$id] = true;
            }
        }

        return $normalized;
    }

    /**
     * The ids of the envelopes the requester can see (metadata visibility —
     * see the Envelope::visibleTo scope), keyed by id.
     *
     * @param  array<mixed>  $widgets
     * @return array<int, int>
     */
    private function visibleEnvelopeIds(array $widgets, User $user): array
    {
        $ids = $this->selectedEnvelopeIds($widgets);

        if ($ids === []) {
            return [];
        }

        return Envelope::query()
            ->visibleTo($user)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->flip()
            ->all();
    }

    /**
     * Every envelope id referenced anywhere in the payload (envelope rows
     * and group members), for the single visibility query.
     *
     * @param  array<mixed>  $widgets
     * @return list<int>
     */
    private function selectedEnvelopeIds(array $widgets): array
    {
        $ids = [];

        foreach ($widgets as $widget) {
            $selection = is_array($widget) ? ($widget['selection'] ?? null) : null;

            foreach (is_array($selection) ? $selection : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (($item['kind'] ?? null) === 'envelope' && is_numeric($item['envelope_id'] ?? null)) {
                    $ids[] = (int) $item['envelope_id'];
                } elseif (($item['kind'] ?? null) === 'group' && is_array($item['envelope_ids'] ?? null)) {
                    foreach ($item['envelope_ids'] as $envelopeId) {
                        if (is_numeric($envelopeId)) {
                            $ids[] = (int) $envelopeId;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Validate one widget configuration.
     *
     * @param  array<string, mixed>|null  $widget  raw widget payload
     * @param  array<int, int>  $visibleIds
     * @return array{id?: int|null, type: string, title: string|null, view_type: string, column_width: int, period: array{type: string, range: int}, selection: list<array<string, mixed>>}
     */
    private function validateWidget(int $index, mixed $widget, array $visibleIds): array
    {
        if (! is_array($widget)) {
            throw $this->error($index, 'type', __('The widget configuration is invalid.'));
        }

        $type = $widget['type'] ?? null;

        if (! in_array($type, DashboardWidget::TYPES, true)) {
            throw $this->error($index, 'type', __('The widget type is invalid.'));
        }

        $title = $widget['title'] ?? null;

        if ($title !== null && ! is_string($title)) {
            throw $this->error($index, 'title', __('The widget title must be a string.'));
        }

        $title = $title === null ? null : trim($title);
        $title = $title === '' ? null : $title;

        if ($title !== null && mb_strlen($title) > 60) {
            throw $this->error($index, 'title', __('The widget title may not be greater than :max characters.', ['max' => 60]));
        }

        // Existing widgets keep their id across saves (in-place update).
        $id = $widget['id'] ?? null;

        if ($id !== null && (! is_int($id) || $id < 1)) {
            throw $this->error($index, 'id', __('The widget configuration is invalid.'));
        }

        $viewType = $widget['view_type'] ?? null;
        $allowedViewTypes = DashboardWidget::VIEW_TYPES[$type];

        if (! in_array($viewType, $allowedViewTypes, true)) {
            throw $this->error($index, 'view_type', __('The widget view type is invalid.'));
        }

        $columnWidth = $widget['column_width'] ?? null;

        if (! is_int($columnWidth) || $columnWidth < DashboardWidget::MIN_COLUMN_WIDTH || $columnWidth > DashboardWidget::MAX_COLUMN_WIDTH) {
            throw $this->error($index, 'column_width', __('The widget column width must be between :min and :max.', [
                'min' => DashboardWidget::MIN_COLUMN_WIDTH,
                'max' => DashboardWidget::MAX_COLUMN_WIDTH,
            ]));
        }

        $period = $this->validatePeriod($index, $widget['period'] ?? null);
        $selection = $this->validateSelection($index, $widget['selection'] ?? null, $visibleIds);

        // Bound the total referenced envelopes per widget (rows + group
        // members) — aggregates hydrate every referenced transaction into
        // PHP, so runaway selections must stay impossible.
        $referenced = [];

        foreach ($selection as $item) {
            foreach ($item['kind'] === 'envelope' ? [$item['envelope_id']] : $item['envelope_ids'] as $envelopeId) {
                $referenced[$envelopeId] = true;
            }
        }

        if (count($referenced) > self::MAX_SELECTION_ITEMS) {
            throw $this->error($index, 'selection', __('A widget can reference at most :max envelopes.', ['max' => self::MAX_SELECTION_ITEMS]));
        }

        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'view_type' => $viewType,
            'column_width' => $columnWidth,
            'period' => $period,
            'selection' => $selection,
        ];
    }

    /**
     * @return array{type: string, range: int}
     */
    private function validatePeriod(int $index, mixed $period): array
    {
        if (! is_array($period) || ! in_array($period['type'] ?? null, [DashboardWidget::PERIOD_DAYS, DashboardWidget::PERIOD_MONTHS], true)) {
            throw $this->error($index, 'period.type', __('The widget period type is invalid.'));
        }

        $ranges = DashboardWidget::PERIOD_RANGES[$period['type']];
        $range = $period['range'] ?? null;

        if (! is_int($range) || ! in_array($range, $ranges, true)) {
            throw $this->error($index, 'period.range', __('The widget period range is invalid.'));
        }

        return ['type' => $period['type'], 'range' => $range];
    }

    /**
     * Validate the selection: flat envelope rows and groups, each envelope
     * at most once across the whole widget, every envelope visible to the
     * requester.
     *
     * @param  array<int, int>  $visibleIds
     * @return list<array<string, mixed>>
     */
    private function validateSelection(int $index, mixed $selection, array $visibleIds): array
    {
        if (! is_array($selection) || count($selection) > self::MAX_SELECTION_ITEMS) {
            throw $this->error($index, 'selection', __('The envelope selection is invalid.'));
        }

        $used = [];
        $items = [];

        foreach ($selection as $item) {
            if (! is_array($item)) {
                throw $this->error($index, 'selection', __('The envelope selection is invalid.'));
            }

            if (($item['kind'] ?? null) === 'envelope') {
                $envelopeId = $item['envelope_id'] ?? null;

                if (! is_int($envelopeId)) {
                    throw $this->error($index, 'selection', __('The envelope selection is invalid.'));
                }

                $this->assertEnvelopeUsable($index, $envelopeId, $visibleIds, $used);

                $items[] = ['kind' => 'envelope', 'envelope_id' => $envelopeId];
            } elseif (($item['kind'] ?? null) === 'group') {
                $name = $item['name'] ?? null;

                if (! is_string($name) || trim($name) === '') {
                    throw $this->error($index, 'selection', __('A group needs a name.'));
                }

                $name = trim($name);

                if (mb_strlen($name) > 60) {
                    throw $this->error($index, 'selection', __('The group name may not be greater than :max characters.', ['max' => 60]));
                }

                $envelopeIds = $item['envelope_ids'] ?? null;

                if (! is_array($envelopeIds) || $envelopeIds === [] || count($envelopeIds) > self::MAX_SELECTION_ITEMS) {
                    throw $this->error($index, 'selection', __('A group needs at least one envelope.'));
                }

                foreach ($envelopeIds as $envelopeId) {
                    if (! is_int($envelopeId)) {
                        throw $this->error($index, 'selection', __('The envelope selection is invalid.'));
                    }

                    $this->assertEnvelopeUsable($index, $envelopeId, $visibleIds, $used);
                }

                $items[] = ['kind' => 'group', 'name' => $name, 'envelope_ids' => array_values($envelopeIds)];
            } else {
                throw $this->error($index, 'selection', __('The envelope selection is invalid.'));
            }
        }

        return $items;
    }

    /**
     * The envelope must exist and be visible to the requester, and may not
     * already be used in this widget's selection (no double counting).
     *
     * @param  array<int, int>  $visibleIds
     * @param  array<int, true>  $used
     */
    private function assertEnvelopeUsable(int $index, int $envelopeId, array $visibleIds, array &$used): void
    {
        if (! isset($visibleIds[$envelopeId])) {
            throw $this->error($index, 'selection', __('One of the selected envelopes is unavailable.'));
        }

        if (isset($used[$envelopeId])) {
            throw $this->error($index, 'selection', __('Each envelope can only be selected once per widget.'));
        }

        $used[$envelopeId] = true;
    }

    private function error(int $index, string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([
            "widgets.{$index}.{$field}" => $message,
        ]);
    }
}
