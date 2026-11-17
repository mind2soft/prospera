<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use App\Rules\NotDeletedAccountEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|\Stringable|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'locale' => $this->localeRules(),
        ];
    }

    /**
     * Get the validation rules used to validate the user's preferred
     * locale. A null locale means "follow the browser".
     *
     * @return array<int, ValidationRule|\Stringable|array<mixed>|string>
     */
    protected function localeRules(): array
    {
        return [
            'nullable',
            'string',
            Rule::in(array_keys(config('app.available_locales', []))),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails. The email
     * must not be the address of a deleted account (issue #71): this holds
     * for new registrations, and for another account's email change too —
     * otherwise a blacklisted address could be claimed by simply updating
     * an existing account.
     *
     * @return array<int, ValidationRule|\Stringable|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            new NotDeletedAccountEmail,
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
