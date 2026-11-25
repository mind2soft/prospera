<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use App\Models\Transaction;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Batch sparse update of manual transactions (issue #123, docs/ledger.md):
 * the caller must at least view the route-bound ledger (LedgerPolicy); the
 * per-transaction update policy (owner: any entry; contributor: own entries)
 * decides each row's outcome. A viewer passes this gate and simply gets
 * per-row `forbidden` results.
 *
 * Omitted fields mean "unchanged". `note` and `clear_note` are mutually
 * exclusive: a blank `note` is never a clear signal — clearing requires the
 * explicit `clear_note` flag.
 */
class BatchUpdateTransactionsRequest extends LedgerRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'transaction_ids' => ['required', 'array', 'min:1', 'max:100'],
            'transaction_ids.*' => ['required', 'integer', 'distinct'],
            'envelope_id' => ['sometimes', 'required', 'integer'],
            // Same shape as recording: a positive amount the caller enters;
            // the stored sign is recomputed from the effective envelope
            // direction (docs/ledger.md — amount sign convention).
            'amount' => ['sometimes', 'required', 'numeric', 'regex:/^(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?$/D', 'gt:0', 'max:999999999.99'],
            'event_at' => ['sometimes', 'required', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.Transaction::NOTE_MAX_LENGTH,
                // Mutually exclusive with clear_note: a blank `note` is
                // never a clear signal (docs/ledger.md).
                Rule::prohibitedIf(fn (): bool => Arr::has($this->all(), 'clear_note')),
            ],
            'clear_note' => ['sometimes', 'boolean',
                Rule::prohibitedIf(fn (): bool => $this->filled('note')),
            ],
            // System-managed or server-assigned fields are never accepted.
            'schedule_id' => ['missing'],
            'user_id' => ['missing'],
            'ledger_id' => ['missing'],
        ];
    }
}
