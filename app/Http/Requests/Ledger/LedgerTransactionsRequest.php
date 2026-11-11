<?php

namespace App\Http\Requests\Ledger;

use App\Http\Controllers\LedgerController;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class LedgerTransactionsRequest extends LedgerRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ];
    }

    /**
     * Every row read is hydrated and decrypted: the range is bounded to a
     * ledger's yearly span (the UI asks for one month at a time).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = Carbon::parse((string) $this->input('from_date'))->startOfDay();
                $to = Carbon::parse((string) $this->input('to_date'))->startOfDay();

                if ($from->diffInDays($to) >= LedgerController::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to_date', __('The date range cannot exceed :days days.', [
                        'days' => LedgerController::MAX_RANGE_DAYS,
                    ]));
                }
            },
        ];
    }
}
