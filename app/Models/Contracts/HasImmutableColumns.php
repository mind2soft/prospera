<?php

namespace App\Models\Contracts;

/**
 * A model whose listed columns never change after insert; see
 * App\Models\Concerns\HasImmutableProfile.
 */
interface HasImmutableColumns
{
    /**
     * @return list<string>
     */
    public static function immutableColumns(): array;
}
