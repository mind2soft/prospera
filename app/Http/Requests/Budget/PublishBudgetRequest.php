<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\Concerns\ResolvesLocalCalendar;
use App\Models\Budget;
use Illuminate\Validation\ValidationException;

class PublishBudgetRequest extends BudgetRequest
{
    use ResolvesLocalCalendar;

    protected function ability(): string
    {
        return 'publish';
    }

    protected function ensureState(Budget $budget): void
    {
        if (! $budget->isDraft()) {
            throw ValidationException::withMessages([
                'budget' => __('This budget is already published.'),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->timezoneRules(),
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
        ];
    }
}
