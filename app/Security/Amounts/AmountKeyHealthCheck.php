<?php

namespace App\Security\Amounts;

use App\Models\FinancialProfileKey;
use RuntimeException;

/**
 * Boot-time smoke check (issue #36): detect an APP_KEY rotation that broke
 * the wrapped amount keys early, with an actionable error, instead of
 * scattered `AmountDecryptionException` 500s (docs/encryption.md).
 */
final class AmountKeyHealthCheck
{
    private const MESSAGE = <<<'TXT'
        Encrypted amounts cannot be decrypted: the application key (APP_KEY) no longer unwraps the amount data keys. This usually means APP_KEY was regenerated (e.g. `composer setup` or `php artisan key:generate`) without keeping the previous key — recovery is impossible without it. Restore the previous APP_KEY from your backup (see README, "Encryption at rest"), or, if the rotation was intentional, list the old key in APP_PREVIOUS_KEYS and run `php artisan amounts:rewrap-keys`.
        TXT;

    /**
     * Unwrap one amount key per profile — its highest active version: cheap,
     * and enough to detect an APP_KEY rotation, which breaks every wrapped
     * key at once. Damage limited to individual amounts is caught by
     * `amounts:verify`.
     */
    public function assertAppKeyCanUnwrap(): void
    {
        $latest = FinancialProfileKey::query()
            ->selectRaw('profile_id, MAX(version) as version')
            ->whereNull('retired_at')
            ->groupBy('profile_id');

        $rows = FinancialProfileKey::query()
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('financial_profile_keys.profile_id', 'latest.profile_id')
                ->on('financial_profile_keys.version', 'latest.version'))
            ->get([
                'financial_profile_keys.profile_id as profile_id',
                'financial_profile_keys.version as version',
                'financial_profile_keys.wrapped_key as wrapped_key',
            ]);

        foreach ($rows as $row) {
            try {
                AmountKeyRing::unwrap($row->profile_id, $row->version, $row->wrapped_key);
            } catch (AmountDecryptionException $exception) {
                throw new RuntimeException(self::MESSAGE, previous: $exception);
            }
        }
    }
}
