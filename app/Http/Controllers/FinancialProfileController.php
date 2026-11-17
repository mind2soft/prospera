<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\StoreFinancialProfileRequest;
use App\Http\Requests\Profile\UpdateFinancialProfileRequest;
use App\Http\Requests\Sharing\TransferOwnershipRequest;
use App\Models\Budget;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use App\Services\ResourcePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialProfileController extends Controller
{
    public function __construct(
        private readonly ResourcePermissionService $permissionService,
    ) {}

    /**
     * List all the user's profiles (owned + shared), each with the caller's
     * is_owner and is_default membership flags.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $memberships = DB::table('financial_profile_user')
            ->where('user_id', $user->id)
            ->get(['profile_id', 'is_owner', 'is_default'])
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->profile_id => (array) $row,
            ]);

        $profiles = FinancialProfile::query()
            ->whereIn('id', $memberships->keys())
            ->orderBy('id')
            ->get()
            ->map(fn (FinancialProfile $profile) => $this->serialize(
                $profile,
                (bool) ($memberships[$profile->id]['is_owner'] ?? false),
                (bool) ($memberships[$profile->id]['is_default'] ?? false),
            ))
            ->values();

        return response()->json(['profiles' => $profiles]);
    }

    /**
     * Create a profile — the creator becomes the owner.
     */
    public function store(StoreFinancialProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->currentUser($request);

        $profile = FinancialProfile::createOwnedBy($user, $validated['name']);

        return response()->json([
            'profile' => $this->serialize($profile, true, $profile->isDefaultFor($user)),
        ], 201);
    }

    /**
     * Profile detail (members only).
     */
    public function show(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorize('view', $profile);
        $user = $this->currentUser($request);

        return response()->json([
            'profile' => $this->serialize(
                $profile,
                $profile->isOwner($user),
                $profile->isDefaultFor($user),
            ),
        ]);
    }

    /**
     * Rename a profile (owner only).
     */
    public function update(UpdateFinancialProfileRequest $request, FinancialProfile $profile): JsonResponse
    {
        $profile->update($request->validated());
        $user = $this->currentUser($request);

        return response()->json([
            'profile' => $this->serialize(
                $profile,
                $profile->isOwner($user),
                $profile->isDefaultFor($user),
            ),
        ]);
    }

    /**
     * Set the caller's default profile (any member; one default per user,
     * docs/shared-finances.md — issue #41). Shared profiles may be set as
     * default too.
     */
    public function setDefault(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorize('view', $profile);
        $user = $this->currentUser($request);

        $profile->markDefaultFor($user);

        return response()->json([
            'profile' => $this->serialize(
                $profile,
                $profile->isOwner($user),
                $profile->isDefaultFor($user),
            ),
        ]);
    }

    /**
     * Delete a profile (owner only; blocked while it has budgets or ledgers).
     * Since issue #41 the "Default profile" is deletable: affected users'
     * defaults are reassigned afterwards (earliest created owned profile,
     * else earliest remaining membership, else a seeded default).
     */
    public function destroy(FinancialProfile $profile): JsonResponse
    {
        $this->authorize('delete', $profile);

        DB::transaction(function () use ($profile): void {
            $locked = FinancialProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $this->authorize('delete', $locked);
            if (Budget::query()->where('profile_id', $locked->id)->exists()
                || Ledger::query()->where('profile_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['profile' => __('This profile still has budgets or ledgers.')]);
            }

            // Capture the members first: deleting cascades the pivot rows
            // away, so the capture must happen before the delete.
            $members = $locked->members()->get();

            $locked->delete();

            // Envelope grants between former co-members who no longer
            // share any profile are dropped (both directions).
            $members->each(fn (User $member) => FinancialProfile::pruneEnvelopeGrantsBetween($member, $members));

            // Deleting detaches the members (FK cascade). Affected users'
            // defaults are reassigned here (earliest created owned profile,
            // else earliest remaining membership, else a seeded default).
            $members->each(fn (User $member) => FinancialProfile::ensureDefaultFor($member));
        }, attempts: 3);

        return response()->json(status: 204);
    }

    /**
     * List the profile's members.
     */
    public function members(FinancialProfile $profile): JsonResponse
    {
        $this->authorize('view', $profile);

        $members = DB::table('financial_profile_user')
            ->join('users', 'users.id', '=', 'financial_profile_user.user_id')
            ->where('financial_profile_user.profile_id', $profile->id)
            ->orderBy('financial_profile_user.created_at')
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'financial_profile_user.is_owner',
                'financial_profile_user.created_at',
            ])
            ->map(fn (object $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'is_owner' => (bool) $member->is_owner,
                'joined_at' => Carbon::parse($member->created_at)->toISOString(),
            ])
            ->values();

        return response()->json(['members' => $members]);
    }

    /**
     * Remove a member (owner only, not the owner) or leave the profile
     * voluntarily (self). Resources owned by the removed member auto-transfer
     * to the profile owner and their share rows on the profile's resources
     * are deleted.
     */
    public function removeMember(Request $request, FinancialProfile $profile, User $user): JsonResponse
    {
        $current = $this->currentUser($request);

        $this->authorize('view', $profile);
        DB::transaction(function () use ($profile, $user, $current): void {
            $locked = FinancialProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $this->authorize('view', $locked);
            abort_unless($locked->isMember($user), 404);

            if ($user->id === $current->id) {
                // Voluntary self-leave.
                if ($locked->isOwner($current)) {
                    throw ValidationException::withMessages([
                        'member' => __('Transfer ownership before leaving the profile.'),
                    ]);
                }
            } else {
                $this->authorize('manage', $locked);

                if ($locked->isOwner($user)) {
                    throw ValidationException::withMessages([
                        'member' => __('The profile owner cannot be removed.'),
                    ]);
                }
            }

            $locked->removeMember($user);
        }, attempts: 3);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership to a member; the previous owner is demoted.
     */
    public function transferOwnership(TransferOwnershipRequest $request, FinancialProfile $profile): JsonResponse
    {
        $user = $this->currentUser($request);

        $locked = DB::transaction(function () use ($profile, $request, $user): FinancialProfile {
            $fresh = FinancialProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $this->authorize('manage', $fresh);
            $target = $this->permissionService->ensureNotOwner(
                $this->permissionService->profileMember($fresh, $request->targetId()),
                $user,
                __('You already own this profile.'),
            );
            $fresh->transferOwnershipTo($target);

            return $fresh;
        }, attempts: 3);

        return response()->json([
            'profile' => $this->serialize(
                $locked,
                $locked->isOwner($user),
                $locked->isDefaultFor($user),
            ),
        ]);
    }

    /**
     * @return array<string, string|int|bool>
     */
    private function serialize(FinancialProfile $profile, bool $isOwner, bool $isDefault): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'is_owner' => $isOwner,
            'is_default' => $isDefault,
        ];
    }
}
