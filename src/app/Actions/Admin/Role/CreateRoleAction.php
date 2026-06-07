<?php

declare(strict_types=1);

namespace App\Actions\Admin\Role;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformPermission;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create a custom platform role.
 *
 * Mirrors pos_merchant's CreateRoleAction, scoped to the
 * platform team sentinel (TenantContext::PLATFORM_TEAM_ID = 0)
 * instead of a per-company team id.
 *
 * Permission whitelist: every key the caller asks for MUST be
 * in the PlatformPermission enum. Unknown keys are dropped
 * silently — defence against a client bug submitting unknown
 * strings that would otherwise pollute the role's permission
 * set with orphan rows.
 *
 * Audit event: role.created (company_id NULL because platform
 * roles live above the tenant scope).
 */
final readonly class CreateRoleAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, permissions?: list<string>}  $attributes
     */
    public function handle(array $attributes, User $actor): Role
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

        try {
            return DB::transaction(function () use ($attributes, $actor): Role {
                $requested = $attributes['permissions'] ?? [];
                $allowed = array_values(array_intersect(
                    $requested,
                    PlatformPermission::values(),
                ));

                /** @var Role $role */
                $role = Role::query()->create([
                    'name' => $attributes['name'],
                    'guard_name' => 'web',
                    'team_id' => TenantContext::PLATFORM_TEAM_ID,
                    'is_system' => false,
                    'description' => $attributes['description'] ?? null,
                ]);

                if ($allowed !== []) {
                    $role->syncPermissions($allowed);
                }

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'role.created',
                    actorUserId: $actor->getKey(),
                    companyId: null,
                    auditableType: Role::class,
                    auditableId: $role->id,
                    newValues: [
                        'name' => $role->name,
                        'description' => $role->description,
                        'permissions' => $allowed,
                    ],
                ));

                return $role;
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
