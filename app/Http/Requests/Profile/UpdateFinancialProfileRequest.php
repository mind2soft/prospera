<?php

namespace App\Http\Requests\Profile;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Renaming a profile (owner only).
 */
class UpdateFinancialProfileRequest extends StoreFinancialProfileRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('profile'));
    }
}
