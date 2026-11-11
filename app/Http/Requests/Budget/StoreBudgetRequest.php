<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\Concerns\AuthorizesPayloadProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new draft in the payload's profile (profile owner only, authorized
 * before the payload is validated).
 */
class StoreBudgetRequest extends FormRequest
{
    use AuthorizesPayloadProfile;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'profile_id' => ['required', 'integer'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ];
    }
}
