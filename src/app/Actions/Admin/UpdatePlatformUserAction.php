<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mutate a platform admin's name, phone, and/or role assignment.
 *
 * The role swap goes through Spatie's syncRoles() so the user
 * always ends up with EXACTLY the one new role — leftover
 * assignments from the previous role wouldn't make sense for a
 * platform-admin user (we don't model multi-role admins; each
 * person picks one bucket).
 *
 * Email is NOT editable here on purpose: it's the login key and
 * the audit trail's stable handle. Renames are rare enough that
 * deleting + re-inviting is the right answer.
 *
 * Audit event: `platform_user.updated`.
 */
final readonly class UpdatePlatformUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array{name?: string, phone?: string|null, role?: string}  $attributes
     */
    public function handle(User $user, array $attributes, User $actor): User
    {
        return DB::transaction(function () use ($user, $attributes, $actor): User {
            // Snapshot the before state so the audit log can show
            // the diff cleanly. Pull the current role under the
            // platform team scope too — outside that scope spatie
            // returns roles for whatever team_id is active.
            $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
            app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

            try {
                $beforeRole = $user->getRoleNames()->first();

                $changes = [];
                if (array_key_exists('name', $attributes) && $attributes['name'] !== $user->name) {
                    $user->name = $attributes['name'];
                    $changes['name'] = ['old' => $user->getOriginal('name'), 'new' => $user->name];
                }
                if (array_key_exists('phone', $attributes) && $attributes['phone'] !== $user->phone) {
                    $user->phone = $attributes['phone'];
                    $changes['phone'] = ['old' => $user->getOriginal('phone'), 'new' => $user->phone];
                }
                $user->save();

                // syncRoles wipes existing assignments first, then
                // installs the new one. We only touch the pivot
                // when a role was explicitly supplied — letting
                // callers update just the name without resetting
                // the role.
                if (array_key_exists('role', $attributes) && $attributes['role'] !== $beforeRole) {
                    $user->syncRoles([$attributes['role']]);
                    $changes['role'] = ['old' => $beforeRole, 'new' => $attributes['role']];
                }

                if ($changes !== []) {
                    $this->writeAuditLog->handle(new AuditLogData(
                        event: 'platform_user.updated',
                        actorUserId: $actor->id,
                        auditableType: User::class,
                        auditableId: $user->id,
                        oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                        newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
                    ));
                }

                return $user->fresh();
            } finally {
                app(PermissionRegistrar::class)->setPermissionsTeamId($previousTeamId);
            }
        });
    }
}
