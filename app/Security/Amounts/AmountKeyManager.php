<?php

declare(strict_types=1);

namespace App\Security\Amounts;

use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\EnvelopeAmountKey;
use App\Models\EnvelopeSchedule;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileKey;
use App\Models\Ledger;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Data-key lifecycle: rotation, pruning of retired versions, re-wrapping
 * under a new application key and verification (docs/encryption.md).
 */
final class AmountKeyManager
{
    private const CHUNK = 500;

    public function __construct(private readonly AmountKeyRing $ring) {}

    /**
     * Retire the owner's current key, create the next version and re-encrypt
     * its amounts atomically, locking the profile/envelope before its rows.
     * Returns the number of re-encrypted amounts.
     */
    public function rotate(int $ownerId, AmountKeyScope $scope = AmountKeyScope::Profile): int
    {
        try {
            return DB::transaction(function () use ($ownerId, $scope): int {
                $this->lockOwner($ownerId, $scope);

                // Rotation replaces a known key; it must never initialize a
                // missing one or trust a request's stale memoized material.
                $this->ring->forget($ownerId, $scope);
                $this->ring->activeKey($ownerId, $scope);

                $scope->keyModel()::query()
                    ->where($scope->column(), $ownerId)
                    ->whereNull('retired_at')
                    ->update(['retired_at' => now(), 'updated_at' => now()]);

                $this->ring->forget($ownerId, $scope);
                $this->ring->createKey($ownerId, $scope);

                return $this->reencrypt($ownerId, $scope);
            });
        } finally {
            $this->ring->forget($ownerId, $scope);
        }
    }

    /**
     * Delete the owner's key versions retired before the cutoff, after
     * re-encrypting any amount still using them. Returns the number of
     * deleted key versions.
     */
    public function prune(int $ownerId, CarbonInterface $retiredBefore, AmountKeyScope $scope = AmountKeyScope::Profile): int
    {
        try {
            return DB::transaction(function () use ($ownerId, $retiredBefore, $scope): int {
                $this->lockOwner($ownerId, $scope);

                $versions = $scope->keyModel()::query()
                    ->where($scope->column(), $ownerId)
                    ->whereNotNull('retired_at')
                    ->where('retired_at', '<', $retiredBefore)
                    ->pluck('version');

                if ($versions->isEmpty()) {
                    return 0;
                }

                $this->reencrypt($ownerId, $scope);

                foreach ($versions as $rawVersion) {
                    $version = AmountContext::id($rawVersion, 'version');
                    foreach ($this->sources($ownerId, $scope) as [$query, $column]) {
                        if ($query->where($column, 'like', AmountCipher::FORMAT.'.'.$version.'.%')->exists()) {
                            throw new RuntimeException("Amount key version {$version} of {$scope->value} {$ownerId} is still in use.");
                        }
                    }
                }

                return $scope->keyModel()::query()
                    ->where($scope->column(), $ownerId)
                    ->whereIn('version', $versions)
                    ->toBase()
                    ->delete();
            });
        } finally {
            $this->ring->forget($ownerId, $scope);
        }
    }

    /**
     * Re-wrap every data key with the current application key (after an
     * APP_KEY rotation; the old key must be in APP_PREVIOUS_KEYS). Returns the
     * number of re-wrapped keys.
     */
    public function rewrapAll(): int
    {
        return DB::transaction(function (): int {
            $count = 0;

            foreach (AmountKeyScope::cases() as $scope) {
                $scope->keyModel()::query()->orderBy('id')->lockForUpdate()->each(function (FinancialProfileKey|EnvelopeAmountKey $row) use (&$count, $scope): void {
                    $ownerId = AmountContext::id($row->getAttribute($scope->column()), $scope->column());
                    $key = AmountKeyRing::unwrap($ownerId, $row->version, $row->wrapped_key, $scope);

                    $row->update(['wrapped_key' => AmountKeyRing::wrap($key)]);

                    $count++;
                });
            }

            return $count;
        });
    }

