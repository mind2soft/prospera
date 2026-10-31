<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\BudgetEnveloppe;
use App\Models\Enveloppe;
use App\Models\FinancialProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use App\Services\ResourcePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BudgetController extends Controller
{
    public function __construct(
        private readonly ResourcePermissions $permissions,
        private readonly ResourcePermissionService $permissionService,
    ) {}

    /**
     * List a profile's non-retired budgets, newest first. The current
     * profile is tracked client-side, so callers pass `profile_id`; without
     * it, the budgets of all the caller's profiles are listed.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->filled('profile_id')) {
            $this->authorizeProfile($request, (int) $request->query('profile_id'));

            $query = Budget::query()->where('profile_id', (int) $request->query('profile_id'));
        } else {
            $query = Budget::query()->visibleTo($request->user());
        }

        $budgets = $query->nonRetired()
            ->with('permissions')
            ->orderByDesc('created_at')
            ->get()
            // Both branches only return budgets of profiles the caller is a
            // member of, so membership needn't be re-queried per row.
            ->map(fn (Budget $budget) => $this->serializeBudget($budget, $request->user(), isMember: true))
            ->values();

        return response()->json(['budgets' => $budgets]);
    }

    /**
     * Create a new draft budget in the given profile (profile owner only;
     * the creator becomes the budget owner). The profile row is locked so
     * the one-draft-per-profile rule cannot race.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ]);

        $profile = $this->authorizeProfile($request, (int) $validated['profile_id'], ownerOnly: true);

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
     * Budget detail with enveloppes and shares.
     */
    public function show(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::VIEWER);

        return response()->json([
            'budget' => $this->serializeDetail($budget, $request->user()),
        ]);
    }

    /**
     * Publish a draft budget: stamps the current month and replaces any
     * previously published budget for the same month. A replaced budget is
     * deleted unless transactions reference it — then it becomes retired
     * (hidden from selectors, auto-deleted with its last transaction). The
     * profile row is locked so the one-published-per-month replacement
     * cannot race. Publishing replaces profile-wide data, so it requires
     * both budget ownership and profile ownership.
     */
    public function publish(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);
        $this->authorizeProfile($request, $budget->profile, ownerOnly: true);

        if (! $budget->isDraft()) {
            throw ValidationException::withMessages([
                'budget' => __('This budget is already published.'),
            ]);
        }

        $year = now()->year;
        $month = now()->month;

        DB::transaction(function () use ($budget, $year, $month): void {
            FinancialProfile::query()->whereKey($budget->profile_id)->lockForUpdate()->firstOrFail();

            Budget::query()
                ->where('profile_id', $budget->profile_id)
                ->where('effective_year', $year)
                ->where('effective_month', $month)
                ->whereKeyNot($budget->id)
                ->get()
                ->each(function (Budget $existing): void {
                    if (Transaction::where('budget_id', $existing->id)->exists()) {
                        $existing->forceFill(['retired_at' => now()])->save();

                        return;
                    }

                    $existing->delete();
                });

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
    public function clone(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::VIEWER);
        $this->authorizeProfile($request, $budget->profile, ownerOnly: true);

        if ($budget->isDraft()) {
            throw ValidationException::withMessages([
                'budget' => __('Only published budgets can be cloned.'),
            ]);
        }

        $validated = $request->validate([
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
        ]);

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

            $budget->load('enveloppes');

            $draft->enveloppes()->createMany(
                $budget->enveloppes
                    ->map(fn (BudgetEnveloppe $enveloppe) => [
                        'enveloppe_id' => $enveloppe->enveloppe_id,
                        'amount' => $enveloppe->amount,
                        'type' => $enveloppe->type,
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
     * Delete a budget (cascades its shares and enveloppe assignments).
     * Blocked when transactions reference the budget (data integrity).
     */
    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);

        if (Transaction::where('budget_id', $budget->id)->exists()) {
            throw ValidationException::withMessages([
                'budget' => __('This budget has transactions and cannot be deleted.'),
            ]);
        }

        $budget->delete();

        return response()->json(status: 204);
    }

    /**
     * Assign an enveloppe to a draft budget. The caller needs contributor+
     * permission on the enveloppe (their own, or shared with them as
     * contributor); metadata-only enveloppes cannot be assigned.
     */
    public function addEnveloppe(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);
        $this->abortUnlessDraft($budget);

        $validated = $request->validate([
            'enveloppe_id' => ['required', 'integer'],
            'type' => ['required', 'string', Rule::in(BudgetEnveloppe::TYPES)],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999'],
        ]);

        /** @var Enveloppe|null $enveloppe */
        $enveloppe = Enveloppe::query()->find($validated['enveloppe_id']);

        if ($enveloppe === null || ! $this->permissions->isAtLeast($enveloppe->permissionFor($request->user()), ResourcePermissions::CONTRIBUTOR)) {
            throw ValidationException::withMessages([
                'enveloppe_id' => __('This enveloppe is not available.'),
            ]);
        }

        if ($budget->enveloppes()->where('enveloppe_id', $enveloppe->id)->exists()) {
            throw ValidationException::withMessages([
                'enveloppe_id' => __('This enveloppe is already assigned to the budget.'),
            ]);
        }

        $assignment = $budget->enveloppes()->create([
            'enveloppe_id' => $enveloppe->id,
            'amount' => $enveloppe->signedAmount($validated['amount']),
            'type' => $validated['type'],
        ]);

        return response()->json([
            'enveloppe' => $this->serializeEnveloppe($assignment->load('enveloppe')),
        ], 201);
    }

    /**
     * Update the amount (and type) of an assigned enveloppe.
     */
    public function updateEnveloppe(Request $request, Budget $budget, int $enveloppeId): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);
        $this->abortUnlessDraft($budget);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'type' => ['sometimes', 'string', Rule::in(BudgetEnveloppe::TYPES)],
        ]);

        $assignment = $budget->enveloppes()
            ->with('enveloppe')
            ->where('enveloppe_id', $enveloppeId)
            ->firstOrFail();

        $assignment->update([
            'amount' => $assignment->enveloppe->signedAmount($validated['amount']),
            'type' => $validated['type'] ?? $assignment->type,
        ]);

        return response()->json([
            'enveloppe' => $this->serializeEnveloppe($assignment->fresh('enveloppe')),
        ]);
    }

    /**
     * Remove an enveloppe assignment from a draft budget.
     */
    public function removeEnveloppe(Request $request, Budget $budget, int $enveloppeId): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);
        $this->abortUnlessDraft($budget);

        $assignment = $budget->enveloppes()
            ->where('enveloppe_id', $enveloppeId)
            ->firstOrFail();

        $assignment->delete();

        return response()->json(status: 204);
    }

    /**
     * List the profile's members with their permission on the budget.
     */
    public function permissionsIndex(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::VIEWER);

        return response()->json([
            'shares' => $this->permissionService->profilePermissions($budget->profile, 'budget_permissions', 'budget_id', $budget->id),
        ]);
    }

    /**
     * Assign a permission to a profile member ("owner" is rejected — use
     * transfer-ownership).
     */
    public function addPermission(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);

        $validated = $this->permissionService->validateProfilePermission($request, $budget->profile);

        $this->permissionService->createPermission('budget_permissions', 'budget_id', $budget->id, $validated['user_id'], $validated['permission'], 'budget');

        return response()->json(status: 201);
    }

    /**
     * Change a member's permission ("owner" is rejected — use
     * transfer-ownership).
     */
    public function updatePermission(Request $request, Budget $budget, int $userId): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);

        $permission = $this->permissionService->validatePermission($request);

        $this->permissionService->updatePermission('budget_permissions', 'budget_id', $budget->id, $userId, $permission);

        return response()->json([
            'shares' => $this->permissionService->profilePermissions($budget->profile, 'budget_permissions', 'budget_id', $budget->id),
        ]);
    }

    /**
     * Revoke a share.
     */
    public function removePermission(Request $request, Budget $budget, int $userId): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);

        $this->permissionService->deletePermission('budget_permissions', 'budget_id', $budget->id, $userId);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership to a member; the previous owner becomes a
     * contributor.
     */
    public function transferOwnership(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize($request, $budget, ResourcePermissions::OWNER);

        $target = $this->permissionService->validateProfileTransferTarget($request, $budget->profile, $request->user(), 'budget');

        $budget->transferOwnershipTo($target);

        return response()->json(['budget' => $this->serializeBudget($budget->refresh(), $request->user())]);
    }

    /**
     * Ensure the current user has at least the required permission on the
     * budget.
     */
    private function authorize(Request $request, Budget $budget, string $minimum): string
    {
        return $this->permissions->authorize(
            $budget->permissionFor($request->user()),
            $minimum,
        );
    }

    private function abortUnlessDraft(Budget $budget): void
    {
        if (! $budget->isDraft()) {
            throw ValidationException::withMessages([
                'budget' => __('Only draft budgets can be modified.'),
            ]);
        }
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
     * Full budget payload with enveloppes and shares, as consumed by the
     * frontend's `BudgetDetail` type.
     *
     * @return array<string, array<int|string, array<string, int|string|null>>|int|string|null>
     */
    private function serializeDetail(Budget $budget, User $user): array
    {
        $budget->loadMissing(['enveloppes.enveloppe', 'permissions.user']);

        return [
            ...$this->serializeBudget($budget, $user),
            'enveloppes' => $budget->enveloppes
                ->map(fn (BudgetEnveloppe $enveloppe) => $this->serializeEnveloppe($enveloppe))
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
            'retired_at' => $budget->retired_at?->toISOString(),
            'created_at' => $budget->created_at->toISOString(),
            'permission' => $budget->permissionFor($user, $isMember),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function serializeEnveloppe(BudgetEnveloppe $assignment): array
    {
        return [
            'enveloppe_id' => $assignment->enveloppe_id,
            'name' => $assignment->enveloppe->name,
            'color' => $assignment->enveloppe->color,
            'direction' => $assignment->enveloppe->type,
            'amount' => $assignment->amount,
            'type' => $assignment->type,
        ];
    }
}
