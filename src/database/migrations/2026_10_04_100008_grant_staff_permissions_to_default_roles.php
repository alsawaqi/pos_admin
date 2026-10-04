<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * LAUNCH-P5 — the four new portal permissions of the staff work:
 *
 *   staff.permissions.manage   the tick-list page (replaces orders.cancel as
 *                              the gate of the four-list page)
 *   pos_staff.reset_pin        reset a staff PIN (M6: no longer part of
 *                              pos_staff.update)
 *   pos_staff.change_position  change a staff member's position (M6)
 *   staff.attendance.manage    edit hours in the Hours report
 *
 * Defaults: the Super Admin and Manager SYSTEM roles get all four.
 * pos_merchant's seeder gives them to those roles of companies seeded from
 * now on, but it never adds a permission to an EXISTING role — so this
 * migration grants them once to every existing merchant_super_admin and
 * merchant_manager role, in every company. Custom roles and the other system
 * roles (Cashier Supervisor included, M6) are not granted them; the merchant
 * adds them on the Roles page.
 *
 * Idempotent: each permission row and each grant are inserted only when
 * missing. Nothing else in the permission tables changes. Same shape as
 * 2026_10_03_100008 (Mark sold out); the same deploy rule applies: re-run the
 * idempotent grant after the merchant portal is on launch-p5, and never
 * migrate:rollback it in a real deploy (its down() deletes grants merchants
 * made).
 */
return new class extends Migration
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'staff.permissions.manage',
        'pos_staff.reset_pin',
        'pos_staff.change_position',
        'staff.attendance.manage',
    ];

    public const GUARD = 'web';

    /** System roles that get the permissions by default (pos_merchant MerchantRole values). */
    public const ROLES = ['merchant_super_admin', 'merchant_manager'];

    public function up(): void
    {
        $tables = $this->tables();
        $now = now();

        $roleIds = DB::table($tables['roles'])
            ->whereIn('name', self::ROLES)
            ->where('guard_name', self::GUARD)
            ->orderBy('id')
            ->pluck('id');

        foreach (self::PERMISSIONS as $permission) {
            DB::table($tables['permissions'])->insertOrIgnore([
                'name' => $permission,
                'guard_name' => self::GUARD,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $permissionId = (int) DB::table($tables['permissions'])
                ->where('name', $permission)
                ->where('guard_name', self::GUARD)
                ->value('id');

            foreach ($roleIds->chunk(500) as $chunk) {
                DB::table($tables['role_has_permissions'])->insertOrIgnore(
                    $chunk->map(static fn ($roleId): array => [
                        'permission_id' => $permissionId,
                        'role_id' => (int) $roleId,
                    ])->values()->all(),
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = $this->tables();

        $permissionIds = DB::table($tables['permissions'])
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table($tables['role_has_permissions'])->whereIn('permission_id', $permissionIds)->delete();
            DB::table($tables['model_has_permissions'])->whereIn('permission_id', $permissionIds)->delete();
            DB::table($tables['permissions'])->whereIn('id', $permissionIds)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array{permissions: string, roles: string, role_has_permissions: string, model_has_permissions: string} */
    private function tables(): array
    {
        $names = (array) config('permission.table_names', []);

        return [
            'permissions' => (string) ($names['permissions'] ?? 'pos_permissions'),
            'roles' => (string) ($names['roles'] ?? 'pos_roles'),
            'role_has_permissions' => (string) ($names['role_has_permissions'] ?? 'pos_role_has_permissions'),
            'model_has_permissions' => (string) ($names['model_has_permissions'] ?? 'pos_model_has_permissions'),
        ];
    }
};
