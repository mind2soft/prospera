<?php

namespace App\Http\Requests\Ledger;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Recording a transaction takes contributor+ on the ledger (LedgerPolicy
 * `record`), authorized before the payload is validated and before the
 * ledger's state is reported (a viewer gets a 403, not "closed"). The
 * envelope permission is checked by the controller.
 */
class StoreTransactionRequest extends LedgerRequest
{
    public function authorize(): Response
    {
        $view = parent::authorize();

        return $view->denied() ? $view : Gate::inspect('record', $this->ledger());
    }

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