    /**
     * Decrypt every amount of the selected owners (both scopes when neither
     * list is supplied). Returns failures; never throws on a bad amount row.
     *
     * @param  list<int>  $profileIds
     * @param  list<int>  $envelopeIds
     * @return list<array{table: string, id: int, error: string}>
     */
    public function verify(array $profileIds = [], array $envelopeIds = []): array
    {
        $failures = [];
        $all = $profileIds === [] && $envelopeIds === [];

        foreach (AmountKeyScope::cases() as $scope) {
            $ids = $all ? $this->ownerIds($scope) : ($scope === AmountKeyScope::Profile ? $profileIds : $envelopeIds);
            foreach ($ids as $ownerId) {
                try {
                    $this->ring->activeKey($ownerId, $scope);
                } catch (Throwable $e) {
                    $failures[] = ['table' => (new ($scope->keyModel()))->getTable(), 'id' => $ownerId, 'error' => $e->getMessage()];
                }

                foreach ($this->sources($ownerId, $scope) as [$query, $column]) {
                    $query->chunkById(self::CHUNK, function (Collection $rows) use ($column, &$failures): void {
                        foreach ($rows as $row) {
                            try {
                                $row->getAttribute($column);
                            } catch (Throwable $e) {
                                $failures[] = ['table' => $row->getTable(), 'id' => AmountContext::id($row->getKey(), 'id'), 'error' => $e->getMessage()];
                            }
                        }
                    });
                }

                $this->ring->forget($ownerId, $scope);
            }
        }

        return $failures;
    }

    /**
     * Re-encrypt the owner's amounts not on its active key version, with
     * the rows locked so no concurrent write is lost.
     */
    private function reencrypt(int $ownerId, AmountKeyScope $scope): int
    {
        $active = $this->ring->activeKey($ownerId, $scope)->version;
        $count = 0;

        foreach ($this->sources($ownerId, $scope) as [$query, $column]) {
            $query->lockForUpdate()->chunkById(self::CHUNK, function (Collection $rows) use ($column, $active, &$count): void {
                foreach ($rows as $row) {
                    $raw = $row->getRawOriginal($column);
                    if (! is_string($raw)) {
                        throw new AmountDecryptionException('Stored amount is not an encrypted payload.');
                    }

                    // Authenticate even a matching version: a missing key row
                    // must not be silently replaced by a new key with the
                    // same version number, nor corruption skipped by pruning.
                    $amount = $row->getAttribute($column);
                    if (AmountCipher::keyVersionOf($raw) === $active) {
                        continue;
                    }

                    $row->setAttribute($column, $amount);
                    $row->timestamps = false;
                    $row->saveQuietly();

                    $count++;
                }
            });
        }

        return $count;
    }

    private function lockOwner(int $ownerId, AmountKeyScope $scope): void
    {
        $scope->ownerModel()::query()->whereKey($ownerId)->lockForUpdate()->firstOrFail();
    }

    /** @return list<int> */
    public function ownerIds(AmountKeyScope $scope): array
    {
        $query = $scope === AmountKeyScope::Profile
            ? FinancialProfile::query()
            : Envelope::query()->where(fn (Builder $query) => $query
                ->whereHas('amountKeys')
                ->orWhereHas('schedules', fn (Builder $schedules) => $schedules->withTrashed()));

        return array_values(array_map(fn (mixed $id): int => AmountContext::id($id, 'id'), $query->orderBy('id')->pluck('id')->all()));
    }

    /**
     * The owner's encrypted amount rows: [query, column].
     *
     * @return list<array{0: Builder<Model>, 1: string}>
     */
    private function sources(int $ownerId, AmountKeyScope $scope): array
    {
        if ($scope === AmountKeyScope::Envelope) {
            /** @var list<array{0: Builder<Model>, 1: string}> */
            return [[EnvelopeSchedule::withTrashed()->where('envelope_id', $ownerId), 'amount']];
        }

        /** @var list<array{0: Builder<Model>, 1: string}> */
        return [
            // Pending deletions are restorable: they must stay decryptable.
            [Transaction::withTrashed()->whereIn('ledger_id', Ledger::query()->where('profile_id', $ownerId)->select('id')), 'amount'],
            [BudgetEnvelope::query()->whereIn('budget_id', Budget::query()->where('profile_id', $ownerId)->select('id')), 'amount'],
            [Ledger::query()->where('profile_id', $ownerId), 'starting_balance'],
        ];
    }
}
