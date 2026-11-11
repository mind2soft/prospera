<?php

namespace App\Security\Amounts;

use App\Models\FinancialProfileKey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Per-request access to the profiles' amount data keys. Keys are unwrapped
 * with the application key (Crypt, honouring APP_PREVIOUS_KEYS) and memoized
 * in memory for the current request/job only — never cached or persisted in
 * plaintext. Registered as a scoped binding.
 */
final class AmountKeyRing
{
    /** @var array<int, array<int, DataKey>> */
    private array $keys = [];

    /** @var array<int, int> */
    private array $activeVersions = [];

    /** @var array<int, int> */
    private array $ledgerProfiles = [];

    /** @var array<int, int> */
    private array $budgetProfiles = [];

    public function __construct(private readonly AmountCipher $cipher) {}

    /**
     * The key new amounts of the profile are encrypted with: its highest
     * non-retired version.
     */
    public function activeKey(int $profileId): DataKey
    {
        if (isset($this->activeVersions[$profileId])) {
            return $this->key($profileId, $this->activeVersions[$profileId]);
        }

        $row = FinancialProfileKey::query()
            ->where('profile_id', $profileId)
            ->whereNull('retired_at')
            ->orderByDesc('version')
            ->first();

        if ($row === null) {
            throw new RuntimeException("No active amount key for profile {$profileId}.");
        }

        $key = self::unwrap($profileId, $row->version, $row->wrapped_key);

        $this->keys[$profileId][$row->version] = $key;
        $this->activeVersions[$profileId] = $row->version;

        return $key;
    }

    /**
     * Any existing key version of the profile (retired ones included), as
     * needed to decrypt amounts written before a rotation.
     */
    public function key(int $profileId, int $version): DataKey
    {
        if (isset($this->keys[$profileId][$version])) {
            return $this->keys[$profileId][$version];
        }

        $row = FinancialProfileKey::query()
            ->where('profile_id', $profileId)
            ->where('version', $version)
            ->first();

        if ($row === null) {
            throw new AmountDecryptionException("Unknown amount key version {$version} for profile {$profileId}.");
        }

        return $this->keys[$profileId][$version] = self::unwrap($profileId, $row->version, $row->wrapped_key);
    }

    /**
     * Generate and store the profile's next key version. Not memoized: the
     * caller's transaction may still roll back.
     */
    public function createKey(int $profileId): DataKey
    {
        $version = (int) FinancialProfileKey::query()->where('profile_id', $profileId)->max('version') + 1;

        $key = DataKey::generate($profileId, $version);

        FinancialProfileKey::create([
            'profile_id' => $profileId,
            'version' => $version,
            'wrapped_key' => self::wrap($key),
        ]);

        return $key;
    }

    public function profileIdForLedger(int $ledgerId): int
    {
        return $this->ledgerProfiles[$ledgerId] ??= $this->profileIdOf('ledgers', $ledgerId);
    }

    public function profileIdForBudget(int $budgetId): int
    {
        return $this->budgetProfiles[$budgetId] ??= $this->profileIdOf('budgets', $budgetId);
    }

    /**
     * Record the profile of ledgers the caller already read (e.g. a
     * profile's ledgers), saving one lookup query per ledger.
     *
     * @param  iterable<int>  $ledgerIds
     */
    public function rememberLedgerProfiles(iterable $ledgerIds, int $profileId): void
    {
        foreach ($ledgerIds as $ledgerId) {
            $this->ledgerProfiles[(int) $ledgerId] = $profileId;
        }
    }

    /**
     * Record the profile of budgets the caller already read.
     *
     * @param  iterable<int>  $budgetIds
     */
    public function rememberBudgetProfiles(iterable $budgetIds, int $profileId): void
    {
        foreach ($budgetIds as $budgetId) {
            $this->budgetProfiles[(int) $budgetId] = $profileId;
        }
    }

    public function encrypt(#[\SensitiveParameter] int $amount, AmountContext $context): string
    {
        return $this->cipher->encrypt($amount, $this->activeKey($context->profileId), $context);
    }

    public function decrypt(string $payload, AmountContext $context): int
    {
        $version = AmountCipher::keyVersionOf($payload, $context);

        return $this->cipher->decrypt($payload, $this->key($context->profileId, $version), $context);
    }

    /**
     * Drop memoized keys and lookups (all, or one profile's keys).
     */
    public function forget(?int $profileId = null): void
    {
        if ($profileId === null) {
            $this->keys = $this->activeVersions = $this->ledgerProfiles = $this->budgetProfiles = [];

            return;
        }

        unset($this->keys[$profileId], $this->activeVersions[$profileId]);
    }

    /**
     * Encrypt a data key with the application key, bound to its profile and
     * version so a wrapped key cannot be moved to another row.
     */
    public static function wrap(DataKey $key): string
    {
        return Crypt::encryptString(json_encode([
            'p' => $key->profileId,
            'v' => $key->version,
            'k' => base64_encode($key->bytes()),
        ], JSON_THROW_ON_ERROR));
    }

    public static function unwrap(int $profileId, int $version, string $wrapped): DataKey
    {
        try {
            $payload = json_decode(Crypt::decryptString($wrapped), true, 2, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new AmountDecryptionException("Unable to unwrap amount key version {$version} for profile {$profileId}.");
        }

        if (! is_array($payload)
            || ($payload['p'] ?? null) !== $profileId
            || ($payload['v'] ?? null) !== $version
            || ! is_string($payload['k'] ?? null)
        ) {
            throw new AmountDecryptionException("Wrapped amount key version {$version} does not belong to profile {$profileId}.");
        }

        $bytes = base64_decode($payload['k'], true);

        if ($bytes === false || strlen($bytes) !== DataKey::LENGTH) {
            throw new AmountDecryptionException("Wrapped amount key version {$version} for profile {$profileId} is malformed.");
        }

        return new DataKey($profileId, $version, $bytes);
    }

    private function profileIdOf(string $table, int $id): int
    {
        $profileId = DB::table($table)->where('id', $id)->value('profile_id');

        if ($profileId === null) {
            throw new RuntimeException("Unable to resolve the profile of {$table} #{$id}.");
        }

        return (int) $profileId;
    }
}
