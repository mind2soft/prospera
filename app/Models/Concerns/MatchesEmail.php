<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Email lookups ignore case: an invite to "Jo@Example.com" is for the
 * account "jo@example.com".
 */
trait MatchesEmail
{
    /**
     * Compare email addresses without regard to case, using PHP's Unicode-aware
     * case conversion for comparisons that happen after records are loaded.
     */
    public static function sameEmail(string $email, string $otherEmail): bool
    {
        return mb_strtolower($email, 'UTF-8') === mb_strtolower($otherEmail, 'UTF-8');
    }

    /**
     * Scope rows whose email matches the given address, ignoring case.
     *
     * @param  Builder<static>  $query
     * @param  literal-string  $column  the email column, qualified when the
     *                                  query joins other tables
     */
    #[Scope]
    protected function forEmail(Builder $query, string $email, string $column = 'email'): void
    {
        $query->whereRaw('lower('.$column.') = ?', [mb_strtolower($email)]);
    }
}
