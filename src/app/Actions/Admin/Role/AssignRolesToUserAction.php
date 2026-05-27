<?php

declare(strict_types=1);

namespace App\Actions\Admin\Role;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformRole;
use App\Enums\UserType;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Replace a platform user's role list with the requested set.
 *
 * Roles are addressed by name. Names are validated against
 * existing platform-team roles — passing a merchant role name
 * has no effect because the lookup is scoped to team_id=0.
 *
 * Self-rescue: the actor cannot strip the SuperAdmin role from
 * themselves. Otherwise an absent-minded owner could downgrade
 * themselves to FinanceViewer and leave the platform with
 * nobody able to fix it.
 *
 * Population guard: the target user MUST be a platform_admin
 * row. Trying to assign platform roles to a merchant user
 * (someone might guess an id) is a 404 — those users live in
 * a different team scope and use a different enum.
 *
 * Audit event: platform_user.roles_changed with old + new
 * arrays.
 */
final readonly class AssignRolesToUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function handle(User $platformUser, array $roleNames, User $actor): User
    {
        // Population guard — refuse to mutate a non-platform
        // user row.
        if ($platformUser->user_type !== UserType::PlatformAdmin) {
            abort(404);
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

        try {
            return DB::transaction(function () use ($platformUser, $roleNames, $actor): User {
                // Resolve names → role rows in the platform team.
                // Unknown names silently dropped — the validator
                // is responsible for surfacing those as 422.
                $resolvedRoles = Role::query()
                    ->where('guard_name', 'web')
                    ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
                    ->whereIn('name', $roleNames)
                    ->get();
                $resolvedNames = $resolvedRoles->pluck('name')->sort()->values()->all();

                $oldNames = $platformUser->getRoleNames()->sort()->values()->all();

                if (
                    $platformUser->getKey() === $actor->getKey()
                    && in_array(PlatformRole::SuperAdmin->value, $oldNames, true)
                    && ! in_array(PlatformRole::SuperAdmin->value, $resolvedNames, true)
                ) {
                    throw new RuntimeException(
                        'You cannot remove your own Super Admin role. Ask another Super Admin to do it for you.',
                    );
                }

                if ($oldNames === $resolvedNames) {
                    return $platformUser->fresh();
                }

                $platformUser->syncRoles($resolvedRoles);

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'platform_user.roles_changed',
                    actorUserId: $actor->getKey(),
                    companyId: null,
                    auditableType: User::class,
                    auditableId: $platformUser->id,
                    oldValues: ['roles' => $oldNames],
                    newValues: ['roles' => $resolvedNames],
                ));

                return $platformUser->fresh();
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
