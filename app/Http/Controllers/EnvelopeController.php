<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnvelopeRequest;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\ResourcePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EnvelopeController extends Controller
{
    public function __construct(
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
     * List the envelope's permission rows with the owner row first (owner
     * and profile co-members only).
     */
    public function permissionsIndex(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeVisibleToOwnerCircle($request, $envelope);

        return response()->json([
            'shares' => $this->permissionService->explicitPermissions('envelope_permissions', 'envelope_id', $envelope->id, $envelope->user),
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
            'shares' => $this->permissionService->explicitPermissions('envelope_permissions', 'envelope_id', $envelope->id, $envelope->user),
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
}
