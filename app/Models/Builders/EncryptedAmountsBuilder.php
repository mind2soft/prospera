<?php

namespace App\Models\Builders;

use App\Models\Contracts\HasEncryptedAmounts;
use App\Security\Amounts\AmountCipher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Eloquent builder of models with encrypted amounts: bulk writes may only
 * store ciphertext in the encrypted columns (as model saves do), and SQL
 * arithmetic or upserts on them are refused. Query-builder writes through
 * DB::table() or toBase() are not covered (docs/encryption.md).
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class EncryptedAmountsBuilder extends Builder
{
    /**
     * @param  array<array-key, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        $this->assertCiphertext($values);

        return parent::update($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->refuse(array_keys(is_array(reset($values)) ? (array) reset($values) : $values), 'upsert');
        $this->refuse($update ?? [], 'upsert');

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @param  array<array-key, mixed>  $extra
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuse([$column], 'increment');
        $this->assertCiphertext($extra);

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  array<array-key, mixed>  $extra
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuse([$column], 'decrement');
        $this->assertCiphertext($extra);

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     * @return int
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuse(array_keys($columns), 'increment');
        $this->assertCiphertext($extra);

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     * @return int
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuse(array_keys($columns), 'decrement');
        $this->assertCiphertext($extra);

        return parent::decrementEach($columns, $extra);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $this->assertCiphertextRows($values);

        return $this->toBase()->insert($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int|string
    {
        $this->assertCiphertext($values);

        return $this->toBase()->insertGetId($values, $sequence);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        $this->assertCiphertextRows($values);

        return $this->toBase()->insertOrIgnore($values);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->refuse($columns, 'insertUsing');

        return $this->toBase()->insertUsing($columns, $query);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->refuse($columns, 'insertOrIgnoreUsing');

        return $this->toBase()->insertOrIgnoreUsing($columns, $query);
    }

    /**
     * @return list<string>
     */
    private function encryptedColumns(): array
    {
        return $this->model instanceof HasEncryptedAmounts ? $this->model::encryptedAmountColumns() : [];
    }

    private function isEncryptedColumn(mixed $column): bool
    {
        if (! is_string($column)) {
            return false;
        }

        $name = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

        return in_array($name, $this->encryptedColumns(), true);
    }

    /**
     * @param  array<array-key, mixed>  $columns  column names (values or keys)
     */
    private function refuse(array $columns, string $operation): void
    {
        foreach ($columns as $key => $column) {
            if ($this->isEncryptedColumn($column) || $this->isEncryptedColumn($key)) {
                throw new LogicException("{$operation} cannot write encrypted amount columns; save the models instead (docs/encryption.md).");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function assertCiphertext(array $values): void
    {
        foreach ($values as $column => $value) {
            if ($this->isEncryptedColumn($column) && ! AmountCipher::looksEncrypted($value)) {
                throw new LogicException("Encrypted amount column [{$column}] can only be written as ciphertext; save the models instead (docs/encryption.md).");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values  one row or a list of rows
     */
    private function assertCiphertextRows(array $values): void
    {
        $rows = is_array(reset($values)) ? $values : [$values];

        foreach ($rows as $row) {
            $this->assertCiphertext((array) $row);
        }
    }
}
