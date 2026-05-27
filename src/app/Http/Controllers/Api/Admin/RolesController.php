<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\Role\CreateRoleAction;
use App\Actions\Admin\Role\DeleteRoleAction;
use App\Actions\Admin\Role\UpdateRoleAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Role\CreateRoleRequest;
use App\Http\Requests\Admin\Role\UpdateRoleRequest;
use App\Http\Resources\Admin\Role\RoleResource;
use App\Support\PlatformPermissionCatalog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Platform-side role builder (Phase 4.8b). Manages the roles
 * that live under team_id=0 (the platform team sentinel).
 *
 *   GET    /admin/api/v1/roles              → list roles
 *   GET    /admin/api/v1/roles/catalog      → grouped permission tree
 *   POST   /admin/api/v1/roles              → create custom role
 *   PATCH  /admin/api/v1/roles/{role}       → edit role
 *   DELETE /admin/api/v1/roles/{role}       → delete custom role
 *
 * Permission gates:
 *   - index / catalog  → RolesView (most roles have it)
 *   - store / update / destroy → RolesManage (SuperAdmin-only
 *     by default; can be granted via the UI to a deputy)
 *
 * Why no Policy class — see PlatformTeamController's preamble.
 * Same reasoning applies: there's already PortalUserPolicy on
 * User::class, registering a Role policy would collide. Direct
 * $user->can() reads via the controller's ensure() helper.
 */
class RolesController extends Controller
{
    public function __construct(
        private readonly CreateRoleAction $create,
        private readonly UpdateRoleAction $update,
        private readonly DeleteRoleAction $delete,
    ) {}

    /**
     * GET /admin/api/v1/roles
     *
     * Returns every role under team_id=0 with permissions +
     * user counts eager-loaded so the table renders without
     * N+1.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, PlatformPermission::RolesView);

        app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

        $roles = Role::query()
            ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
            ->where('guard_name', 'web')
            ->with('permissions')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    /**
     * GET /admin/api/v1/roles/catalog
     *
     * The full permission catalog used by the role-editor's
     * grouped checkbox grid. Enum-derived, not data-derived.
     */
    public function catalog(Request $request): JsonResponse
    {
        $this->ensure($request, PlatformPermission::RolesView);

        return response()->json([
            'data' => PlatformPermissionCatalog::platform(),
        ]);
    }

    /**
     * POST /admin/api/v1/roles
     */
    public function store(CreateRoleRequest $request): JsonResponse
    {
        $this->ensure($request, PlatformPermission::RolesManage);

        $role = $this->create->handle($request->validated(), $request->user());
        $role->load('permissions');

        return response()->json([
            'data' => (new RoleResource($role))->resolve($request),
        ], 201);
    }

    /**
     * PATCH /admin/api/v1/roles/{role}
     */
    public function update(UpdateRoleRequest $request, Role $role): RoleResource | JsonResponse
    {
        $this->ensure($request, PlatformPermission::RolesManage);
        $this->refuseIfNotPlatformTeam($role);

        try {
            $updated = $this->update->handle($role, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load('permissions');

        return RoleResource::make($updated);
    }

    /**
     * DELETE /admin/api/v1/roles/{role}
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->ensure($request, PlatformPermission::RolesManage);
        $this->refuseIfNotPlatformTeam($role);

        try {
            $this->delete->handle($role, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => null], 204);
    }

    private function ensure(Request $request, PlatformPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /**
     * Roles are team-scoped — the spatie default route binding
     * resolves by id, which doesn't enforce team_id. Without
     * this an admin with RolesManage could probe a merchant
     * role id and mutate it. 404 (not 403) because the role
     * effectively doesn't exist in this team.
     */
    private function refuseIfNotPlatformTeam(Role $role): void
    {
        if ((int) $role->team_id !== TenantContext::PLATFORM_TEAM_ID) {
            abort(404);
        }
    }
}
