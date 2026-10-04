<?php

declare(strict_types=1);

/*
 * LAUNCH-P5 — the four new portal permissions (staff.permissions.manage,
 * pos_staff.reset_pin, pos_staff.change_position, staff.attendance.manage)
 * granted to the Super Admin and Manager system roles of every existing
 * merchant, the same shape as the P4 "Mark sold out" grant. Cashier
 * Supervisor does not get them (M6).
 */

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function p5GrantMigration(): object
{
    return require database_path('migrations/2026_10_04_100008_grant_staff_permissions_to_default_roles.php');
}

function p5Role(int $teamId, string $name): int
{
    return DB::table('pos_roles')->insertGetId([
        'team_id' => $teamId,
        'name' => $name,
        'guard_name' => 'web',
        'is_system' => str_starts_with($name, 'merchant_'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return list<int> role ids holding the permission */
function p5RolesWith(string $permission): array
{
    return DB::table('pos_role_has_permissions')
        ->join('pos_permissions', 'pos_permissions.id', '=', 'pos_role_has_permissions.permission_id')
        ->where('pos_permissions.name', $permission)
        ->orderBy('role_id')
        ->pluck('role_id')
        ->map(static fn ($id): int => (int) $id)
        ->all();
}

const P5_PERMISSIONS = ['staff.permissions.manage', 'pos_staff.reset_pin', 'pos_staff.change_position', 'staff.attendance.manage'];

it('grants the four staff permissions to every existing Super Admin and Manager role, and to nobody else', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $superA = p5Role($a->id, 'merchant_super_admin');
    $managerA = p5Role($a->id, 'merchant_manager');
    $viewerA = p5Role($a->id, 'merchant_viewer');
    $cashierSupA = p5Role($a->id, 'merchant_cashier_supervisor');
    $customA = p5Role($a->id, 'Shift lead');
    $managerB = p5Role($b->id, 'merchant_manager');

    p5GrantMigration()->up();

    foreach (P5_PERMISSIONS as $permission) {
        expect(DB::table('pos_permissions')->where('name', $permission)->where('guard_name', 'web')->count())->toBe(1, $permission)
            ->and(p5RolesWith($permission))->toBe([$superA, $managerA, $managerB]);
        expect(p5RolesWith($permission))->not->toContain($viewerA)
            ->not->toContain($cashierSupA)
            ->not->toContain($customA);
    }

    // Idempotent: a second run adds nothing and fails on nothing.
    p5GrantMigration()->up();
    foreach (P5_PERMISSIONS as $permission) {
        expect(DB::table('pos_permissions')->where('name', $permission)->count())->toBe(1)
            ->and(p5RolesWith($permission))->toBe([$superA, $managerA, $managerB]);
    }
});

it('keeps a grant the merchant already made and removes the permissions on rollback', function (): void {
    $company = Company::factory()->create();
    $custom = p5Role($company->id, 'Shift lead');
    $manager = p5Role($company->id, 'merchant_manager');
    foreach (P5_PERMISSIONS as $permission) {
        expect(DB::table('pos_permissions')->where('name', $permission)->exists())->toBeTrue($permission);
    }
    $resetPin = (int) DB::table('pos_permissions')->where('name', 'pos_staff.reset_pin')->value('id');
    DB::table('pos_role_has_permissions')->insert(['permission_id' => $resetPin, 'role_id' => $custom]);

    p5GrantMigration()->up();
    expect(p5RolesWith('pos_staff.reset_pin'))->toBe([$custom, $manager])
        ->and(p5RolesWith('staff.attendance.manage'))->toBe([$manager]);

    p5GrantMigration()->down();
    foreach (P5_PERMISSIONS as $permission) {
        expect(DB::table('pos_permissions')->where('name', $permission)->exists())->toBeFalse()
            ->and(p5RolesWith($permission))->toBe([]);
    }
});
