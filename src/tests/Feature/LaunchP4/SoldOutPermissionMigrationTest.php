<?php

declare(strict_types=1);

/*
 * LAUNCH-P4 — the "Mark sold out" permission (catalogue.sold_out) granted to
 * the Super Admin and Manager system roles of every existing merchant, the
 * same shape as the P3 "Edit recipes" grant.
 */

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function p4SoldOutGrantMigration(): object
{
    return require database_path('migrations/2026_10_03_100008_grant_sold_out_permission_to_default_roles.php');
}

function p4Role(int $teamId, string $name): int
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

/** @return list<int> role ids holding catalogue.sold_out */
function p4RolesWithSoldOut(): array
{
    return DB::table('pos_role_has_permissions')
        ->join('pos_permissions', 'pos_permissions.id', '=', 'pos_role_has_permissions.permission_id')
        ->where('pos_permissions.name', 'catalogue.sold_out')
        ->orderBy('role_id')
        ->pluck('role_id')
        ->map(static fn ($id): int => (int) $id)
        ->all();
}

it('grants "Mark sold out" to every existing Super Admin and Manager role, and to nobody else', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $superA = p4Role($a->id, 'merchant_super_admin');
    $managerA = p4Role($a->id, 'merchant_manager');
    $viewerA = p4Role($a->id, 'merchant_viewer');
    $cashierSupA = p4Role($a->id, 'merchant_cashier_supervisor');
    $customA = p4Role($a->id, 'Shift lead');
    $managerB = p4Role($b->id, 'merchant_manager');
    $platform = p4Role(0, 'platform_sold_out_probe');

    p4SoldOutGrantMigration()->up();

    expect(DB::table('pos_permissions')->where('name', 'catalogue.sold_out')->where('guard_name', 'web')->count())->toBe(1)
        ->and(p4RolesWithSoldOut())->toBe([$superA, $managerA, $managerB]);
    expect(p4RolesWithSoldOut())->not->toContain($viewerA)
        ->not->toContain($cashierSupA)
        ->not->toContain($customA)
        ->not->toContain($platform);

    // Idempotent: a second run adds nothing and fails on nothing.
    p4SoldOutGrantMigration()->up();
    expect(DB::table('pos_permissions')->where('name', 'catalogue.sold_out')->count())->toBe(1)
        ->and(p4RolesWithSoldOut())->toBe([$superA, $managerA, $managerB]);
});

it('keeps a grant the merchant already made and removes the permission on rollback', function (): void {
    $company = Company::factory()->create();
    $custom = p4Role($company->id, 'Shift lead');
    $manager = p4Role($company->id, 'merchant_manager');
    $permissionId = (int) DB::table('pos_permissions')->where('name', 'catalogue.sold_out')->value('id');
    expect($permissionId)->toBeGreaterThan(0);
    DB::table('pos_role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $custom]);

    p4SoldOutGrantMigration()->up();
    expect(p4RolesWithSoldOut())->toBe([$custom, $manager]);

    p4SoldOutGrantMigration()->down();
    expect(DB::table('pos_permissions')->where('name', 'catalogue.sold_out')->exists())->toBeFalse()
        ->and(p4RolesWithSoldOut())->toBe([]);
});
