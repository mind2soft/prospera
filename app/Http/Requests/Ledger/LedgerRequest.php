<?php

namespace App\Http\Requests\Ledger;

use App\Models\Ledger;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A request on a route-bound ledger the caller must at least view
 * (LedgerPolicy); finer checks stay with the controller.
 */
abstract class LedgerRequest extends FormRequest
{
    public function ledger(): Ledger
    {
        /** @var Ledger $ledger */
        $ledger = $this->route('ledger');

        return $ledger;
    }

    public function authorize(): Response
    {
        return Gate::inspect('view', $this->ledger());
    }
}
