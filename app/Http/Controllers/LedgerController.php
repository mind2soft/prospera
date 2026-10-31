<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\BudgetEnveloppe;
use App\Models\Enveloppe;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use App\Services\ResourcePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LedgerController extends Controller
{
    public function __construct(
        private readonly ResourcePermissions $permissions,
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
            $this->authorizeProfile($request, (int) $request->query('profile_id'));

            $query = Ledger::query()->where('profile_id', (int) $request->query('profile_id'));
        } else {
            $query = Ledger::query()->visibleTo($request->user());
        }

        $ledgers = $query->with('permissions')->orderByDesc('created_at')->get();

        return response()->json([
            'ledgers' => $ledgers
                // Both branches are scoped to the caller's profiles.
                ->map(fn (Ledger $ledger) => $this->serializeLedger($ledger, $request->user(), isMember: true))
                ->values(),
        ]);
    }

    /**
     * Create a ledger in the given profile (profile owner only; the creator
     * becomes the ledger owner).
     * The starting balance payload is always a positive integer;
     * starting_balance_type selects the stored sign (cash | debt).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'starting_balance' => ['required', 'integer', 'min:0', 'max:999999999'],
            'starting_balance_type' => ['required', 'string', Rule::in(['cash', 'debt'])],
        ]);

        $profile = $this->authorizeProfile($request, (int) $validated['profile_id'], ownerOnly: true);

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
     * Delete a ledger and ALL of its transactions (cascade). The
     * transactions are deleted through Eloquent first so the model events
     * fire — retired budgets whose last referencing transactions lived in
     * this ledger are swept — then the ledger itself is deleted.
     */
    public function destroy(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize($request, $ledger, ResourcePermissions::OWNER);

        $ledger->deleteWithTransactions();

        return response()->json(status: 204);
    }

    /**
     * Close a ledger: it becomes read-only.
     */
    public function close(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize($request, $ledger, ResourcePermissions::OWNER);

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
        $this->authorize($request, $ledger, ResourcePermissions::OWNER);

        $ledger->update(['closed_at' => null]);

        return response()->json([
            'ledger' => $this->serializeLedger($ledger, $request->user()),
        ]);
    }

    /**
     * Transactions for the ledger within a from_date/to_date range. Rows on
     * enveloppes the requester lacks enveloppe-viewer+ permission on are
     * masked server-side: amount null + masked true (metadata only).
     */
    public function transactions(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize($request, $ledger, ResourcePermissions::VIEWER);

        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        $from = Carbon::parse($validated['from_date'])->startOfDay();
        $to = Carbon::parse($validated['to_date'])->endOfDay();

        $transactions = $ledger->transactions()
            ->with('enveloppe', 'user')
            ->whereBetween('event_at', [$from, $to])
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();

        // Remaining allocated is computed per budget snapshot: transactions
        // recorded against a budget revision subtract from that revision's
        // own allocation, not from other revisions of the same enveloppe.
        $totals = DB::table('transactions')
            ->where('ledger_id', $ledger->id)
            ->whereBetween('event_at', [$from, $to])
            ->groupBy('budget_id', 'enveloppe_id')
            ->get(['budget_id', 'enveloppe_id', DB::raw('sum(amount) as total')])
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->budget_id.'-'.(string) $row->enveloppe_id => (int) $row->total,
            ]);

        $allocations = BudgetEnveloppe::query()
            ->whereIn('enveloppe_id', $transactions->pluck('enveloppe_id')->unique())
            ->whereIn('budget_id', $transactions->pluck('budget_id')->unique())
            ->get()
            ->keyBy(fn (BudgetEnveloppe $assignment) => $assignment->budget_id.'-'.$assignment->enveloppe_id)
            ->toBase();

        $enveloppePermissions = Enveloppe::permissionsFor(
            $request->user(),
            $transactions->pluck('enveloppe_id')->unique()->all(),
        );

        return response()->json([
            'transactions' => $transactions
                ->map(fn (Transaction $transaction) => $this->serializeTransaction(
                    $transaction,
                    $enveloppePermissions->get($transaction->enveloppe_id, ResourcePermissions::NONE),
                    $this->remainingAllocated($transaction, $allocations, $totals),
                ))
                ->values(),
        ]);
    }

    /**
     * Record a transaction against the ledger's enveloppes. Ledger owners and
     * contributors can record, but only against enveloppes on which they are
     * also contributor (or owner). The amount is
     * signed from the enveloppe's direction; the budget snapshot is the
     * latest published (non-retired) budget of the profile.
     */
    public function addTransaction(Request $request, Ledger $ledger): JsonResponse
    {
        $ledgerPermission = $this->authorize($request, $ledger, ResourcePermissions::VIEWER);

        $validated = $request->validate([
            'enveloppe_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'event_at' => ['required', 'date'],
        ]);

        if ($ledger->isClosed()) {
            throw ValidationException::withMessages([
                'ledger' => __('This ledger is closed and read-only.'),
            ]);
        }

        if (! $this->permissions->isAtLeast($ledgerPermission, ResourcePermissions::CONTRIBUTOR)) {
            abort(403);
        }

        /** @var Enveloppe|null $enveloppe */
        $enveloppe = Enveloppe::query()->find($validated['enveloppe_id']);

        $enveloppePermission = $enveloppe?->permissionFor($request->user()) ?? ResourcePermissions::NONE;

        if ($enveloppe === null || ! $this->permissions->isAtLeast($enveloppePermission, ResourcePermissions::CONTRIBUTOR)) {
            throw ValidationException::withMessages([
                'enveloppe_id' => __('You need contributor access to this enveloppe to record transactions.'),
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

        /** @var Budget|null $budget */
        $budget = Budget::query()
            ->where('profile_id', $ledger->profile_id)
            ->whereNotNull('published_at')
            ->whereNull('retired_at')
            ->orderByDesc('published_at')
            ->first();

        if ($budget === null) {
            throw ValidationException::withMessages([
                'budget' => __('You need a published budget before recording transactions.'),
            ]);
        }

        if (! $budget->enveloppes()->where('enveloppe_id', $enveloppe->id)->exists()) {
            throw ValidationException::withMessages([
                'enveloppe_id' => __('This enveloppe is not part of the published budget.'),
            ]);
        }

        $transaction = $ledger->transactions()->create([
            'user_id' => $request->user()->id,
            'enveloppe_id' => $enveloppe->id,
            'budget_id' => $budget->id,
            'amount' => $enveloppe->signedAmount($validated['amount']),
            'event_at' => $eventAt,
        ]);

        return response()->json([
            'transaction' => $this->serializeTransaction(
                $transaction,
                $enveloppePermission,
                null,
                withRemaining: false,
            ),
        ], 201);
    }

    /**
     * Delete a transaction (open ledgers only; ledger owner: any; ledger
     * contributor: own entries only; viewers: none).
     */
    public function destroyTransaction(Request $request, Transaction $transaction): JsonResponse
    {
        $ledger = $transaction->ledger;

        $permission = $ledger->permissionFor($request->user());

        if ($permission === ResourcePermissions::NONE) {
            abort(404);
        }

        if ($ledger->isClosed()) {
            throw ValidationException::withMessages([
                'ledger' => __('This ledger is closed and read-only.'),
            ]);
        }

        $mayDelete = $permission === ResourcePermissions::OWNER
            || ($permission === ResourcePermissions::CONTRIBUTOR && $transaction->user_id === $request->user()->id);

        if (! $mayDelete) {
            abort(403);
        }

        $transaction->delete();

        return response()->json(status: 204);
    }

    /**
     * The enveloppe's allocated amount on the transaction's budget snapshot,
     * minus the transactions recorded against that same snapshot budget
     * within the queried range ("remaining allocated").
     *
     * @param  Collection<string, BudgetEnveloppe>  $allocations
     * @param  Collection<string, int>  $totals
     */
    private function remainingAllocated(Transaction $transaction, Collection $allocations, Collection $totals): ?int
    {
        $allocation = $allocations->get($transaction->budget_id.'-'.$transaction->enveloppe_id)?->amount;

        if ($allocation === null) {
            return null;
        }

        return (int) $allocation - $totals->get($transaction->budget_id.'-'.$transaction->enveloppe_id, 0);
    }

    /**
     * List the profile's members with their permission on the ledger.
     */
    public function permissionsIndex(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize($request, $ledger, ResourcePermissions::VIEWER);

        return response()->json([
            'shares' => $this->permissionService->profilePermissions($ledger->profile, 'ledger_permissions', 'ledger_id', $ledger->id),
        ]);
    }

    /**
     * Assign a permission to a profile member ("owner" is rejected — use
     * transfer-ownership).
     */
    public function addPermission(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize($request, $ledger, ResourcePermissions::OWNER);

        $validated = $this->permissionService->validateProfilePermission($request, $ledger->profile);

        $this->permissionService->createPermission('ledger_permissions', 'ledger_id', $ledger->id, $validated['user_id'], $validated['permission'], 'ledger');

        return response()->json(status: 201);
    }

    /**
     * Change a member's permission ("owner" is rejected — use
     * transfer-ownership).
     */
    public function updatePermission(Request $request, Ledger $ledger, int $userId): JsonResponse
    {
        $this->authorize($request, $ledger, ResourcePermissions::OWNER);

        $permission = $this->permissionService->validatePermission($request);

        $this->permissionService->updatePermission('ledger_permissions', 'ledger_id', $ledger->id, $userId, $permission);

        return response()->json([
            'shares' => $this->permissionService->profilePermissions($ledger->profile, 'ledger_permissions', 'ledger_id', $ledger->id),
        ]);
    }

    /**
     * Revoke a share.
     */
    public function removePermission(Request $request, Ledger $ledger, int $userId): JsonResponse
    {
        $this->authorize($request, $ledger, ResourcePermissions::OWNER);

        $this->permissionService->deletePermission('ledger_permissions', 'ledger_id', $ledger->id, $userId);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership to a member; the previous owner becomes a
     * contributor.
     */
    public function transferOwnership(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize($request, $ledger, ResourcePermissions::OWNER);

        $target = $this->permissionService->validateProfileTransferTarget($request, $ledger->profile, $request->user(), 'ledger');

        $ledger->transferOwnershipTo($target);

        return response()->json(['ledger' => $this->serializeLedger($ledger->refresh(), $request->user())]);
    }

    /**
     * Ensure the current user has at least the required permission on the
     * ledger.
     */
    private function authorize(Request $request, Ledger $ledger, string $minimum): string
    {
        return $this->permissions->authorize(
            $ledger->permissionFor($request->user()),
            $minimum,
        );
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
     * Transaction payload with the enveloppe's display info and the entering
     * user ("Former member" for deleted users). Rows on enveloppes the user
     * lacks enveloppe-viewer+ permission on are masked server-side.
     *
     * @return array<string, string|int|null|bool|array<string, string|int|null>>
     */
    private function serializeTransaction(
        Transaction $transaction,
        string $permission,
        ?int $remainingAllocated,
        bool $withRemaining = true,
    ): array {
        $transaction->loadMissing(['enveloppe', 'user']);

        $masked = $permission === ResourcePermissions::NONE;

        $payload = [
            'id' => $transaction->id,
            'ledger_id' => $transaction->ledger_id,
            'enveloppe_id' => $transaction->enveloppe_id,
            'budget_id' => $transaction->budget_id,
            'name' => $transaction->enveloppe->name,
            'color' => $transaction->enveloppe->color,
            'direction' => $transaction->enveloppe->type,
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
