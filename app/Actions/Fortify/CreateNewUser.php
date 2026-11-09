<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * When a valid invite key is carried through registration, the invite is
     * reserved for the new account and the personal "Default profile" is
     * **not** created — the shared profile becomes the account's only (and
     * default) profile once it verifies its email; if the invite is gone by
     * then (revoked, pruned, or the email changed), the default profile is
     * seeded then instead. See
     * FinancialProfileInvite::completePendingFor().
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invite_key' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        $inviteKey = empty($input['invite_key']) ? null : (string) $input['invite_key'];

        // Fail fast before hashing the password / opening a transaction.
        if ($inviteKey !== null) {
            $this->assertUsable(FinancialProfileInvite::findByKey($inviteKey), (string) $input['email']);
        }

        return DB::transaction(function () use ($input, $inviteKey): User {
            // Re-verify the invite under a lock: it may have been consumed,
            // revoked, reserved or expired since the check above.
            $invite = null;

            if ($inviteKey !== null) {
                /** @var FinancialProfileInvite|null $invite */
                $invite = FinancialProfileInvite::query()
                    ->where('invite_key', FinancialProfileInvite::keyHash($inviteKey))
                    ->lockForUpdate()
                    ->first();

                $this->assertUsable($invite, (string) $input['email']);
            }

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            // Invite registrations skip the personal default profile: the
            // shared profile they are joining becomes their only profile on
            // email verification (and if the invite is gone by then, the
            // default profile is seeded at that point instead).
            if ($invite === null) {
                FinancialProfile::createDefaultFor($user);
            }

            $invite?->forceFill(['pending_user_id' => $user->id])->save();

            return $user;
        });
    }

    /**
     * @phpstan-assert FinancialProfileInvite $invite
     */
    private function assertUsable(?FinancialProfileInvite $invite, string $email): void
    {
        $error = $invite === null ? 'This invite is invalid.' : $invite->usageError($email);

        if ($error !== null) {
            throw ValidationException::withMessages(['invite_key' => __($error)]);
        }
    }
}
