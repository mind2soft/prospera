<?php

namespace App\Security\Amounts;

use App\Models\Budget;
use App\Models\BudgetEnvelope;
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
     * Retire the profile's current key, create the next version and
     * re-encrypt every amount of the profile with it — atomically, with the
     * profile row locked. Returns the number of re-encrypted amounts.
     */
    public function rotate(int $profileId): int
    {
        try {
            return DB::transaction(function () use ($profileId): int {
                $this->lockProfile($profileId);

                FinancialProfileKey::query()
                    ->where('profile_id', $profileId)
                    ->whereNull('retired_at')
                    ->update(['retired_at' => now(), 'updated_at' => now()]);

                $this->ring->forget($profileId);
                $this->ring->createKey($profileId);

                return $this->reencrypt($profileId);
            });
        } finally {
            $this->ring->forget($profileId);
        }
    }

    /**
     * Delete the profile's key versions retired before the cutoff, after
     * re-encrypting any amount still using them. Returns the number of
     * deleted key versions.
     */
    public function prune(int $profileId, CarbonInterface $retiredBefore): int
    {
        try {
            return DB::transaction(function () use ($profileId, $retiredBefore): int {
                $this->lockProfile($profileId);

                $versions = FinancialProfileKey::query()
                    ->where('profile_id', $profileId)
                    ->whereNotNull('retired_at')
                    ->where('retired_at', '<', $retiredBefore)
                    ->pluck('version');

                if ($versions->isEmpty()) {
                    return 0;
                }

                $this->reencrypt($profileId);

                foreach ($versions as $version) {
                    foreach ($this->sources($profileId) as [$query, $column]) {
                        if ($query->where($column, 'like', AmountCipher::FORMAT.'.'.$version.'.%')->exists()) {
                            throw new RuntimeException("Amount key version {$version} of profile {$profileId} is still in use.");
                        }
                    }
                }

                return FinancialProfileKey::query()
                    ->where('profile_id', $profileId)
                    ->whereIn('version', $versions)
                    ->delete();
            });
        } finally {
            $this->ring->forget($profileId);
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

            FinancialProfileKey::query()->orderBy('id')->lockForUpdate()->each(function (FinancialProfileKey $row) use (&$count): void {
                $key = AmountKeyRing::unwrap($row->profile_id, $row->version, $row->wrapped_key);

                FinancialProfileKey::query()->whereKey($row->id)->update(['wrapped_key' => AmountKeyRing::wrap($key)]);

                $count++;
            });

            return $count;
        });
    }

    /**
     * Decrypt every amount of the given profiles (all when empty). Returns
     * the failures; never throws on a bad row.
     *
     * @param  list<int>  $profileIds
     * @return list<array{table: string, id: int, error: string}>
     */
    public function verify(array $profileIds = []): array
    {
        $failures = [];

        $profiles = $profileIds === []
            ? FinancialProfile::query()->orderBy('id')->pluck('id')->all()
            : $profileIds;

        foreach ($profiles as $profileId) {
            try {
                $this->ring->activeKey((int) $profileId);
            } catch (Throwable $e) {
                $failures[] = ['table' => 'financial_profile_keys', 'id' => (int) $profileId, 'error' => $e->getMessage()];
            }

            foreach ($this->sources((int) $profileId) as [$query, $column]) {
                $query->chunkById(self::CHUNK, function (Collection $rows) use ($column, &$failures): void {
                    foreach ($rows as $row) {
                        try {
                            $row->getAttribute($column);
                        } catch (Throwable $e) {
                            $failures[] = ['table' => $row->getTable(), 'id' => (int) $row->getKey(), 'error' => $e->getMessage()];
                        }
                    }
                });
            }

            $this->ring->forget((int) $profileId);
        }

        return $failures;
    }

    /**
     * Re-encrypt the profile's amounts not on its active key version, with
     * the rows locked so no concurrent write is lost.
     */
    private function reencrypt(int $profileId): int
    {
        $active = $this->ring->activeKey($profileId)->version;
        $count = 0;

        foreach ($this->sources($profileId) as [$query, $column]) {
            $query->lockForUpdate()->chunkById(self::CHUNK, function (Collection $rows) use ($column, $active, &$count): void {
                foreach ($rows as $row) {
                    $raw = (string) $row->getRawOriginal($column);

                    if (AmountCipher::keyVersionOf($raw) === $active) {
                        continue;
                    }

                    $row->setAttribute($column, $row->getAttribute($column));
                    $row->timestamps = false;
                    $row->saveQuietly();

                    $count++;
                }
            });
        }

        return $count;
    }

    private function lockProfile(int $profileId): void
    {
        FinancialProfile::query()->whereKey($profileId)->lockForUpdate()->firstOrFail();
    }

    /**
     * The profile's encrypted amount rows: [query, column].
     *
     * @return list<array{0: Builder<Model>, 1: string}>
     */
    private function sources(int $profileId): array
    {
        /** @var list<array{0: Builder<Model>, 1: string}> */
        return [
            // Pending deletions are restorable: they must stay decryptable.
            [Transaction::withTrashed()->whereIn('ledger_id', Ledger::query()->where('profile_id', $profileId)->select('id')), 'amount'],
            [BudgetEnvelope::query()->whereIn('budget_id', Budget::query()->where('profile_id', $profileId)->select('id')), 'amount'],
            [Ledger::query()->where('profile_id', $profileId), 'starting_balance'],
        ];
    }
}
