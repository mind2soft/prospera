<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EncryptsAmounts;
use App\Models\Concerns\HasImmutableProfile;
use App\Models\Concerns\HasProfilePermissions;
use App\Models\Contracts\HasEncryptedAmounts;
use App\Models\Contracts\HasImmutableColumns;
use App\Security\Amounts\AmountContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $name
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property int $starting_balance encrypted at rest (docs/encryption.md)
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $expired_notified_at set when the daily
 *                                                     run first detects the ledger past its end date while open —
 *                                                     at most once ever (issue #50, docs/notifications.md)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Ledger extends Model implements HasEncryptedAmounts, HasImmutableColumns
{
    use EncryptsAmounts;
    use HasImmutableProfile;
    use HasProfilePermissions;

    protected $fillable = ['profile_id', 'name', 'start_date', 'end_date', 'starting_balance', 'closed_at'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
        'expired_notified_at' => 'datetime',
    ];

    /**
     * @return Attribute<int, mixed>
     */
    protected function startingBalance(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): int => $this->decryptStoredAmount('starting_balance', $value),
            set: fn (mixed $value): int => $this->encodeAmount('starting_balance', $value),
        );
    }

    public static function encryptedAmountColumns(): array
    {
        return ['starting_balance'];
    }

    public static function amountContextAttributes(): array
    {
        return ['profile_id'];
    }

    public static function amountContextFor(string $column, array $attributes, int $profileId): AmountContext
    {
        return new AmountContext('ledgers', $column, $profileId);
    }

    protected function amountKeyOwnerId(array $attributes): int
    {
        return AmountContext::id($attributes['profile_id'] ?? null, 'profile_id');
    }

    /**
     * The financial profile this ledger belongs to.
     *
     * @return BelongsTo<FinancialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class);
    }

    /**
     * The ledger's permission rows.
     *
     * @return HasMany<LedgerPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(LedgerPermission::class);
    }

    /**
     * The transactions recorded in this ledger.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Delete the ledger and all its transactions in bulk, pending deletions
     * included: a deleted ledger has nothing left to undo.
     *
     * The optional $confirm callback runs on the freshly locked ledger before
     * any deletion; returning false aborts the operation without writing —
     * the retention prune re-checks eligibility under the lock (issue #61,
     * docs/retention.md).
     */
    public function deleteWithTransactions(?callable $confirm = null): bool
    {
        return (bool) DB::transaction(function () use ($confirm): bool {
            // Direct lifecycle callers must obey the same ledger-first
            // serialization as HTTP writers; not all come via the controller.
            $locked = self::query()->whereKey($this->id)->lockForUpdate()->first();
            if ($locked === null || ($confirm !== null && ! $confirm($locked))) {
                return false;
            }
            $this->transactions()->withTrashed()->forceDelete();
            $locked->delete();

            return true;
        });
    }

    /**
     * Whether the ledger is open (transactions can still be added).
     */
    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Whether the ledger is closed (read-only).
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Whether a transaction can be entered on the given date: the ledger must
     * be open and the date must fall within the ledger's range.
     */
    public function acceptsEventDate(CarbonInterface $date): bool
    {
        return $this->isOpen()
            && $date->between($this->start_date, $this->end_date);
    }
}
