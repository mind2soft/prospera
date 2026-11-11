<?php

namespace App\Models;

use App\Models\Concerns\EncryptsAmounts;
use App\Models\Contracts\HasEncryptedAmounts;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountKeyRing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $budget_id
 * @property int $envelope_id
 * @property int $amount encrypted at rest (docs/encryption.md)
 * @property string $type
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class BudgetEnvelope extends Model implements HasEncryptedAmounts
{
    use EncryptsAmounts;

    public const TYPE_FIXED = 'fixed';

    public const TYPE_VARIABLE = 'variable';

    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_VARIABLE,
    ];

    protected $fillable = ['budget_id', 'envelope_id', 'amount', 'type'];

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
        return ['budget_id', 'envelope_id'];
    }

    public static function amountContextFor(string $column, array $attributes, int $profileId): AmountContext
    {
        return new AmountContext('budget_envelopes', $column, $profileId, [
            'b' => AmountContext::id($attributes['budget_id'] ?? null, 'budget_id'),
            'e' => AmountContext::id($attributes['envelope_id'] ?? null, 'envelope_id'),
        ]);
    }

    protected function amountProfileId(array $attributes): int
    {
        return app(AmountKeyRing::class)->profileIdForBudget(
            AmountContext::id($attributes['budget_id'] ?? null, 'budget_id'),
        );
    }

    /**
     * The budget this assignment belongs to.
     *
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * The envelope this assignment points to.
     *
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class, 'envelope_id');
    }
}
