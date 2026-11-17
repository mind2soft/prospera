<?php

declare(strict_types=1);

namespace App\Security\Amounts;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Per-request access to profile- and envelope-scoped amount data keys. Keys are unwrapped
 * with the application key (Crypt, honouring APP_PREVIOUS_KEYS) and memoized
 * in memory for the current request/job only — never cached or persisted in
 * plaintext. Registered as a scoped binding.
 */
final class AmountKeyRing
{
    /** @var array<string, array<int, DataKey>> */
    private array $keys = [];

    /** @var array<string, int> */
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
    public function activeKey(int $ownerId, AmountKeyScope $scope = AmountKeyScope::Profile): DataKey
    {
        $cache = $scope->value.':'.$ownerId;
        if (isset($this->activeVersions[$cache])) {
            return $this->key($ownerId, $this->activeVersions[$cache], $scope);
        }

        $row = $scope->keyModel()::query()
            ->where($scope->column(), $ownerId)
            ->whereNull('retired_at')
            ->orderByDesc('version')
            ->first();

        if ($row === null) {
            throw new RuntimeException("No active amount key for {$scope->value} {$ownerId}.");
        }

        $key = self::unwrap($ownerId, $row->version, $row->wrapped_key, $scope);

        $this->keys[$cache][$row->version] = $key;
        $this->activeVersions[$cache] = $row->version;

        return $key;
    }

    /**
     * Any existing key version of the profile (retired ones included), as
     * needed to decrypt amounts written before a rotation.
     */
    public function key(int $ownerId, int $version, AmountKeyScope $scope = AmountKeyScope::Profile): DataKey
    {
        $cache = $scope->value.':'.$ownerId;
        if (isset($this->keys[$cache][$version])) {
            return $this->keys[$cache][$version];
        }

        $row = $scope->keyModel()::query()
            ->where($scope->column(), $ownerId)
            ->where('version', $version)
            ->first();

        if ($row === null) {
            throw new AmountDecryptionException("Unknown amount key version {$version} for {$scope->value} {$ownerId}.");
        }

        return $this->keys[$cache][$version] = self::unwrap($ownerId, $row->version, $row->wrapped_key, $scope);
    }

    /**
     * Generate and store the profile's next key version. Not memoized: the
     * caller's transaction may still roll back.
     */
    public function createKey(int $ownerId, AmountKeyScope $scope = AmountKeyScope::Profile): DataKey
    {
        $maximum = $scope->keyModel()::query()->where($scope->column(), $ownerId)->max('version');
        $version = $maximum === null ? 1 : AmountContext::id($maximum, 'version') + 1;

        $key = DataKey::generate($ownerId, $version, $scope);

        $scope->keyModel()::create([
            $scope->column() => $ownerId,
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
        return $this->cipher->encrypt($amount, $this->activeKey($context->ownerId, $context->scope), $context);
    }

    public function decrypt(string $payload, AmountContext $context): int
    {
        $version = AmountCipher::keyVersionOf($payload, $context);

        return $this->cipher->decrypt($payload, $this->key($context->ownerId, $version, $context->scope), $context);
    }

    /**
     * Drop memoized keys and lookups (all, or one profile's keys).
     */
    public function forget(?int $ownerId = null, AmountKeyScope $scope = AmountKeyScope::Profile): void
    {
        if ($ownerId === null) {
            $this->keys = $this->activeVersions = $this->ledgerProfiles = $this->budgetProfiles = [];

            return;
        }

        $cache = $scope->value.':'.$ownerId;
        unset($this->keys[$cache], $this->activeVersions[$cache]);
    }

    /**
     * Encrypt a data key with the application key, bound to its profile and
     * version so a wrapped key cannot be moved to another row.
     */
    public static function wrap(DataKey $key): string
    {
        return Crypt::encryptString(json_encode([
            $key->scope->binding() => $key->ownerId,
            'v' => $key->version,
            'k' => base64_encode($key->bytes()),
        ], JSON_THROW_ON_ERROR));
    }

    public static function unwrap(int $ownerId, int $version, string $wrapped, AmountKeyScope $scope = AmountKeyScope::Profile): DataKey
    {
        try {
            $payload = json_decode(Crypt::decryptString($wrapped), true, 2, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new AmountDecryptionException("Unable to unwrap amount key version {$version} for {$scope->value} {$ownerId}.");
        }

        if (! is_array($payload)
            || count($payload) !== 3
            || ($payload[$scope->binding()] ?? null) !== $ownerId
            || ($payload['v'] ?? null) !== $version
            || ! is_string($payload['k'] ?? null)
        ) {
            throw new AmountDecryptionException("Wrapped amount key version {$version} does not belong to {$scope->value} {$ownerId}.");
        }

        $bytes = base64_decode($payload['k'], true);

        if ($bytes === false || strlen($bytes) !== DataKey::LENGTH) {
            throw new AmountDecryptionException("Wrapped amount key version {$version} for {$scope->value} {$ownerId} is malformed.");
        }

        return new DataKey($ownerId, $version, $bytes, $scope);
    }

    private function profileIdOf(string $table, int $id): int
    {
        $profileId = DB::table($table)->where('id', $id)->value('profile_id');

        if ($profileId === null) {
            throw new RuntimeException("Unable to resolve the profile of {$table} #{$id}.");
        }

        return AmountContext::id($profileId, 'profile_id');
    }
}
