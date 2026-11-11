<?php

namespace App\Models\Concerns;

use App\Models\Builders\GuardedBuilder;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountDecryptionException;
use App\Security\Amounts\AmountKeyRing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;

/**
 * Money columns encrypted at rest (docs/encryption.md). Using models
 * implement HasEncryptedAmounts.
 *
 * The attribute holds the plaintext int between assignment and persistence;
 * it is encrypted when Eloquent builds the INSERT/UPDATE payload
 * (getAttributesForInsert / getDirtyForUpdate), so saveQuietly() and
 * withoutEvents() cannot bypass it. Stored values are decrypted on read and
 * any failure throws — an amount never silently reads as 0 or null.
 *
 * Changing an attribute bound into the encryption context (e.g. a
 * transaction's envelope) requires assigning the amount in the same save, so
 * a stale amount is never re-encrypted behind the caller's back.
 *
 * Bulk Eloquent writes go through GuardedBuilder, which only lets
 * ciphertext reach these columns and refuses context changes that would not
 * re-write them. DB::table()/toBase() writes bypass Eloquent and must never
 * touch them; nor may SQL aggregate, sort or filter on them.
 */
trait EncryptsAmounts
{
    /**
     * Decrypted amounts per column, memoized against the exact raw inputs
     * of the decryption (stored payload, raw context attributes, original
     * values): any change to them misses the memo and decrypts afresh, so
     * the context-change guard keeps applying. Laravel's own accessor cache
     * (`shouldCache`) is not used: it writes cached values back into the
     * attributes through the mutator, turning the stored ciphertext into a
     * plaintext int that would silently re-encrypt in a changed context.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private array $decryptedAmounts = [];

    /**
     * The encryption context of a column, from raw attribute values (a model's
     * attributes or a database row).
     *
     * @param  array<string, mixed>  $attributes
     */
    abstract public static function amountContextFor(string $column, array $attributes, int $profileId): AmountContext;

    /**
     * The financial profile owning the amounts, from raw attribute values.
     *
     * @param  array<string, mixed>  $attributes
     */
    abstract protected function amountProfileId(array $attributes): int;

    /**
     * Accessor/mutator for an encrypted column.
     *
     * @return Attribute<int, mixed>
     */
    protected function encryptedAmount(string $column): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): int => $this->decryptStoredAmount($column, $value),
            set: fn (mixed $value): int => self::plainAmount($column, $value),
        );
    }

    /**
     * Encrypt pending plaintext amounts right before Eloquent writes the row
     * (and re-encrypt raw ciphertext set without the mutator).
     */
    public function encryptAmountsForPersistence(): void
    {
        $ring = app(AmountKeyRing::class);

        foreach (static::encryptedAmountColumns() as $column) {
            $raw = $this->attributes[$column] ?? null;

            if (is_int($raw)) {
                $this->attributes[$column] = $ring->encrypt($raw, $this->currentAmountContext($column));

                continue;
            }

            if (! is_string($raw)) {
                throw new LogicException("{$this->getTable()}.{$column} must be set before saving.");
            }

            if ($this->exists && $raw === $this->getRawOriginal($column)) {
                if ($this->amountContextChanged($column)) {
                    throw new LogicException(
                        "Changing {$this->getTable()} ".implode('/', static::amountContextAttributes())
                        ." re-binds the encrypted {$column}: assign {$column} in the same save."
                    );
                }

                continue;
            }

            // A raw ciphertext set without the mutator (setRawAttributes) must
            // authenticate in this row's context before being re-encrypted.
            $this->attributes[$column] = $ring->encrypt(
                $ring->decrypt($raw, $this->currentAmountContext($column)),
                $this->currentAmountContext($column),
            );
        }
    }

    /**
     * Copies carry the plaintext amounts, encrypted afresh in their own
     * context when saved.
     *
     * @param  array<int, string>|null  $except
     * @return static
     */
    public function replicate(?array $except = null)
    {
        $copy = parent::replicate($except);

        foreach (static::encryptedAmountColumns() as $column) {
            if (array_key_exists($column, $copy->getAttributes())) {
                $copy->setAttribute($column, $this->getAttribute($column));
            }
        }

        return $copy;
    }

    /**
     * @param  Builder  $query
     * @return GuardedBuilder<$this>
     */
    public function newEloquentBuilder($query)
    {
        return (new GuardedBuilder($query))->setModel($this);
    }

    /**
     * The model's own update: encryptAmountsForPersistence() has checked
     * context changes precisely (e.g. a time change within the same day
     * keeps a transaction's context), so the builder's coarse check is
     * relaxed for this query only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return bool
     */
    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        if ($query instanceof GuardedBuilder) {
            $query->forModelSave();
        }

        return parent::performUpdate($query);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        $this->encryptAmountsForPersistence();

        return parent::getAttributesForInsert();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate()
    {
        $this->encryptAmountsForPersistence();

        return parent::getDirtyForUpdate();
    }

    private function decryptStoredAmount(string $column, mixed $value): int
    {
        if (is_int($value)) {
            return $value; // assigned, not yet persisted
        }

        if (! AmountCipher::looksEncrypted($value)) {
            throw new AmountDecryptionException("{$this->getTable()}.{$column} is not an encrypted amount.");
        }

        /** @var string $value */
        $memoKey = $this->decryptionMemoKey($column, $value);

        if (isset($this->decryptedAmounts[$column]) && $this->decryptedAmounts[$column][0] === $memoKey) {
            return $this->decryptedAmounts[$column][1];
        }

        $useOriginalContext = $this->exists
            && $value === $this->getRawOriginal($column)
            && $this->amountContextChanged($column);

        $amount = app(AmountKeyRing::class)->decrypt(
            $value,
            $useOriginalContext ? $this->originalAmountContext($column) : $this->currentAmountContext($column),
        );

        $this->decryptedAmounts[$column] = [$memoKey, $amount];

        return $amount;
    }

    /**
     * Everything the decryption of the column depends on, from the raw
     * attribute arrays (no casting): the payload, the current and original
     * context attributes, the original payload and whether the row exists.
     */
    private function decryptionMemoKey(string $column, string $payload): string
    {
        $inputs = [$payload, $this->exists, $this->original[$column] ?? null];

        foreach (static::amountContextAttributes() as $attribute) {
            $inputs[] = $this->attributes[$attribute] ?? null;
            $inputs[] = $this->original[$attribute] ?? null;
        }

        return serialize($inputs);
    }

    /**
     * Decrypted amounts never leave the process with a serialized model.
     *
     * @return array<int, string>
     */
    public function __sleep()
    {
        $this->decryptedAmounts = [];

        return parent::__sleep();
    }

    private function currentAmountContext(string $column): AmountContext
    {
        $attributes = $this->getAttributes();

        return static::amountContextFor($column, $attributes, $this->amountProfileId($attributes));
    }

    private function originalAmountContext(string $column): AmountContext
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $this->getRawOriginal();

        return static::amountContextFor($column, $attributes, $this->amountProfileId($attributes));
    }

    /**
     * Whether pending attribute changes alter the column's bound context (a
     * transaction's time of day, for instance, does not).
     */
    private function amountContextChanged(string $column): bool
    {
        return $this->exists
            && $this->isDirty(static::amountContextAttributes())
            && $this->currentAmountContext($column)->aad(1) !== $this->originalAmountContext($column)->aad(1);
    }

    private static function plainAmount(string $column, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?(?:0|[1-9]\d{0,17})$/D', $value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException("{$column} must be an integer amount.");
    }
}
