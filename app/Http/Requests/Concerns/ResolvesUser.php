<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Requests served behind the auth middleware: the authenticated user is
 * part of the request's contract, so the invariant is made explicit —
 * fail closed with 401 if it ever stops holding.
 *
 * @mixin FormRequest
 */
trait ResolvesUser
{
    /**
     * The authenticated user (a 401 response otherwise).
     */
    protected function currentUser(): User
    {
        $user = $this->user();

        abort_if($user === null, 401);

        return $user;
    }
}
