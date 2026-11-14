<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesShares;
use App\Http\Requests\EnvelopeRequest;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class EnvelopeController extends Controller
{
    /** @use ManagesShares<Envelope> */
    use ManagesShares;

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

            if ($validated['type'] !== $locked->type) {
                if ($locked->isReferenced()) {
                    throw ValidationException::withMessages([
                        'type' => __('This envelope is already used by a budget or transactions: its direction cannot be changed.'),
                    ]);
                }

                // A pending deletion restored after the flip would carry the
                // old sign (docs/undoable-deletion.md).
                Transaction::purgeDeletionsOnEnvelopes([$locked->id]);
            }

            $envelope->update($validated);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope updated.')]);

        return to_route('envelopes');
    }

    public function destroy(Envelope $envelope): RedirectResponse
    {
        $this->authorize('delete', $envelope);

        DB::transaction(function () use ($envelope): void {
            // The row lock serializes with writers reading the envelope under
            // a shared lock (recording or restoring a transaction).
            $locked = Envelope::query()->whereKey($envelope->id)->lockForUpdate()->firstOrFail();

            $referencedBy = $locked->referencedBy();

            if ($referencedBy !== null) {
                throw ValidationException::withMessages([
                    'envelope' => $referencedBy === 'budget'
                        ? __('This envelope is used in a budget and cannot be deleted.')
                        : __('This envelope has transactions and cannot be deleted.'),
                ]);
            }

            // Pending transaction deletions no longer block the envelope
            // (docs/undoable-deletion.md): they are purged with it.
            Transaction::purgeDeletionsOnEnvelopes([$locked->id]);

            $locked->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Envelope deleted.')]);

        return to_route('envelopes');
    }

    protected function sharedResource(Model $resource): Envelope
    {
        if (! $resource instanceof Envelope) {
            throw new LogicException('The share routes of this controller must bind an envelope.');
        }

        return $resource;
    }

    /**
     * @return array{string, string}
     */
    protected function shareTable(): array
    {
        return ['envelope_permissions', 'envelope_id'];
    }

    /**
     * Owner and profile co-members only.
     */
    protected function viewSharesAbility(): string
    {
        return 'viewShares';
    }

    /**
     * The envelope's permission rows with the owner row first. Envelope
     * grants span all the owner's profiles: a co-member only sees the rows
     * of people sharing a profile with them — never grantees of the
     * owner's other profiles (docs/envelope.md).
     *
     * @param  Envelope  $resource
     * @return array<int, array<string, int|string|bool>>
     */
    protected function shareList(Model $resource, User $caller): array
    {
        [$table, $column] = $this->shareTable();

        return $this->permissionService->explicitPermissions(
            $table,
            $column,
            $resource->id,
            $resource->user,
            $resource->isOwner($caller) ? null : FinancialProfile::coMemberIdsOf($caller->id),
        );
    }

    /**
     * A member of the owner's profiles.
     *
     * @param  Envelope  $resource
     */
    protected function shareTarget(Model $resource, int $userId): User
    {
        return $this->permissionService->ownerCoMember($resource->user, $userId);
    }

    protected function alreadySharedMessage(): string
    {
        return __('This envelope is already shared with this user.');
    }

    /**
     * Transfer ownership to a member of the owner's profiles; the previous
     * owner becomes a contributor.
     *
     * @param  Envelope  $resource
     * @return array<string, mixed>
     */
    protected function transferOwnershipOf(Model $resource, User $caller, int $userId): array
    {
        $target = $this->permissionService->ensureNotOwner(
            $this->permissionService->ownerCoMember($resource->user, $userId),
            $resource->user,
            __('This user already owns this envelope.'),
        );

        $resource->transferOwnershipTo($target);

        return ['envelope' => $this->serializeEnvelope($resource->refresh(), $caller)];
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
