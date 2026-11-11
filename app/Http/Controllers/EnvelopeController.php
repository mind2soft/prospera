<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnvelopeRequest;
use App\Http\Requests\Sharing\StoreShareRequest;
use App\Http\Requests\Sharing\TransferOwnershipRequest;
use App\Http\Requests\Sharing\UpdateShareRequest;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\ResourcePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        $validated = $request->validated();

        DB::transaction(function () use ($envelope, $validated): void {
            // Stored amounts are signed from the direction at write time:
            // flipping it under existing amounts would mix both conventions.
            // The row lock serializes with writers reading the direction
            // under a shared lock (transactions, budget assignments).
            $locked = Envelope::query()->whereKey($envelope->id)->lockForUpdate()->firstOrFail();

            if ($validated['type'] !== $locked->type && $locked->isReferenced()) {
                throw ValidationException::withMessages([
                    'type' => __('This envelope is already used by a budget or transactions: its direction cannot be changed.'),
                ]);
            }

            $envelope->update($validated);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope updated.')]);

        return to_route('envelopes');
    }

    public function destroy(Envelope $envelope): RedirectResponse
    {
        $this->authorize('delete', $envelope);

        $referencedBy = $envelope->referencedBy();

        if ($referencedBy !== null) {
            throw ValidationException::withMessages([
                'envelope' => $referencedBy === 'budget'
                    ? __('This envelope is used in a budget and cannot be deleted.')
                    : __('This envelope has transactions and cannot be deleted.'),
            ]);
        }

        $envelope->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope deleted.')]);

        return to_route('envelopes');
    }

    /**
     * List the envelope's permission rows with the owner row first (owner
     * and profile co-members only). Envelope grants span all the owner's
     * profiles: a co-member only sees the rows of people sharing a profile
     * with them — never grantees of the owner's other profiles
     * (docs/envelope.md).
     */
    public function permissionsIndex(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorize('viewShares', $envelope);

        $user = $request->user();

        return response()->json([
            'shares' => $this->permissionService->explicitPermissions(
                'envelope_permissions',
                'envelope_id',
                $envelope->id,
                $envelope->user,
                $envelope->isOwner($user) ? null : FinancialProfile::coMemberIdsOf($user->id),
            ),
        ]);
    }

    /**
     * Assign a permission to a profile member of the owner ("owner" is
     * rejected — use transfer-ownership).
     */
    public function addPermission(StoreShareRequest $request, Envelope $envelope): JsonResponse
    {
        $target = $this->permissionService->ownerCoMember($envelope->user, $request->targetId());

        $this->permissionService->createPermission('envelope_permissions', 'envelope_id', $envelope->id, $target->id, $request->permission(), __('This envelope is already shared with this user.'));

        return response()->json(status: 201);
    }

    /**
     * Change a member's permission ("owner" is rejected — use
     * transfer-ownership).
     */
    public function updatePermission(UpdateShareRequest $request, Envelope $envelope, int $userId): JsonResponse
    {
        $this->permissionService->updatePermission('envelope_permissions', 'envelope_id', $envelope->id, $userId, $request->permission());

        return response()->json([
            'shares' => $this->permissionService->explicitPermissions('envelope_permissions', 'envelope_id', $envelope->id, $envelope->user),
        ]);
    }

    /**
     * Revoke a share (owner only).
     */
    public function removePermission(Envelope $envelope, int $userId): JsonResponse
    {
        $this->authorize('share', $envelope);

        $this->permissionService->deletePermission('envelope_permissions', 'envelope_id', $envelope->id, $userId);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership to a member of the owner's profiles; the previous
     * owner becomes a contributor.
     */
    public function transferOwnership(TransferOwnershipRequest $request, Envelope $envelope): JsonResponse
    {
        $target = $this->permissionService->ensureNotOwner(
            $this->permissionService->ownerCoMember($envelope->user, $request->targetId()),
            $envelope->user,
            __('This user already owns this envelope.'),
        );

        $envelope->transferOwnershipTo($target);

        return response()->json([
            'envelope' => $this->serializeEnvelope($envelope->refresh(), $request->user()),
        ]);
    }

    /**
     * All envelopes visible to the user, serialized with their effective
     * permission (the user's share rows are eager-loaded — no per-row query).
     *
     * @return array<int, array<string, string|int>>
     */
    private function visibleEnvelopes(User $user): array
    {
        $coMemberIds = FinancialProfile::coMemberIdsOf($user->id);

        return Envelope::query()
            ->visibleTo($user)
            ->with(['permissions' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Envelope $envelope) => $this->serializeEnvelope($envelope, $user, $coMemberIds))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>|null  $coMemberIds
     * @return array<string, string|int>
     */
    private function serializeEnvelope(Envelope $envelope, User $user, ?Collection $coMemberIds = null): array
    {
        return [
            'id' => $envelope->id,
            'name' => $envelope->name,
            'direction' => $envelope->type,
            'color' => $envelope->color,
            'permission' => $envelope->permissionFor($user, $coMemberIds),
        ];
    }
}
