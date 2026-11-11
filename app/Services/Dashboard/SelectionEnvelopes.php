<?php

namespace App\Services\Dashboard;

use App\Models\Envelope;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The envelopes behind widget selections, loaded in one query. Deleted
 * envelopes are pruned from selections server-side on read
 * (docs/dashboard.md): the ids found here are the ones that still exist.
 */
final class SelectionEnvelopes
{
    /**
     * The ids among the given ones that still exist — one query for any
     * number of widgets.
     *
     * @param  array<int, int>  $envelopeIds
     * @return list<int>
     */
    public static function existingIds(array $envelopeIds): array
    {
        if ($envelopeIds === []) {
            return [];
        }

        return array_values(Envelope::query()
            ->whereIn('id', array_values(array_unique($envelopeIds)))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * The existing envelopes among the given ids, keyed by id, with the
     * user's share rows eager-loaded so `permissionFor()` needs no further
     * query.
     *
     * @param  array<int, int>  $envelopeIds
     * @return Collection<int, Envelope>
     */
    public static function loadFor(User $user, array $envelopeIds): Collection
    {
        if ($envelopeIds === []) {
            return collect();
        }

        return Envelope::query()
            ->whereIn('id', array_values(array_unique($envelopeIds)))
            ->with(['permissions' => fn ($query) => $query->where('user_id', $user->id)])
            ->get()
            ->keyBy('id');
    }
}
