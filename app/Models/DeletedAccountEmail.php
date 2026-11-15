<?php

namespace App\Models;

use App\Models\Concerns\MatchesEmail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The email address of a deleted account, blacklisted so it cannot be
 * recycled — neither to register a new account, nor as another account's
 * email address (issue #71).
 *
 * There is no self-service release: a legitimate owner who wants their
 * email back must contact the operator (docs/shared-finances.md —
 * Account termination).
 *
 * @property int $id
 * @property string $email
 * @property CarbonImmutable|null $account_created_at when the deleted account was created
 * @property CarbonImmutable $account_deleted_at when the account was deleted
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['email', 'account_created_at', 'account_deleted_at'])]
class DeletedAccountEmail extends Model
{
    use MatchesEmail;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_created_at' => 'datetime',
            'account_deleted_at' => 'datetime',
        ];
    }
}
