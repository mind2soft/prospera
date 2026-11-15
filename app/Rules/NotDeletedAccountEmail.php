<?php

namespace App\Rules;

use App\Models\DeletedAccountEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A deleted account's email address cannot be recycled (issue #71): the
 * match is case-insensitive, and the rule applies to new registrations as
 * well as to another account's email change.
 */
class NotDeletedAccountEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && DeletedAccountEmail::query()->forEmail($value)->exists()) {
            $fail(__('This email address is invalid.'));
        }
    }
}
