<?php

namespace App\Http\Requests\Profile;

use App\Models\FinancialProfileInvite;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Inviting someone to a profile (owner only).
 */
class StoreInviteRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('profile'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in(FinancialProfileInvite::GRANTABLE_ROLES)],
        ];
    }
}
