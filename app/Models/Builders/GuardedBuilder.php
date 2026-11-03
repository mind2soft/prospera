<?php

namespace App\Models\Builders;

use App\Models\Contracts\HasEncryptedAmounts;
use App\Models\Contracts\HasImmutableColumns;
use App\Security\Amounts\AmountCipher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Eloquent builder guarding bulk writes that would break encrypted amounts
 * (docs/encryption.md):
 *
 * - encrypted amount columns only accept ciphertext (as model saves write
 *   it); SQL arithmetic, upserts and INSERT … SELECT on them are refused;
 * - columns bound into the amounts' encryption context only change together
 *   with freshly written ciphertext for every amount column;
 * - immutable columns (a resource's profile) never change.
 *
 * Writes through DB::table() or toBase() bypass Eloquent and are not covered.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class GuardedBuilder extends Builder
{
    /**
     * Set for a model's own save (EncryptsAmounts::performUpdate), which has
     * already checked context changes precisely; consumed by the next
     * update().
     */
    private bool $modelSave = false;

    /**
     * @internal for EncryptsAmounts::performUpdate() only
     *
     * @return $this
     */
    public function forModelSave(): static
    {
        $this->modelSave = true;

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        try {
            $this->assertUpdatable($values, 'update');
        } finally {
            $this->modelSave = false;
        }

        return parent::update($values);
    }

    /**
     * @param  string|array<int, string>|null  $column
     * @return int|false
     */
    public function touch($column = null)
    {
        $columns = $column
            ? Arr::wrap($column)
            : array_filter([$this->model->usesTimestamps() ? $this->model->getUpdatedAtColumn() : null]);

        $this->assertUpdatable(array_fill_keys($columns, $this->model->freshTimestampString()), 'touch');

        return parent::touch($column);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        $this->assertUpdatable($values, 'updateFrom');

        return $this->toBase()->updateFrom($values);
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  array<array-key, mixed>|callable  $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        $this->assertInsertable($attributes, 'updateOrInsert');

        if (is_callable($values)) {
            $callback = $values;
            $values = function (bool $exists) use ($callback): array {
                $resolved = (array) $callback($exists);
                $this->assertUpdatable($resolved, 'updateOrInsert');

                return $resolved;
            };
        } else {
            $this->assertUpdatable($values, 'updateOrInsert');
        }

        return $this->toBase()->updateOrInsert($attributes, $values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->refuseColumns(array_keys(is_array(reset($values)) ? (array) reset($values) : $values), 'upsert');
        $this->refuseColumns($update ?? [], 'upsert');

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @param  array<array-key, mixed>  $extra
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuseColumns([$column], 'increment');
        $this->assertUpdatable($extra, 'increment');

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  array<array-key, mixed>  $extra
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuseColumns([$column], 'decrement');
        $this->assertUpdatable($extra, 'decrement');

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     * @return int
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuseColumns(array_keys($columns), 'increment');
        $this->assertUpdatable($extra, 'increment');

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     * @return int
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuseColumns(array_keys($columns), 'decrement');
        $this->assertUpdatable($extra, 'decrement');

        return parent::decrementEach($columns, $extra);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $this->assertInsertableRows($values, 'insert');

        return $this->toBase()->insert($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int|string
    {
        $this->assertInsertable($values, 'insertGetId');

        return $this->toBase()->insertGetId($values, $sequence);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        $this->assertInsertableRows($values, 'insertOrIgnore');

        return $this->toBase()->insertOrIgnore($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  non-empty-array<non-empty-string>  $returning
     * @param  non-empty-string|non-empty-array<non-empty-string>|null  $uniqueBy
     * @return Collection<array-key, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        $this->assertInsertableRows($values, 'insertOrIgnoreReturning');

        return $this->toBase()->insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->refuseColumns($columns, 'insertUsing');

        return $this->toBase()->insertUsing($columns, $query);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->refuseColumns($columns, 'insertOrIgnoreUsing');

        return $this->toBase()->insertOrIgnoreUsing($columns, $query);
    }

    /**
     * @return list<string>
     */
    private function encryptedColumns(): array
    {
        return $this->model instanceof HasEncryptedAmounts ? $this->model::encryptedAmountColumns() : [];
    }

    /**
     * @return list<string>
     */
    private function contextColumns(): array
    {
        return $this->model instanceof HasEncryptedAmounts ? $this->model::amountContextAttributes() : [];
    }

    /**
     * @return list<string>
     */
    private function immutableColumns(): array
    {
        return $this->model instanceof HasImmutableColumns ? $this->model::immutableColumns() : [];
    }

    /**
     * The unqualified, lower-case column name (SQL column names are
     * case-insensitive), or null for a non-string key.
     */
    private static function columnName(mixed $column): ?string
    {
        if (! is_string($column)) {
            return null;
        }

        $dot = strrpos($column, '.');

        return strtolower(trim($dot === false ? $column : substr($column, $dot + 1), ' `"[]'));
    }

    /**
     * @param  list<string>  $columns
     */
    private static function isOneOf(mixed $column, array $columns): bool
    {
        $name = self::columnName($column);

        return $name !== null && in_array($name, array_map(strtolower(...), $columns), true);
    }

    /**
     * Refuse any write (key or value of the array naming a column) to the
     * encrypted, context or immutable columns.
     *
     * @param  array<array-key, mixed>  $columns
     */
    private function refuseColumns(array $columns, string $operation): void
    {
        $guarded = [...$this->encryptedColumns(), ...$this->contextColumns(), ...$this->immutableColumns()];

        foreach ($columns as $key => $column) {
            if (self::isOneOf($column, $guarded) || self::isOneOf($key, $guarded)) {
                throw new LogicException("{$operation} cannot write encrypted amounts or their context columns; save the models instead (docs/encryption.md).");
            }
        }
    }

    /**
     * Encrypted columns may only receive ciphertext.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function assertInsertable(array $values, string $operation): void
    {
        foreach ($values as $column => $value) {
            if (self::isOneOf($column, $this->encryptedColumns()) && ! AmountCipher::looksEncrypted($value)) {
                throw new LogicException("{$operation}: encrypted amount column [{$column}] only accepts ciphertext; save the models instead (docs/encryption.md).");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values  one row or a list of rows
     */
    private function assertInsertableRows(array $values, string $operation): void
    {
        foreach (is_array(reset($values)) ? $values : [$values] as $row) {
            $this->assertInsertable((array) $row, $operation);
        }
    }

    /**
     * Like inserts, plus: immutable columns never change, and context columns
     * only change when every amount column is re-written in the same update.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function assertUpdatable(array $values, string $operation): void
    {
        $this->assertInsertable($values, $operation);

        $columns = array_filter(array_map(self::columnName(...), array_keys($values)));

        foreach ($columns as $column) {
            if (self::isOneOf($column, $this->immutableColumns())) {
                throw new LogicException("{$operation} cannot change immutable column [{$column}] (docs/encryption.md).");
            }
        }

        $changesContext = array_filter($columns, fn (string $column): bool => self::isOneOf($column, $this->contextColumns())) !== [];
        $rewritesAmounts = array_diff(array_map(strtolower(...), $this->encryptedColumns()), $columns) === [];

        if ($changesContext && ! $rewritesAmounts && ! $this->modelSave) {
            throw new LogicException("{$operation} cannot change the encryption context of amounts without re-writing them; save the models instead (docs/encryption.md).");
        }
    }
}
