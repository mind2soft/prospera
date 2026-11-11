<?php

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Profile ownership is checked by the controller once the payload is valid.
 * The starting balance is always positive; starting_balance_type selects
 * the stored sign (cash | debt).
 */
class StoreLedgerRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'profile_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'starting_balance' => ['required', 'integer', 'min:0', 'max:999999999'],
            'starting_balance_type' => ['required', 'string', Rule::in(['cash', 'debt'])],
        ];
    }
}
