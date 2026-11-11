<?php

namespace App\Http\Requests\Budget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Profile ownership is checked by the controller once the payload is valid.
 */
class StoreBudgetRequest extends FormRequest
{
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
