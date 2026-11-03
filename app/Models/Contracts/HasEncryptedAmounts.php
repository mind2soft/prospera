<?php

namespace App\Models\Contracts;

/**
 * A model with money columns encrypted at rest (docs/encryption.md); see
 * App\Models\Concerns\EncryptsAmounts.
 */
interface HasEncryptedAmounts
{
    /**
     * The encrypted money columns.
     *
     * @return list<string>
     */
    public static function encryptedAmountColumns(): array;
}
