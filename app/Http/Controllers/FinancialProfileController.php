<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialProfileController extends Controller
{
    /**
     * List all the user's profiles (owned + shared), each with is_owner.
     */
    public function index(Request $request): JsonResponse
    {
        $memberships = DB::table('financial_profile_user')
            ->where('user_id', $request->user()->id)
            ->pluck('is_owner', 'profile_id');

        $profiles = FinancialProfile::query()
            ->whereIn('id', $memberships->keys())
            ->orderBy('id')
            ->get()
            ->map(fn (FinancialProfile $profile) => $this->serialize(
                $profile,
                (bool) $memberships->get($profile->id),
            ))
            ->values();

        return response()->json(['profiles' => $profiles]);
    }

    /**
     * Create a profile — the creator becomes the owner.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $profile = FinancialProfile::createOwnedBy($request->user(), $validated['name']);

        return response()->json(['profile' => $this->serialize($profile, true)], 201);
    }

    /**
     * Profile detail (members only).
     */
    public function show(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile);

        return response()->json([
            'profile' => $this->serialize($profile, $profile->isOwner($request->user())),
        ]);
    }

    /**
     * Rename a profile (owner only).
     */
    public function update(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile, ownerOnly: true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $profile->update($validated);

        return response()->json(['profile' => $this->serialize($profile, true)]);
    }

    /**
     * Delete a profile (owner only; blocked while it has budgets or ledgers;
     * the "Default profile" cannot be deleted but can be renamed).
     */
    public function destroy(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile, ownerOnly: true);

        if ($profile->is_default) {
            throw ValidationException::withMessages([
                'profile' => __('The default profile cannot be deleted.'),
            ]);
        }

        $hasBudgets = Budget::query()->where('profile_id', $profile->id)->exists();
        $hasLedgers = Ledger::query()->where('profile_id', $profile->id)->exists();

        if ($hasBudgets || $hasLedgers) {
            throw ValidationException::withMessages([
                'profile' => __('This profile still has budgets or ledgers.'),
            ]);
        }

        $profile->delete();

        return response()->json(status: 204);
    }

    /**
     * List the profile's members.
     */
    public function members(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile);

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
        $current = $request->user();

        $this->authorizeProfile($request, $profile);
        abort_unless($profile->isMember($user), 404);

        if ($user->id === $current->id) {
            // Voluntary self-leave.
            if ($profile->isOwner($current)) {
                throw ValidationException::withMessages([
                    'member' => __('Transfer ownership before leaving the profile.'),
                ]);
            }
        } else {
            $this->authorizeProfile($request, $profile, ownerOnly: true);

            if ($profile->isOwner($user)) {
                throw ValidationException::withMessages([
                    'member' => __('The profile owner cannot be removed.'),
                ]);
            }
        }

        $profile->removeMember($user);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership to a member; the previous owner is demoted.
     */
    public function transferOwnership(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile, ownerOnly: true);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        /** @var User|null $target */
        $target = User::query()->find($validated['user_id']);

        if ($target === null || ! $profile->isMember($target)) {
            throw ValidationException::withMessages([
                'user_id' => __('The target user must be a member of the profile.'),
            ]);
        }

        if ($target->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user_id' => __('You already own this profile.'),
            ]);
        }

        $profile->transferOwnershipTo($target);

        return response()->json(['profile' => $this->serialize($profile, true)]);
    }

    /**
     * @return array<string, string|int|bool>
     */
    private function serialize(FinancialProfile $profile, bool $isOwner): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'is_owner' => $isOwner,
            'is_default' => $profile->is_default,
        ];
    }
}
