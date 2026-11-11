<?php

namespace App\Http\Requests\Budget;

use App\Models\Budget;
use App\Models\BudgetEnvelope;
use Illuminate\Validation\Rule;

class UpdateBudgetEnvelopeRequest extends BudgetRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    protected function ensureState(Budget $budget): void
    {
        $budget->ensureDraft();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'type' => ['sometimes', 'string', Rule::in(BudgetEnvelope::TYPES)],
        ];
    }
}
