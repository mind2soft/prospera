<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesShares;
use App\Http\Requests\Ledger\LedgerTransactionsRequest;
use App\Http\Requests\Ledger\StoreLedgerRequest;
use App\Http\Requests\Ledger\StoreTransactionRequest;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountKeyRing;
use App\Services\OnboardingProgress;
use App\Services\ResourcePermissions;
use App\Services\ResourcePermissionService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class LedgerController extends Controller
{
    /** @use ManagesShares<Ledger> */
    use ManagesShares;

    public function __construct(
        private readonly ResourcePermissionService $permissionService,
    ) {}

    /**
     * List a profile's ledgers, newest first. The current profile is tracked
     * client-side, so callers pass `profile_id`; without it, the ledgers of
     * all the caller's profiles are listed.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        if ($request->filled('profile_id')) {
            $this->authorizeProfileId((int) $request->query('profile_id'));

            $query = Ledger::query()->where('profile_id', (int) $request->query('profile_id'));
        } else {
            $query = Ledger::query()->visibleTo($request->user());
        }

        $user = $request->user();

        // Only the caller's share row resolves their permission.
        $ledgers = $query
            ->with(['permissions' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'ledgers' => $ledgers
                // Both branches are scoped to the caller's profiles.
                ->map(fn (Ledger $ledger) => $this->serializeLedger($ledger, $user, isMember: true))
                ->values(),
        ]);
    }

    /**
     * Create a ledger in the given profile (profile owner only; the creator
     * becomes the ledger owner).
     * The starting balance payload is always a positive integer;
     * starting_balance_type selects the stored sign (cash | debt).
     */
    public function store(StoreLedgerRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $profile = $request->profile();

        if (! app(OnboardingProgress::class)->forProfile($profile)['can_create_ledger']) {
            throw ValidationException::withMessages([
                'profile_id' => __('Create at least one revenue envelope and three expense envelopes before creating a ledger.'),
            ]);
        }

        $ledger = DB::transaction(function () use ($request, $profile, $validated): Ledger {
            $ledger = Ledger::create([
                'profile_id' => $profile->id,
                'name' => $validated['name'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'starting_balance' => $validated['starting_balance_type'] === 'debt'
                    ? -$validated['starting_balance']
                    : $validated['starting_balance'],
            ]);

            $ledger->permissions()->create([
                'user_id' => $request->user()->id,
                'permission' => ResourcePermissions::OWNER,
            ]);

            return $ledger;
        });

        return response()->json([
            'ledger' => $this->serializeLedger($ledger, $request->user()),
        ], 201);
    }

    /**
     * Delete a ledger and ALL of its transactions (cascade).
     */
    public function destroy(Ledger $ledger): JsonResponse
    {
        $this->authorize('delete', $ledger);

        $ledger->deleteWithTransactions();

        return response()->json(status: 204);
    }

    /**
     * Close a ledger: it becomes read-only.
     */
    public function close(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize('update', $ledger);

        $ledger->update(['closed_at' => now()]);

        return response()->json([
            'ledger' => $this->serializeLedger($ledger, $request->user()),
        ]);
    }

    /**
     * Re-open a closed ledger.
     */
    public function reopen(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize('update', $ledger);

        $ledger->update(['closed_at' => null]);

        return response()->json([
            'ledger' => $this->serializeLedger($ledger, $request->user()),
        ]);
    }

    /**
     * Transactions for the ledger within a from_date/to_date range. Rows on
     * envelopes the requester lacks envelope-viewer+ permission on are
     * masked server-side: amount null + masked true (metadata only).
     */
    public function transactions(LedgerTransactionsRequest $request, Ledger $ledger): JsonResponse
    {
        $validated = $request->validated();

        $from = Carbon::parse($validated['from_date'])->startOfDay();
        $to = Carbon::parse($validated['to_date'])->endOfDay();

        $user = $request->user();

        // The ledger's profile is known: no lookup query when decrypting.
        app(AmountKeyRing::class)->rememberLedgerProfiles([$ledger->id], $ledger->profile_id);

        $transactions = $ledger->transactions()
            ->with([
                // The requester's share rows resolve the masking below
                // without a second envelopes query.
                'envelope.permissions' => fn ($query) => $query->where('user_id', $user->id),
                'user',
            ])
            ->whereBetween('event_at', [$from, $to])
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();

        // Totals per envelope within the queried range, computed in PHP from
        // the rows above — amounts are encrypted at rest (docs/encryption.md).
        $totals = $transactions
            ->groupBy(fn (Transaction $transaction): string => (string) $transaction->envelope_id)
            ->map(fn (Collection $group): int => $group->sum(
                fn (Transaction $transaction): int => Money::toMinorUnits($transaction->amount),
            ));

        // "Remaining allocated" comes from the published budget bound to the
        // queried month (budgets are per month/year — see docs/budget.md):
        // allocation minus the transactions for that envelope in the range.
        // Envelopes without an allocation (or months without a published
        // budget) report as unallocated.
        $budget = Budget::query()
            ->where('profile_id', $ledger->profile_id)
            ->whereNotNull('published_at')
            ->where('effective_year', $from->year)
            ->where('effective_month', $from->month)
            ->first();

        if ($budget !== null) {
            app(AmountKeyRing::class)->rememberBudgetProfiles([$budget->id], $ledger->profile_id);
        }

        $allocations = $budget === null
            ? collect()
            : BudgetEnvelope::query()
                ->where('budget_id', $budget->id)
                ->whereIn('envelope_id', $totals->keys())
                ->get()
                ->mapWithKeys(fn (BudgetEnvelope $allocation): array => [
                    (string) $allocation->envelope_id => $allocation->amount,
                ]);

        // Resolved once for every row's permission check (stale grants).
        $coMemberIds = FinancialProfile::coMemberIdsOf($user->id);

        return response()->json([
            'transactions' => $transactions
                ->map(fn (Transaction $transaction) => $this->serializeTransaction(
                    $transaction,
                    $transaction->envelope->permissionFor($user, $coMemberIds),
                    $this->remainingAllocated($transaction, $allocations, $totals),
                ))
                ->values(),
        ]);
    }

    /**
     * The envelope's allocated amount on the month's published budget, minus
     * the transactions recorded against that envelope within the queried
     * range ("remaining allocated"). Null when the envelope has no
     * allocation (or the month has no published budget).
     *
     * @param  Collection<int|string, int|float>  $allocations
     * @param  Collection<string, int>  $totals
     */
    private function remainingAllocated(Transaction $transaction, Collection $allocations, Collection $totals): int|float|null
    {
        $allocation = $allocations->get((string) $transaction->envelope_id);

        if ($allocation === null) {
            return null;
        }

        return Money::fromMinorUnits(
            Money::toMinorUnits($allocation) - $totals->get((string) $transaction->envelope_id, 0),
        );
    }

    /**
     * Record a transaction against one of the user's envelopes — envelopes
     * are the only dependency; no budget is involved. Ledger owners and
     * contributors can record, but only against envelopes on which they are
     * also contributor (or owner). The amount is signed from the envelope's
     * direction.
     */
    public function addTransaction(StoreTransactionRequest $request, Ledger $ledger): JsonResponse
    {
        $validated = $request->validated();

        if ($ledger->isClosed()) {
            throw ValidationException::withMessages([
                'ledger' => __('This ledger is closed and read-only.'),
            ]);
        }

        /** @var Envelope|null $envelope */
        $envelope = Envelope::query()->find($validated['envelope_id']);

        if ($envelope === null || $request->user()->cannot('use', $envelope)) {
            throw ValidationException::withMessages([
                'envelope_id' => __('You need contributor access to this envelope to record transactions.'),
            ]);
        }

        $eventAt = Carbon::parse($validated['event_at'])->startOfDay();

        if (! $ledger->acceptsEventDate($eventAt)) {
            throw ValidationException::withMessages([
                'event_at' => __('Transactions can only be recorded between :start and :end.', [
                    'start' => $ledger->start_date->toDateString(),
                    'end' => $ledger->end_date->toDateString(),
                ]),
            ]);
        }

        $transaction = DB::transaction(function () use ($request, $ledger, $envelope, $validated, $eventAt): Transaction {
            // The sign comes from the direction read under a shared lock, so
            // a concurrent direction change (blocked once the envelope is
            // referenced) cannot slip in between (docs/envelope.md).
            $direction = Envelope::query()->whereKey($envelope->id)->sharedLock()->firstOrFail();

            return $ledger->transactions()->create([
                'user_id' => $request->user()->id,
                'envelope_id' => $envelope->id,
                'amount' => $direction->signedAmount((float) $validated['amount']),
                'event_at' => $eventAt,
            ])->setRelation('envelope', $direction);
        });

        return response()->json([
            'transaction' => $this->serializeTransaction(
                $transaction,
                // Contributor+ (the `use` check above): the amount is never
                // masked, so the permission is not resolved a second time.
                ResourcePermissions::CONTRIBUTOR,
                null,
                withRemaining: false,
            ),
        ], 201);
    }

    /**
     * Delete a transaction (open ledgers only; ledger owner: any; ledger
     * contributor: own entries only; viewers: none). The row is soft-deleted:
     * it disappears everywhere at once, but stays restorable during the undo
     * window before `transactions:purge-deleted` removes it for good
     * (docs/undoable-deletion.md).
     */
    public function destroyTransaction(Transaction $transaction): JsonResponse
    {
        $this->authorizeTransactionChange($transaction, 'delete');

        $transaction->delete();

        return response()->json(status: 204);
    }

    /**
     * Undo a transaction deletion, within the undo window. Same rules as the
     * deletion; restoring an entry that is not deleted is a no-op.
     */
    public function restoreTransaction(Transaction $transaction): JsonResponse
    {
        $this->authorizeTransactionChange($transaction, 'restore');

        DB::transaction(function () use ($transaction): void {
            // The shared lock serializes with the envelope's deletion or
            // direction change, which purge its pending deletions: the row
            // is re-read under it (docs/undoable-deletion.md).
            Envelope::query()->whereKey($transaction->envelope_id)->sharedLock()->first();

            $pending = Transaction::onlyTrashed()->whereKey($transaction->id)->lockForUpdate()->first();

            if ($pending === null) {
                // Already restored, or purged in the meantime.
                abort_if(! Transaction::query()->whereKey($transaction->id)->exists(), 404);

                return;
            }

            if (! $pending->isRestorable()) {
                throw ValidationException::withMessages([
                    'transaction' => __('This transaction can no longer be restored.'),
                ]);
            }

            $pending->restore();
        });

        return response()->json(status: 204);
    }

    /**
     * Deleting and restoring entries: the ledger must be visible (404
     * otherwise) and open, then the transaction policy decides.
     */
    private function authorizeTransactionChange(Transaction $transaction, string $ability): void
    {
        $ledger = $transaction->ledger;

        $this->authorize('view', $ledger);

        if ($ledger->isClosed()) {
            throw ValidationException::withMessages([
                'ledger' => __('This ledger is closed and read-only.'),
            ]);
        }

        $this->authorize($ability, $transaction);
    }

    protected function sharedResource(Model $resource): Ledger
    {
        if (! $resource instanceof Ledger) {
            throw new LogicException('The share routes of this controller must bind a ledger.');
        }

        return $resource;
    }

    /**
     * @return array{string, string}
     */
    protected function shareTable(): array
    {
        return ['ledger_permissions', 'ledger_id'];
    }

    protected function viewSharesAbility(): string
    {
        return 'view';
    }

    /**
     * The profile's members with their permission on the ledger.
     *
     * @param  Ledger  $resource
     * @return array<int, array<string, int|string|bool>>
     */
    protected function shareList(Model $resource, User $caller): array
    {
        [$table, $column] = $this->shareTable();

        return $this->permissionService->profilePermissions($resource->profile, $table, $column, $resource->id);
    }

    /**
     * @param  Ledger  $resource
     */
    protected function shareTarget(Model $resource, int $userId): User
    {
        return $this->permissionService->profileMember($resource->profile, $userId);
    }

    protected function alreadySharedMessage(): string
    {
        return __('This ledger is already shared with this user.');
    }

    /**
     * Transfer ownership to a member; the previous owner becomes a
     * contributor.
     *
     * @param  Ledger  $resource
     * @return array<string, mixed>
     */
    protected function transferOwnershipOf(Model $resource, User $caller, int $userId): array
    {
        $target = $this->permissionService->ensureNotOwner(
            $this->permissionService->profileMember($resource->profile, $userId),
            $caller,
            __('You already own this ledger.'),
        );

        $resource->transferOwnershipTo($target);

        return ['ledger' => $this->serializeLedger($resource->refresh(), $caller)];
    }

    /**
     * @return array<string, string|int|null>
     */
    private function serializeLedger(Ledger $ledger, User $user, ?bool $isMember = null): array
    {
        return [
            'id' => $ledger->id,
            'profile_id' => $ledger->profile_id,
            'name' => $ledger->name,
            'start_date' => $ledger->start_date->toDateString(),
            'end_date' => $ledger->end_date->toDateString(),
            'starting_balance' => $ledger->starting_balance,
            'closed_at' => $ledger->closed_at?->toISOString(),
            'status' => $ledger->isClosed() ? 'closed' : 'open',
            'created_at' => $ledger->created_at->toISOString(),
            'permission' => $ledger->permissionFor($user, $isMember),
        ];
    }

    /**
     * Transaction payload with the envelope's display info and the entering
     * user ("Former member" for deleted users). Rows on envelopes the user
     * lacks envelope-viewer+ permission on are masked server-side.
     *
     * @return array<string, string|int|float|null|bool|array<string, string|int|float|null>>
     */
    private function serializeTransaction(
        Transaction $transaction,
        string $permission,
        int|float|null $remainingAllocated,
        bool $withRemaining = true,
    ): array {
        $transaction->loadMissing(['envelope', 'user']);

        $masked = $permission === ResourcePermissions::NONE;

        $payload = [
            'id' => $transaction->id,
            'ledger_id' => $transaction->ledger_id,
            'envelope_id' => $transaction->envelope_id,
            'name' => $transaction->envelope->name,
            'color' => $transaction->envelope->color,
            'direction' => $transaction->envelope->type,
            'amount' => $masked ? null : $transaction->amount,
            'masked' => $masked,
            'event_at' => $transaction->event_at->toDateString(),
            'created_at' => $transaction->created_at->toISOString(),
            'user' => [
                'id' => $transaction->user_id,
                'name' => $transaction->user_id === null
                    ? __('Former member')
                    : $transaction->user->name,
            ],
        ];

        // remaining_allocated is a range-view concept; the create response
        // omits it.
        if ($withRemaining) {
            $payload['remaining_allocated'] = $masked ? null : $remainingAllocated;
        }

        return $payload;
    }
}
