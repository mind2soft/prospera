<?php

namespace App\Http\Requests\Budget;

use App\Models\Budget;
use Illuminate\Validation\ValidationException;

class CloneBudgetRequest extends BudgetRequest
{
    protected function ability(): string
    {
        return 'clone';
    }

    protected function ensureState(Budget $budget): void
    {
        if ($budget->isDraft()) {
            throw ValidationException::withMessages([
                'budget' => __('Only published budgets can be cloned.'),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
        ];
    }
}
