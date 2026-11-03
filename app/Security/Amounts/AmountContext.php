<?php

namespace App\Security\Amounts;

use InvalidArgumentException;

/**
 * Where an encrypted amount lives: authenticated as AES-GCM additional data
 * so a ciphertext only decrypts in its own table, column, profile and row
 * context (see docs/encryption.md).
 */
final class AmountContext
{
    /**
     * @param  array<string, int|string>  $bindings  ordered single-letter keys; int ids or Y-m-d dates
     */
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly int $profileId,
        public readonly array $bindings = [],
    ) {
        if (! preg_match('/^[a-z_]+$/D', $table) || ! preg_match('/^[a-z_]+$/D', $column)) {
            throw new InvalidArgumentException('Amount context table and column must match [a-z_]+.');
        }

        if ($profileId < 1) {
            throw new InvalidArgumentException('Amount context profile id must be positive.');
        }

        foreach ($bindings as $key => $value) {
            if (! preg_match('/^[a-z]$/D', (string) $key)) {
                throw new InvalidArgumentException('Amount context binding keys must be a single lowercase letter.');
            }

            if (is_int($value) ? $value < 1 : ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                throw new InvalidArgumentException("Amount context binding [{$key}] must be a positive id or a Y-m-d date.");
            }
        }
    }

    /**
     * The canonical additional authenticated data for the given key version.
     * Values cannot contain "|" or "=", so the encoding is unambiguous.
     */
    public function aad(int $keyVersion): string
    {
        $parts = [
            'prospera/amount/'.AmountCipher::FORMAT,
            'v='.$keyVersion,
            't='.$this->table.'.'.$this->column,
            'p='.$this->profileId,
        ];

        foreach ($this->bindings as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return implode('|', $parts);
    }

    /**
     * Normalize a raw timestamp ("Y-m-d H:i:s", "Y-m-d") to its Y-m-d date.
     */
    public static function date(mixed $raw): string
    {
        if (! is_string($raw) || ! preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T]|$)/D', $raw, $matches)) {
            throw new InvalidArgumentException('Amount context date must be a Y-m-d[ H:i:s] string.');
        }

        return $matches[1];
    }

    /**
     * A positive integer id from a raw attribute value.
     */
    public static function id(mixed $raw, string $name): int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^[1-9]\d*$/D', $raw)) {
            return (int) $raw;
        }

        throw new InvalidArgumentException("Amount context attribute [{$name}] is missing or invalid.");
    }
}
