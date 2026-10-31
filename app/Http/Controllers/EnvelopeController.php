<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnvelopeRequest;
use App\Models\Envelope;
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

class EnvelopeController extends Controller
{
    public function __construct(
        private readonly ResourcePermissions $permissions,
        private readonly ResourcePermissionService $permissionService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Envelopes', [
            'envelopes' => $this->visibleEnvelopes($request->user()),
        ]);
    }

    /**
     * JSON list of all envelopes visible to the user: their own, ones
     * shared with them, and metadata-only ones via profile membership with
     * the owner — each with its effective permission.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        return response()->json(['envelopes' => $this->visibleEnvelopes($request->user())]);
    }

    public function store(EnvelopeRequest $request): RedirectResponse
    {
        $request->user()->envelopes()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope created.')]);

        return to_route('envelopes');
    }

    public function update(EnvelopeRequest $request, Envelope $envelope): RedirectResponse
    {
        $this->authorizeOwner($request, $envelope);

        $envelope->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope updated.')]);

        return to_route('envelopes');
    }

    public function destroy(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->authorizeOwner($request, $envelope);

        if ($envelope->budgetEnvelopes()->exists()) {
            throw ValidationException::withMessages([
                'envelope' => __('This envelope is used in a budget and cannot be deleted.'),
            ]);
        }

        if ($envelope->transactions()->exists()) {
            throw ValidationException::withMessages([
                'envelope' => __('This envelope has transactions and cannot be deleted.'),
            ]);
        }

        $envelope->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope deleted.')]);

        return to_route('envelopes');
    }

    /**
     * Computed transaction totals for the envelope (optional
     * from_date/to_date filters). 403 without envelope-viewer+ permission —
     * raw amounts never reach clients lacking permission.
     */
    public function totals(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeViewer($request, $envelope);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $query = Transaction::query()->where('envelope_id', $envelope->id);

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
                'envelope_id' => $envelope->id,
                'direction' => $envelope->type,
                'total' => (int) ($aggregate->total ?? 0),
                'count' => (int) ($aggregate->count ?? 0),
            ],
        ]);
    }

    /**
     * Transactions referencing the envelope; 403 without envelope-viewer+
     * permission.
     */
    public function apiTransactions(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeViewer($request, $envelope);

        $transactions = $envelope->transactions()
            ->with('user')
            ->orderBy('event_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Transaction $transaction) => $this->serializeTransaction($transaction))
            ->values();

        return response()->json(['transactions' => $transactions]);
    }

    /**
     * List the envelope's permission rows (owner and profile co-members
     * only).
     */
    public function permissionsIndex(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeVisibleToOwnerCircle($request, $envelope);

        return response()->json([
            'shares' => $this->permissionService->explicitPermissions('envelope_permissions', 'envelope_id', $envelope->id),
        ]);
    }

    /**
     * Assign a permission to a profile member of the owner ("owner" is
     * rejected — use transfer-ownership).
     */
    public function addPermission(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeOwner($request, $envelope);

        $validated = $this->permissionService->validateOwnedPermission($request, $envelope->user);

        $this->permissionService->createPermission('envelope_permissions', 'envelope_id', $envelope->id, $validated['user_id'], $validated['permission'], 'envelope');

        return response()->json(status: 201);
    }

    /**
     * Change a member's permission ("owner" is rejected — use
     * transfer-ownership).
     */
    public function updatePermission(Request $request, Envelope $envelope, int $userId): JsonResponse
    {
        $this->authorizeOwner($request, $envelope);

        $permission = $this->permissionService->validatePermission($request);

        $this->permissionService->updatePermission('envelope_permissions', 'envelope_id', $envelope->id, $userId, $permission);

        return response()->json([
            'shares' => $this->permissionService->explicitPermissions('envelope_permissions', 'envelope_id', $envelope->id),
        ]);
    }

    /**
     * Revoke a share (owner only).
     */
    public function removePermission(Request $request, Envelope $envelope, int $userId): JsonResponse
    {
        $this->authorizeOwner($request, $envelope);

        $this->permissionService->deletePermission('envelope_permissions', 'envelope_id', $envelope->id, $userId);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership to a member of the owner's profiles; the previous
     * owner becomes a contributor.
     */
    public function transferOwnership(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeOwner($request, $envelope);

        $target = $this->permissionService->validateOwnedTransferTarget($request, $envelope->user);

        $envelope->transferOwnershipTo($target);

        return response()->json([
            'envelope' => $this->serializeEnvelope($envelope->refresh(), $request->user()),
        ]);
    }

    /**
     * Shares are visible to the owner and to profile co-members of the
     * owner.
     */
    private function authorizeVisibleToOwnerCircle(Request $request, Envelope $envelope): void
    {
        $user = $request->user();

        if ($envelope->isOwner($user)) {
            return;
        }

        abort_unless(FinancialProfile::membersShareAProfile($envelope->user, $user), 404);
    }

    /**
     * Transaction data requires envelope-viewer+ permission (403 otherwise).
     */
    private function authorizeViewer(Request $request, Envelope $envelope): void
    {
        abort_unless(
            $this->permissions->isAtLeast($envelope->permissionFor($request->user()), ResourcePermissions::VIEWER),
            403,
        );
    }

    /**
     * All envelopes visible to the user, serialized with their effective
     * permission (the user's share rows are eager-loaded — no per-row query).
     *
     * @return array<int, array<string, string|int>>
     */
    private function visibleEnvelopes(User $user): array
    {
        return Envelope::query()
            ->visibleTo($user)
            ->with(['permissions' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Envelope $envelope) => $this->serializeEnvelope($envelope, $user))
            ->values()
            ->all();
    }

    /**
     * Envelope management is owner-only; anything else is invisible (404).
     */
    private function authorizeOwner(Request $request, Envelope $envelope): void
    {
        abort_unless($envelope->isOwner($request->user()), 404);
    }

    /**
     * @return array<string, string|int>
     */
    private function serializeEnvelope(Envelope $envelope, User $user): array
    {
        return [
            'id' => $envelope->id,
            'name' => $envelope->name,
            'direction' => $envelope->type,
            'color' => $envelope->color,
            'permission' => $envelope->permissionFor($user),
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
            'envelope_id' => $transaction->envelope_id,
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
