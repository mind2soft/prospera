<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Sharing\StoreShareRequest;
use App\Http\Requests\Sharing\TransferOwnershipRequest;
use App\Http\Requests\Sharing\UpdateShareRequest;
use App\Models\User;
use App\Services\ResourcePermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Share-drawer endpoints of a shareable resource (budgets, ledgers,
 * envelopes — docs/shared-finances.md, permission model): list, grant,
 * change, revoke, transfer ownership. The controller says how its resource
 * lists its people and which users may be granted or receive it.
 *
 * The route-bound resource arrives as a plain Model (the trait serves
 * several types), so its route parameter is bound explicitly in
 * routes/web.php. The payload requests authorize the policy ability on it
 * before validating; the request-less endpoints authorize here.
 *
 * @template TResource of Model
 *
 * @property-read ResourcePermissionService $permissionService
 */
trait ManagesShares
{
    /**
     * The route-bound resource as the controller's model type (a
     * LogicException when the route is bound to anything else).
     *
     * @return TResource
     */
    abstract protected function sharedResource(Model $resource): Model;

    /**
     * The share rows table and its resource column, e.g.
     * ["budget_permissions", "budget_id"].
     *
     * @return array{string, string}
     */
    abstract protected function shareTable(): array;

    /**
     * The people with access shown by the Share drawer, for the caller.
     *
     * @param  TResource  $resource
     * @return array<int, array<string, int|string|bool>>
     */
    abstract protected function shareList(Model $resource, User $caller): array;

    /**
     * The policy ability listing the shares requires.
     */
    abstract protected function viewSharesAbility(): string;

    /**
     * The user a grant targets, once eligible (a ValidationException on
     * `user_id` otherwise).
     *
     * @param  TResource  $resource
     */
    abstract protected function shareTarget(Model $resource, int $userId): User;

    /**
     * The validation message of a grant to a user who already has one.
     */
    abstract protected function alreadySharedMessage(): string;

    /**
     * Transfer the resource's ownership to the given user id (validating
     * the target on `user_id`) and return the response payload.
     *
     * @param  TResource  $resource
     * @return array<string, mixed>
     */
    abstract protected function transferOwnershipOf(Model $resource, User $caller, int $userId): array;

    /**
     * List the people with access.
     */
    public function permissionsIndex(Request $request, Model $resource): JsonResponse
    {
        $resource = $this->sharedResource($resource);

        $this->authorize($this->viewSharesAbility(), $resource);

        return response()->json(['shares' => $this->shareList($resource, $request->user())]);
    }

    /**
     * Grant a permission ("owner" is rejected — use transfer-ownership).
     */
    public function addPermission(StoreShareRequest $request, Model $resource): JsonResponse
    {
        [$table, $column] = $this->shareTable();
        $resource = $this->sharedResource($resource);

        $target = $this->shareTarget($resource, $request->targetId());

        $this->permissionService->createPermission($table, $column, (int) $resource->getKey(), $target->id, $request->permission(), $this->alreadySharedMessage());

        return response()->json(status: 201);
    }

    /**
     * Change a permission ("owner" is rejected — use transfer-ownership).
     */
    public function updatePermission(UpdateShareRequest $request, Model $resource, int $userId): JsonResponse
    {
        [$table, $column] = $this->shareTable();

        $resource = $this->sharedResource($resource);

        $this->permissionService->updatePermission($table, $column, (int) $resource->getKey(), $userId, $request->permission());

        return response()->json(['shares' => $this->shareList($resource, $request->user())]);
    }

    /**
     * Revoke a share (the owner row cannot be removed).
     */
    public function removePermission(Model $resource, int $userId): JsonResponse
    {
        $this->authorize('share', $resource);

        [$table, $column] = $this->shareTable();

        $this->permissionService->deletePermission($table, $column, (int) $resource->getKey(), $userId);

        return response()->json(status: 204);
    }

    /**
     * Transfer ownership; the previous owner becomes a contributor.
     */
    public function transferOwnership(TransferOwnershipRequest $request, Model $resource): JsonResponse
    {
        return response()->json($this->transferOwnershipOf($this->sharedResource($resource), $request->user(), $request->targetId()));
    }
}
