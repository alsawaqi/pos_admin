<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * LAUNCH-P4 — the "Mark sold out" permission (owner decision 2026-10-03:
 * a dish is switched sold out by hand, per branch, and stays off until it
 * is switched back on).
 *
 * pos_merchant's MerchantPermission enum gains `catalogue.sold_out`; it lets
 * a portal user set or clear sold out (branch-limited users only for their
 * own branches). Users with catalogue.manage may do it too.
 *
 * Defaults: the Super Admin and Manager SYSTEM roles get it. pos_merchant's
 * seeder gives it to those roles of companies seeded from now on, but it
 * never adds a permission to an EXISTING Manager role — so this migration
 * grants it once to every existing merchant_super_admin and merchant_manager
 * role, in every company. Custom roles (and the other system roles) are not
 * granted it; the merchant adds it on the Roles page.
 *
 * Idempotent: the permission row and each grant are inserted only when
 * missing. Nothing else in the permission tables changes. Same shape as
 * 2026_10_02_100004 (Edit recipes); the same deploy rule applies: re-run the
 * idempotent grant after the merchant portal is on launch-p4, never
 * migrate:rollback it (its down() deletes grants merchants made).
 */
return new class extends Migration
{
    public const PERMISSION = 'catalogue.sold_out';

    public const GUARD = 'web';

    /** System roles that get the permission by default (pos_merchant MerchantRole values). */
    public const ROLES = ['merchant_super_admin', 'merchant_manager'];

    public function up(): void
    {
        $tables = $this->tables();
        $now = now();

        DB::table($tables['permissions'])->insertOrIgnore([
            'name' => self::PERMISSION,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = (int) DB::table($tables['permissions'])
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        $roleIds = DB::table($tables['roles'])
            ->whereIn('name', self::ROLES)
            ->where('guard_name', self::GUARD)
            ->orderBy('id')
            ->pluck('id');

        foreach ($roleIds->chunk(500) as $chunk) {
            DB::table($tables['role_has_permissions'])->insertOrIgnore(
                $chunk->map(static fn ($roleId): array => [
                    'permission_id' => $permissionId,
                    'role_id' => (int) $roleId,
                ])->values()->all(),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = $this->tables();

        $permissionId = DB::table($tables['permissions'])
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($permissionId !== null) {
            DB::table($tables['role_has_permissions'])->where('permission_id', $permissionId)->delete();
            DB::table($tables['model_has_permissions'])->where('permission_id', $permissionId)->delete();
            DB::table($tables['permissions'])->where('id', $permissionId)->delete();
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
