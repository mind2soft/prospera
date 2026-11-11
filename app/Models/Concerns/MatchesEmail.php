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
