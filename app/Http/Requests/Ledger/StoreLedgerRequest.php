<?php

namespace App\Http\Requests\Ledger;

use App\Http\Requests\Concerns\AuthorizesPayloadProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new ledger in the payload's profile (profile owner only, authorized
 * before the payload is validated). The starting balance is always
 * positive; starting_balance_type selects the stored sign (cash | debt).
 */
class StoreLedgerRequest extends FormRequest
{
    use AuthorizesPayloadProfile;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'profile_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => [
                'required',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
                        return;
                    }

                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                    if ($date !== false && $date->format('Y-m-d') === $value && $date->format('d') !== '01') {
                        $fail(__('validation.first_day_of_month'));
                    }
                },
            ],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
                        return;
                    }

                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                    if ($date !== false && $date->format('Y-m-d') === $value && $date->format('d') !== $date->format('t')) {
                        $fail(__('validation.last_day_of_month'));
                    }
                },
            ],
            'starting_balance' => ['required', 'integer', 'min:0', 'max:999999999'],
            'starting_balance_type' => ['required', 'string', Rule::in(['cash', 'debt'])],
        ];
    }
}
