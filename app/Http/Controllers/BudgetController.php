<?php

namespace App\Http\Controllers;

use App\Http\Requests\Budget\CloneBudgetRequest;
use App\Http\Requests\Budget\PublishBudgetRequest;
use App\Http\Requests\Budget\StoreBudgetEnvelopeRequest;
use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Requests\Budget\UpdateBudgetEnvelopeRequest;
use App\Http\Requests\Sharing\StoreShareRequest;
use App\Http\Requests\Sharing\TransferOwnershipRequest;
use App\Http\Requests\Sharing\UpdateShareRequest;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\LocalCalendar;
use App\Services\ResourcePermissions;
use App\Services\ResourcePermissionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetController extends Controller
{
    public function __construct(
        private readonly ResourcePermissions $permissions,
        private readonly ResourcePermissionService $permissionService,
    ) {}

    /**
     * List a profile's budgets, newest first. The current profile is
     * tracked client-side, so callers pass `profile_id`; without it, the
     * budgets of all the caller's profiles are listed.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->filled('profile_id')) {
            $this->authorizeProfileId((int) $request->query('profile_id'));

            $query = Budget::query()->where('profile_id', (int) $request->query('profile_id'));
        } else {
            $query = Budget::query()->visibleTo($request->user());
        }

        $user = $request->user();

        $budgets = $query
            // Only the caller's share row resolves their permission.
            ->with(['permissions' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderByDesc('created_at')
            ->get()
            // Both branches only return budgets of profiles the caller is a
            // member of, so membership needn't be re-queried per row.
            ->map(fn (Budget $budget) => $this->serializeBudget($budget, $user, isMember: true))
            ->values();

        return response()->json(['budgets' => $budgets]);
    }

    /**
     * Create a new draft budget in the given profile (profile owner only;
     * the creator becomes the budget owner). The profile row is locked so
     * the one-draft-per-profile rule cannot race.
     */
    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $profile = $this->authorizeProfileId((int) $validated['profile_id'], 'manage');

        $budget = DB::transaction(function () use ($request, $profile, $validated): Budget {
            FinancialProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            $this->abortIfDraftExists($profile->id);

            $budget = Budget::create([
                'profile_id' => $profile->id,
                'currency' => strtoupper($validated['currency']),
                'effective_year' => 0,
                'effective_month' => 0,
            ]);

            $budget->permissions()->create([
                'user_id' => $request->user()->id,
                'permission' => ResourcePermissions::OWNER,
            ]);

            return $budget;
        });

        return response()->json([
            'budget' => $this->serializeDetail($budget, $request->user()),
        ], 201);
    }

    /**
     * Budget detail with envelopes and shares.
     */
    public function show(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize('view', $budget);

        return response()->json([
            'budget' => $this->serializeDetail($budget, $request->user()),
        ]);
    }

    /**
     * Publish a draft budget: stamps the current month and replaces any
     * previously published budget for the same month (the replaced budget is
     * deleted with its shares and assignments). The profile row is locked so
     * the one-published-per-month replacement cannot race. Publishing
     * replaces profile-wide data, so it requires both budget ownership and
     * profile ownership.
     */
    public function publish(PublishBudgetRequest $request, Budget $budget): JsonResponse
    {
        // The current month is the user's, not the server's (UTC): near a
        // month boundary they differ, and publishing replaces — deletes —
        // the published budget of the stamped month.
        $today = LocalCalendar::fromRequest($request)->today();

        $validated = $request->validated();

        // The client may state the month it means to publish for: a
        // mismatch (e.g. a dialog left open across midnight) is refused
        // rather than replacing another month's budget.
        if (isset($validated['month']) && $validated['month'] !== $today->format('Y-m')) {
            throw ValidationException::withMessages([
                'month' => __('Budgets can only be published for the current month (:month).', [
                    'month' => $today->format('Y-m'),
                ]),
            ]);
        }

        $year = $today->year;
        $month = $today->month;

        DB::transaction(function () use ($budget, $year, $month): void {
            FinancialProfile::query()->whereKey($budget->profile_id)->lockForUpdate()->firstOrFail();

            // Re-checked under the lock: a concurrent publish (double click)
            // must not run twice.
            if (! Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail()->isDraft()) {
                throw ValidationException::withMessages([
                    'budget' => __('This budget is already published.'),
                ]);
            }

            Budget::query()
                ->where('profile_id', $budget->profile_id)
                ->where('effective_year', $year)
                ->where('effective_month', $month)
                ->whereKeyNot($budget->id)
                ->get()
                ->each(fn (Budget $existing) => $existing->delete());

            $budget->update([
                'published_at' => now(),
                'effective_year' => $year,
                'effective_month' => $month,
            ]);
        });

        return response()->json([
            'budget' => $this->serializeDetail($budget->refresh(), $request->user()),
        ]);
    }

    /**
     * Clone a published budget into a new draft (profile owner only — the
     * draft occupies the profile's single draft slot).
     */
    public function clone(CloneBudgetRequest $request, Budget $budget): JsonResponse
    {
        $validated = $request->validated();

        $draft = DB::transaction(function () use ($request, $budget, $validated): Budget {
            FinancialProfile::query()->whereKey($budget->profile_id)->lockForUpdate()->firstOrFail();

            $this->abortIfDraftExists($budget->profile_id);

            $draft = Budget::create([
                'profile_id' => $budget->profile_id,
                'currency' => strtoupper($validated['currency'] ?? $budget->currency),
                'effective_year' => 0,
                'effective_month' => 0,
            ]);

            $draft->permissions()->create([
                'user_id' => $request->user()->id,
                'permission' => ResourcePermissions::OWNER,
            ]);

            $budget->load('envelopes');

            $draft->envelopes()->createMany(
                $budget->envelopes
                    ->map(fn (BudgetEnvelope $envelope) => [
                        'envelope_id' => $envelope->envelope_id,
                        'amount' => $envelope->amount,
                        'type' => $envelope->type,
                    ])
                    ->all()
            );

            return $draft;
        });

        return response()->json([
            'budget' => $this->serializeDetail($draft, $request->user()),
        ], 201);
    }

    /**
     * Delete a budget (cascades its shares and envelope assignments).
     */
    public function destroy(Budget $budget): JsonResponse
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return response()->json(status: 204);
    }

    /**
     * Assign an envelope to a draft budget. The caller needs contributor+
     * permission on the envelope (their own, or shared with them as
     * contributor); metadata-only envelopes cannot be assigned.
     */
    public function addEnvelope(StoreBudgetEnvelopeRequest $request, Budget $budget): JsonResponse
    {
        $validated = $request->validated();

        /** @var Envelope|null $envelope */
        $envelope = Envelope::query()->find($validated['envelope_id']);

        if ($envelope === null || ! $this->permissions->isAtLeast($envelope->permissionFor($request->user()), ResourcePermissions::CONTRIBUTOR)) {
            throw ValidationException::withMessages([
                'envelope_id' => __('This envelope is not available.'),
            ]);
        }

        $duplicate = fn (): ValidationException => ValidationException::withMessages([
            'envelope_id' => __('This envelope is already assigned to the budget.'),
        ]);

        $assignment = DB::transaction(function () use ($budget, $envelope, $validated, $duplicate): BudgetEnvelope {
            $this->lockDraft($budget);

            if ($budget->envelopes()->where('envelope_id', $envelope->id)->exists()) {
                throw $duplicate();
            }

            // Sign from the direction read under a shared lock: a concurrent
            // direction change cannot slip in between (docs/envelope.md).
            $direction = Envelope::query()->whereKey($envelope->id)->sharedLock()->firstOrFail();

            try {
                return $budget->envelopes()->create([
                    'envelope_id' => $envelope->id,
                    'amount' => $direction->signedAmount($validated['amount']),
                    'type' => $validated['type'],
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent add won the race past the check above.
                throw $duplicate();
            }
        });

        return response()->json([
            'envelope' => $this->serializeEnvelope($assignment->load('envelope')),
        ], 201);
    }

    /**
     * Update the amount (and type) of an assigned envelope.
     */
    public function updateEnvelope(UpdateBudgetEnvelopeRequest $request, Budget $budget, int $envelopeId): JsonResponse
    {
        $validated = $request->validated();

        $assignment = DB::transaction(function () use ($budget, $envelopeId, $validated): BudgetEnvelope {
            $this->lockDraft($budget);

            $assignment = $budget->envelopes()
                ->with('envelope')
                ->where('envelope_id', $envelopeId)
                ->firstOrFail();

            $assignment->update([
                'amount' => $assignment->envelope->signedAmount($validated['amount']),
                'type' => $validated['type'] ?? $assignment->type,
            ]);

            return $assignment;
        });

        return response()->json([
            'envelope' => $this->serializeEnvelope($assignment->fresh('envelope')),
        ]);
    }

    /**
     * Remove an envelope assignment from a draft budget.
     */
    public function removeEnvelope(Budget $budget, int $envelopeId): JsonResponse
    {
        $this->authorize('update', $budget);
        $budget->ensureDraft();

        DB::transaction(function () use ($budget, $envelopeId): void {
            $this->lockDraft($budget);

            $budget->envelopes()
                ->where('envelope_id', $envelopeId)
                ->firstOrFail()
                ->delete();
        });

        return response()->json(status: 204);
    }

    /**
     * List the profile's members with their permission on the budget.
     */
    public function permissionsIndex(Budget $budget): JsonResponse
    {
        $this->authorize('view', $budget);

        return response()->json([
            'shares' => $this->permissionService->profilePermissions($budget->profile, 'budget_permissions', 'budget_id', $budget->id),
        ]);
    }

    /**
     * Assign a permission to a profile member ("owner" is rejected — use
     * transfer-ownership).
     */
    public function addPermission(StoreShareRequest $request, Budget $budget): JsonResponse
    {
        $target = $this->permissionService->profileMember($budget->profile, $request->targetId());

        $this->permissionService->createPermission('budget_permissions', 'budget_id', $budget->id, $target->id, $request->permission(), __('This budget is already shared with this user.'));

        return response()->json(status: 201);
    }

    /**
     * Change a member's permission ("owner" is rejected — use
     * transfer-ownership).
     */
    public function updatePermission(UpdateShareRequest $request, Budget $budget, int $userId): JsonResponse
    {
        $this->permissionService->updatePermission('budget_permissions', 'budget_id', $budget->id, $userId, $request->permission());

        return response()->json([
            'shares' => $this->permissionService->profilePermissions($budget->profile, 'budget_permissions', 'budget_id', $budget->id),
        ]);
    }

    /**
     * Revoke a share.
     */
    public function removePermission(Budget $budget, int $userId): JsonResponse
    {
        $this->authorize('share', $budget);

        $this->permissionService->deletePermission('budget_permissions', 'budget_id', $budget->id, $userId);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership to a member; the previous owner becomes a
     * contributor. A draft only ever belongs to the profile owner
     * (docs/budget.md — Ownership): publishing needs both ownerships and
     * the draft holds the profile's single draft slot, so a draft handed to
     * a collaborator could be neither published nor replaced.
     */
    public function transferOwnership(TransferOwnershipRequest $request, Budget $budget): JsonResponse
    {
        $target = $this->permissionService->ensureNotOwner(
            $this->permissionService->profileMember($budget->profile, $request->targetId()),
            $request->user(),
            __('You already own this budget.'),
        );

        if ($budget->isDraft() && ! $budget->profile->isOwner($target)) {
            throw ValidationException::withMessages([
                'user_id' => __('A draft budget can only be owned by the profile owner.'),
            ]);
        }

        $budget->transferOwnershipTo($target);

        return response()->json(['budget' => $this->serializeBudget($budget->refresh(), $request->user())]);
    }

    /**
     * Lock the budget row and re-check it is still a draft, inside the
     * caller's transaction: the route-bound model was read without a lock,
     * so a concurrent publish may have committed since (publishing locks
     * the same row).
     */
    private function lockDraft(Budget $budget): void
    {
        Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail()->ensureDraft();
    }

    private function abortIfDraftExists(int $profileId): void
    {
        $exists = Budget::query()
            ->where('profile_id', $profileId)
            ->whereNull('published_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'budget' => __('This profile already has a draft budget. Publish it before creating a new one.'),
            ]);
        }
    }

    /**
     * Full budget payload with envelopes and shares, as consumed by the
     * frontend's `BudgetDetail` type.
     *
     * @return array<string, array<int|string, array<string, bool|int|string|null>>|int|string|null>
     */
    private function serializeDetail(Budget $budget, User $user): array
    {
        $budget->loadMissing('envelopes.envelope');

        return [
            ...$this->serializeBudget($budget, $user),
            'envelopes' => $budget->envelopes
                ->map(fn (BudgetEnvelope $envelope) => $this->serializeEnvelope($envelope))
                ->values()
                ->all(),
            'shares' => $this->permissionService->profilePermissions($budget->profile, 'budget_permissions', 'budget_id', $budget->id),
        ];
    }

    /**
     * @return array<string, string|int|null>
     */
    private function serializeBudget(Budget $budget, User $user, ?bool $isMember = null): array
    {
        return [
            'id' => $budget->id,
            'profile_id' => $budget->profile_id,
            'currency' => $budget->currency,
            'effective_year' => $budget->effective_year,
            'effective_month' => $budget->effective_month,
            'published_at' => $budget->published_at?->toISOString(),
            'created_at' => $budget->created_at->toISOString(),
            'permission' => $budget->permissionFor($user, $isMember),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function serializeEnvelope(BudgetEnvelope $assignment): array
    {
        return [
            'envelope_id' => $assignment->envelope_id,
            'name' => $assignment->envelope->name,
            'color' => $assignment->envelope->color,
            'direction' => $assignment->envelope->type,
            'amount' => $assignment->amount,
            'type' => $assignment->type,
        ];
    }
}
