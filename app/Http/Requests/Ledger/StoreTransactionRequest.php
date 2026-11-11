<?php

namespace App\Http\Requests\Ledger;

class StoreTransactionRequest extends LedgerRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'envelope_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'event_at' => ['required', 'date'],
        ];
    }
}
