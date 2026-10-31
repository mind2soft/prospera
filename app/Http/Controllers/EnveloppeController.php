<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnveloppeRequest;
use App\Models\Enveloppe;
use App\Models\FinancialProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use App\Services\ResourcePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EnveloppeController extends Controller
{
    public function __construct(
        private readonly ResourcePermissions $permissions,
        private readonly ResourcePermissionService $permissionService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Enveloppes', [
            'enveloppes' => $this->visibleEnveloppes($request->user()),
        ]);
    }

    /**
     * JSON list of all enveloppes visible to the user: their own, ones
     * shared with them, and metadata-only ones via profile membership with
     * the owner — each with its effective permission.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        return response()->json(['enveloppes' => $this->visibleEnveloppes($request->user())]);
    }

    public function store(EnveloppeRequest $request): RedirectResponse
    {
        $request->user()->enveloppes()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope created.')]);

        return to_route('enveloppes');
    }

    public function update(EnveloppeRequest $request, Enveloppe $enveloppe): RedirectResponse
    {
        $this->authorizeOwner($request, $enveloppe);

        $enveloppe->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope updated.')]);

        return to_route('enveloppes');
    }

    public function destroy(Request $request, Enveloppe $enveloppe): RedirectResponse
    {
        $this->authorizeOwner($request, $enveloppe);

        if ($enveloppe->budgetEnveloppes()->exists()) {
            throw ValidationException::withMessages([
                'enveloppe' => __('This envelope is used in a budget and cannot be deleted.'),
            ]);
        }

        if ($enveloppe->transactions()->exists()) {
            throw ValidationException::withMessages([
                'enveloppe' => __('This envelope has transactions and cannot be deleted.'),
            ]);
        }

        $enveloppe->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope deleted.')]);

        return to_route('enveloppes');
    }

    /**
     * Computed transaction totals for the enveloppe (optional
     * from_date/to_date filters). 403 without enveloppe-viewer+ permission —
     * raw amounts never reach clients lacking permission.
     */
    public function totals(Request $request, Enveloppe $enveloppe): JsonResponse
    {
        $this->authorizeViewer($request, $enveloppe);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $query = Transaction::query()->where('enveloppe_id', $enveloppe->id);

        if (! empty($validated['from_date'])) {
            $query->where('event_at', '>=', Carbon::parse($validated['from_date'])->startOfDay());
        }

        if (! empty($validated['to_date'])) {
            $query->where('event_at', '<=', Carbon::parse($validated['to_date'])->endOfDay());
        }

        $aggregate = $query->toBase()
            ->selectRaw('coalesce(sum(amount), 0) as total, count(*) as count')
            ->first();

        return response()->json([
            'totals' => [
                'enveloppe_id' => $enveloppe->id,
                'direction' => $enveloppe->type,
                'total' => (int) ($aggregate->total ?? 0),
                'count' => (int) ($aggregate->count ?? 0),
            ],
        ]);
    }

    /**
     * Transactions referencing the enveloppe; 403 without enveloppe-viewer+
     * permission.
     */
    public function apiTransactions(Request $request, Enveloppe $enveloppe): JsonResponse
    {
        $this->authorizeViewer($request, $enveloppe);

        $transactions = $enveloppe->transactions()
            ->with('user')
            ->orderBy('event_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Transaction $transaction) => $this->serializeTransaction($transaction))
            ->values();

        return response()->json(['transactions' => $transactions]);
    }

    /**
     * List the enveloppe's permission rows (owner and profile co-members
     * only).
     */
    public function permissionsIndex(Request $request, Enveloppe $enveloppe): JsonResponse
    {
        $this->authorizeVisibleToOwnerCircle($request, $enveloppe);

        return response()->json([
            'shares' => $this->permissionService->explicitPermissions('enveloppe_permissions', 'enveloppe_id', $enveloppe->id),
        ]);
    }

    /**
     * Assign a permission to a profile member of the owner ("owner" is
     * rejected — use transfer-ownership).
     */
    public function addPermission(Request $request, Enveloppe $enveloppe): JsonResponse
    {
        $this->authorizeOwner($request, $enveloppe);

        $validated = $this->permissionService->validateOwnedPermission($request, $enveloppe->user);

        $this->permissionService->createPermission('enveloppe_permissions', 'enveloppe_id', $enveloppe->id, $validated['user_id'], $validated['permission'], 'enveloppe');

        return response()->json(status: 201);
    }

    /**
     * Change a member's permission ("owner" is rejected — use
     * transfer-ownership).
     */
    public function updatePermission(Request $request, Enveloppe $enveloppe, int $userId): JsonResponse
    {
        $this->authorizeOwner($request, $enveloppe);

        $permission = $this->permissionService->validatePermission($request);

        $this->permissionService->updatePermission('enveloppe_permissions', 'enveloppe_id', $enveloppe->id, $userId, $permission);

        return response()->json([
            'shares' => $this->permissionService->explicitPermissions('enveloppe_permissions', 'enveloppe_id', $enveloppe->id),
        ]);
    }

    /**
     * Revoke a share (owner only).
     */
    public function removePermission(Request $request, Enveloppe $enveloppe, int $userId): JsonResponse
    {
        $this->authorizeOwner($request, $enveloppe);

        $this->permissionService->deletePermission('enveloppe_permissions', 'enveloppe_id', $enveloppe->id, $userId);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership to a member of the owner's profiles; the previous
     * owner becomes a contributor.
     */
    public function transferOwnership(Request $request, Enveloppe $enveloppe): JsonResponse
    {
        $this->authorizeOwner($request, $enveloppe);

        $target = $this->permissionService->validateOwnedTransferTarget($request, $enveloppe->user);

        $enveloppe->transferOwnershipTo($target);

        return response()->json([
            'enveloppe' => $this->serializeEnveloppe($enveloppe->refresh(), $request->user()),
        ]);
    }

    /**
     * Shares are visible to the owner and to profile co-members of the
     * owner.
     */
    private function authorizeVisibleToOwnerCircle(Request $request, Enveloppe $enveloppe): void
    {
        $user = $request->user();

        if ($enveloppe->isOwner($user)) {
            return;
        }

        abort_unless(FinancialProfile::membersShareAProfile($enveloppe->user, $user), 404);
    }

    /**
     * Transaction data requires enveloppe-viewer+ permission (403 otherwise).
     */
    private function authorizeViewer(Request $request, Enveloppe $enveloppe): void
    {
        abort_unless(
            $this->permissions->isAtLeast($enveloppe->permissionFor($request->user()), ResourcePermissions::VIEWER),
            403,
        );
    }

    /**
     * All enveloppes visible to the user, serialized with their effective
     * permission (the user's share rows are eager-loaded — no per-row query).
     *
     * @return array<int, array<string, string|int>>
     */
    private function visibleEnveloppes(User $user): array
    {
        return Enveloppe::query()
            ->visibleTo($user)
            ->with(['permissions' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Enveloppe $enveloppe) => $this->serializeEnveloppe($enveloppe, $user))
            ->values()
            ->all();
    }

    /**
     * Enveloppe management is owner-only; anything else is invisible (404).
     */
    private function authorizeOwner(Request $request, Enveloppe $enveloppe): void
    {
        abort_unless($enveloppe->isOwner($request->user()), 404);
    }

    /**
     * @return array<string, string|int>
     */
    private function serializeEnveloppe(Enveloppe $enveloppe, User $user): array
    {
        return [
            'id' => $enveloppe->id,
            'name' => $enveloppe->name,
            'direction' => $enveloppe->type,
            'color' => $enveloppe->color,
            'permission' => $enveloppe->permissionFor($user),
        ];
    }

    /**
     * @return array<string, string|int|null|array<string, string|int|null>>
     */
    private function serializeTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing(['user']);

        return [
            'id' => $transaction->id,
            'ledger_id' => $transaction->ledger_id,
            'budget_id' => $transaction->budget_id,
            'enveloppe_id' => $transaction->enveloppe_id,
            'amount' => $transaction->amount,
            'event_at' => $transaction->event_at->toDateString(),
            'created_at' => $transaction->created_at->toISOString(),
            'user' => [
                'id' => $transaction->user_id,
                'name' => $transaction->user_id === null
                    ? __('Former member')
                    : $transaction->user->name,
            ],
        ];
    }
}
