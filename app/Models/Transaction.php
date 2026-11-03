<?php

namespace App\Models;

use App\Models\Concerns\EncryptsAmounts;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountKeyRing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ledger_id
 * @property int|null $user_id
 * @property int $envelope_id
 * @property int $amount encrypted at rest (docs/encryption.md)
 * @property Carbon $event_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Transaction extends Model
{
    use EncryptsAmounts;

    protected $fillable = ['ledger_id', 'user_id', 'envelope_id', 'amount', 'event_at'];

    protected $casts = [
        'event_at' => 'datetime',
    ];

    /**
     * @return Attribute<int, mixed>
     */
    protected function amount(): Attribute
    {
        return $this->encryptedAmount('amount');
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
