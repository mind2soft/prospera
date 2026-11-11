<?php

namespace App\Http\Requests\Budget;

use App\Models\Budget;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A request on a route-bound budget: authorized through BudgetPolicy, then
 * the budget's state is checked (ensureState) before the payload is
 * validated — the same order the endpoints always used.
 */
abstract class BudgetRequest extends FormRequest
{
    /**
     * The BudgetPolicy ability the request requires.
     */
    abstract protected function ability(): string;

    /**
     * Reject the request when the budget is not in the required state
     * (throws a ValidationException).
     */
    abstract protected function ensureState(Budget $budget): void;

    public function budget(): Budget
    {
        /** @var Budget $budget */
        $budget = $this->route('budget');

        return $budget;
    }

    public function authorize(): Response
    {
        $response = Gate::inspect($this->ability(), $this->budget());

        if ($response->allowed()) {
            $this->ensureState($this->budget());
        }

        return $response;
    }
}
