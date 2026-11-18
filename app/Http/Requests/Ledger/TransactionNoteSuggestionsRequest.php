<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Note suggestions are offered while recording: they take the same
 * contributor+ ledger permission as recording (LedgerPolicy `record`). The
 * envelope permission is checked by the controller.
 */
class TransactionNoteSuggestionsRequest extends LedgerRequest
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
        ];
    }
}
