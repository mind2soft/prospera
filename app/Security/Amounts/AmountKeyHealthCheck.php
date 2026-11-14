<?php

namespace App\Security\Amounts;

use App\Models\FinancialProfileKey;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;

/**
 * Boot-time smoke check (issue #36): detect an APP_KEY rotation that broke
 * the wrapped amount keys early, with an actionable error, instead of
 * scattered `AmountDecryptionException` 500s (docs/encryption.md).
 */
final class AmountKeyHealthCheck
{
    private const MESSAGE = <<<'TXT'
        Encrypted amounts cannot be decrypted: the application key (APP_KEY) no longer unwraps the amount data keys. This usually means APP_KEY was regenerated (e.g. a bare `php artisan key:generate` — composer setup refuses to rotate) without keeping the previous key — recovery is impossible without it. Restore the previous APP_KEY from your backup (see README, "Encryption at rest"), or, if the rotation was intentional, list the old key in APP_PREVIOUS_KEYS and run `php artisan amounts:rewrap-keys`.
        TXT;

    /**
     * Unwrap a single amount key — the most recently created active one. An
     * APP_KEY rotation breaks every wrapped key at once, so one key detects
     * it at constant cost (one indexed query, one unwrap) whatever the
     * number of profiles. Damage limited to individual keys or amounts is
     * caught by `amounts:verify` (deploys and daily).
     *
     * A database that cannot be read (down, locked, not migrated yet) is
     * not this check's concern: it is skipped so the request reaches its
     * own error handling (and the maintenance page keeps rendering).
     */
    public function assertAppKeyCanUnwrap(): void
    {
        try {
            $row = FinancialProfileKey::query()
                ->whereNull('retired_at')
                ->orderByDesc('id')
                ->first(['profile_id', 'version', 'wrapped_key']);
        } catch (QueryException|PDOException) {
            return;
        }

        if ($row === null) {
            return;
        }

        try {
            AmountKeyRing::unwrap($row->profile_id, $row->version, $row->wrapped_key);
        } catch (AmountDecryptionException $exception) {
            throw new RuntimeException(self::MESSAGE, previous: $exception);
        }
    }
}
