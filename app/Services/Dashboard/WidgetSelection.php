<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Arr;

/**
 * The envelope selection of a dashboard widget (docs/dashboard.md): an
 * ordered list of envelope rows and flat, single-level groups. Envelopes may
 * appear at most once across the whole selection (no double counting); a
 * group cannot contain another group (enforced by the shape itself).
 *
 * Immutable value object; `pruned` returns a copy without references to
 * envelopes that no longer exist.
 *
 * @phpstan-type SelectionEnvelope array{kind: 'envelope', envelope_id: int}
 * @phpstan-type SelectionGroup array{kind: 'group', name: string, envelope_ids: list<int>}
 * @phpstan-type SelectionItem SelectionEnvelope|SelectionGroup
 */
final class WidgetSelection
{
    /** @var list<SelectionItem> */
    private array $items;

    /** @param list<SelectionItem> $items */
    private function __construct(array $items)
    {
        $this->items = $items;
    }

    /**
     * Builds the selection from the raw JSON column value, ignoring
     * malformed entries (an old, hand-edited row must not break the page).
     *
     * @param  array<int, mixed>|null  $selection
     */
    public static function fromConfig(?array $selection): self
    {
        $items = [];

        foreach (Arr::wrap($selection) as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['kind'] ?? null) === 'envelope' && is_int($item['envelope_id'] ?? null)) {
                /** @var SelectionEnvelope $entry */
                $entry = ['kind' => 'envelope', 'envelope_id' => $item['envelope_id']];

                $items[] = $entry;
            } elseif (($item['kind'] ?? null) === 'group' && is_string($item['name'] ?? null)) {
                $ids = array_values(array_filter(
                    Arr::wrap($item['envelope_ids'] ?? []),
                    fn (mixed $id): bool => is_int($id),
                ));

                /** @var SelectionGroup $entry */
                $entry = ['kind' => 'group', 'name' => $item['name'], 'envelope_ids' => $ids];

                $items[] = $entry;
            }
        }

        return new self($items);
    }

    /**
     * All referenced envelope ids, in envelope rows and groups.
     *
     * @return list<int>
     */
    public function envelopeIds(): array
    {
        $ids = [];

        foreach ($this->items as $item) {
            if ($item['kind'] === 'envelope') {
                $ids[] = $item['envelope_id'];
            } else {
                $ids = array_merge($ids, $item['envelope_ids']);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<SelectionItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * A copy of the selection without references to envelopes that no longer
     * exist: deleted envelopes are pruned from rows and groups shrink
     * accordingly; groups that end up empty are dropped (no orphan rows).
     *
     * @param  array<int, int>  $existingIds
     */
    public function pruned(array $existingIds): self
    {
        $existing = array_flip($existingIds);
        $items = [];

        foreach ($this->items as $item) {
            if ($item['kind'] === 'envelope') {
                if (isset($existing[$item['envelope_id']])) {
                    $items[] = $item;
                }
            } else {
                $envelopeIds = array_values(array_filter(
                    $item['envelope_ids'],
                    fn (int $id): bool => isset($existing[$id]),
                ));

                if ($envelopeIds !== []) {
                    $items[] = ['kind' => 'group', 'name' => $item['name'], 'envelope_ids' => $envelopeIds];
                }
            }
        }

        /** @var list<SelectionItem> $items */
        return new self($items);
    }
}
