<?php

namespace App\Models;

use App\Models\Concerns\EncryptsAmounts;
use App\Models\Contracts\HasEncryptedAmounts;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountKeyRing;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $ledger_id
 * @property int|null $user_id
 * @property int $envelope_id
 * @property int|float $amount currency units; encrypted as minor units at rest (docs/encryption.md)
 * @property CarbonImmutable $event_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at pending deletion, restorable within the undo window
 */
class Transaction extends Model implements HasEncryptedAmounts
{
    use EncryptsAmounts;
    use SoftDeletes;

    /**
     * How long a deleted transaction stays restorable before
     * `transactions:purge-deleted` removes it for good
     * (docs/undoable-deletion.md).
     */
    public const UNDO_WINDOW_SECONDS = 180;

    protected $fillable = ['ledger_id', 'user_id', 'envelope_id', 'amount', 'event_at'];

    protected $casts = [
        'event_at' => 'datetime',
    ];

    /**
     * @return Attribute<int|float, mixed>
     */
    protected function amount(): Attribute
    {
        return $this->encryptedAmount('amount');
    }

    protected function decodeAmount(string $column, int $amount): int|float
    {
        return $column === 'amount' ? Money::fromMinorUnits($amount) : $amount;
    }

    protected function encodeAmount(string $column, mixed $amount): int
    {
        if (! is_int($amount) && ! is_float($amount) && ! is_string($amount)) {
            throw new InvalidArgumentException('Transaction amount must be a number.');
        }

        return Money::toMinorUnits($amount);
    }

    /**
     * Whether this pending deletion can still be undone.
     */
    public function isRestorable(): bool
    {
        return $this->deleted_at !== null
            && $this->deleted_at->greaterThan(now()->subSeconds(self::UNDO_WINDOW_SECONDS));
    }

    /**
     * Permanently delete the pending deletions past the undo window. Returns
     * the number of purged rows.
     */
    public static function purgeExpiredDeletions(): int
    {
        return static::onlyTrashed()
            ->where('deleted_at', '<=', now()->subSeconds(self::UNDO_WINDOW_SECONDS))
            ->forceDelete();
    }

    /**
     * Permanently delete the pending deletions on the given envelopes, before
     * a change that a restored row would contradict: the envelope's deletion
     * (restrictive foreign key) or a direction change (stored sign).
     *
     * @param  array<int, int>  $envelopeIds
     */
    public static function purgeDeletionsOnEnvelopes(array $envelopeIds): int
    {
        if ($envelopeIds === []) {
            return 0;
        }

        return static::onlyTrashed()->whereIn('envelope_id', $envelopeIds)->forceDelete();
    }

    /**
     * Permanently delete the pending deletions in the profile's ledgers on
     * the member's envelopes, before the member leaves the profile: the
     * envelope hand-over and grant pruning only see live references, and a
     * row restored afterwards would point at an envelope the profile no
     * longer controls.
     */
    public static function purgeDeletionsOnMemberEnvelopes(FinancialProfile $profile, User $member): int
    {
        return static::onlyTrashed()
            ->whereIn('ledger_id', Ledger::query()->where('profile_id', $profile->id)->select('id'))
            ->whereIn('envelope_id', Envelope::query()->where('user_id', $member->id)->select('id'))
            ->forceDelete();
    }

    public static function encryptedAmountColumns(): array
    {
        return ['amount'];
    }

    public static function amountContextAttributes(): array
    {
        return ['ledger_id', 'envelope_id', 'event_at'];
    }

    public static function amountContextFor(string $column, array $attributes, int $profileId): AmountContext
    {
        return new AmountContext('transactions', $column, $profileId, [
            'l' => AmountContext::id($attributes['ledger_id'] ?? null, 'ledger_id'),
            'e' => AmountContext::id($attributes['envelope_id'] ?? null, 'envelope_id'),
            'd' => AmountContext::date($attributes['event_at'] ?? null),
        ]);
    }

    protected function amountProfileId(array $attributes): int
    {
        return app(AmountKeyRing::class)->profileIdForLedger(
            AmountContext::id($attributes['ledger_id'] ?? null, 'ledger_id'),
        );
    }

    /**
     * The ledger this transaction belongs to.
     *
     * @return BelongsTo<Ledger, $this>
     */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    /**
     * The user who entered this transaction.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The envelope this transaction draws from.
     *
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class, 'envelope_id');
    }
}
