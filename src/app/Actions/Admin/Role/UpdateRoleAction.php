<?php

declare(strict_types=1);

namespace App\Actions\Admin\Role;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Edit a platform role.
 *
 *   - name        — only mutable on non-system roles. The 5
 *                   default roles' names are referenced by the
 *                   seeder + cross-app contracts (e.g.
 *                   pos_admin's `platform_super_admin` string
 *                   is checked in tests + middleware).
 *   - description — always mutable.
 *   - permissions — always mutable, BUT we refuse to remove
 *                   the self-rescue set from SuperAdmin
 *                   (roles.manage, roles.view, platform_users.*).
 *                   Without these the owner can't fix their own
 *                   mistake.
 *
 * Cross-team safety: the action re-checks that team_id is the
 * platform sentinel (0). A merchant role can never be edited
 * from this admin-side action even if the id is guessed.
 *
 * Audit event: role.updated with old/new pivot diffs.
 */
final readonly class UpdateRoleAction
{
    /**
     * Permissions that must always be present on the SuperAdmin
     * role — removing any of them would lock the owner out of
     * the ability to put them back. Keep narrow.
     *
     * @var list<string>
     */
    private const SUPER_ADMIN_LOCKED_PERMISSIONS = [
        'roles.manage',
        'roles.view',
        'platform_users.view',
        'platform_users.invite',
        'platform_users.update_roles',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array{name?: string, description?: string|null, permissions?: list<string>}  $attributes
     */
    public function handle(Role $role, array $attributes, User $actor): Role
    {
        if ((int) $role->team_id !== TenantContext::PLATFORM_TEAM_ID) {
            abort(404);
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

        try {
            return DB::transaction(function () use ($role, $attributes, $actor): Role {
                $changes = [];

                // name (system-locked)
                if (array_key_exists('name', $attributes) && $attributes['name'] !== $role->name) {
                    if ($role->is_system) {
                        throw new RuntimeException(
                            'System roles cannot be renamed. Create a new custom role instead.',
                        );
                    }
                    $changes['name'] = ['old' => $role->name, 'new' => $attributes['name']];
                    $role->name = $attributes['name'];
                }

                // description
                if (array_key_exists('description', $attributes) && $attributes['description'] !== $role->description) {
                    $changes['description'] = ['old' => $role->description, 'new' => $attributes['description']];
                    $role->description = $attributes['description'];
                }

                $role->save();

                // permissions
                if (array_key_exists('permissions', $attributes)) {
                    $requested = $attributes['permissions'];
                    $allowed = array_values(array_intersect(
                        $requested,
                        PlatformPermission::values(),
                    ));

                    if ($role->name === PlatformRole::SuperAdmin->value) {
                        $missing = array_diff(self::SUPER_ADMIN_LOCKED_PERMISSIONS, $allowed);
                        if ($missing !== []) {
                            throw new RuntimeException(
                                'These permissions cannot be removed from the Super Admin role: ' . implode(', ', $missing),
                            );
                        }
                    }

                    $oldPermissions = $role->permissions()->pluck('name')->sort()->values()->all();
                    $role->syncPermissions($allowed);
                    $newPermissions = $role->permissions()->pluck('name')->sort()->values()->all();

                    if ($oldPermissions !== $newPermissions) {
                        $changes['permissions'] = [
                            'old' => $oldPermissions,
                            'new' => $newPermissions,
                        ];
                    }
                }

                if ($changes !== []) {
                    $this->writeAuditLog->handle(new AuditLogData(
                        event: 'role.updated',
                        actorUserId: $actor->getKey(),
                        companyId: null,
                        auditableType: Role::class,
                        auditableId: $role->id,
                        oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                        newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
                    ));
                }

                return $role->fresh();
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
