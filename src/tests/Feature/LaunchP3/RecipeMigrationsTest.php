<?php

declare(strict_types=1);

/*
 * LAUNCH-P3 schema (pos_admin owns every migration; the data contract in
 * LAUNCH-P3_WORK_ORDER.md is shared with pos_api):
 *  - P3-4 pos_ingredients.is_prep + prep_yield_quantity, and the prep recipe
 *         table pos_ingredient_recipes;
 *  - P3-1 entered_unit / entered_quantity on product recipe and add-on lines;
 *  - P3-3 the "Edit recipes" permission granted to the Super Admin and
 *         Manager system roles of every existing merchant;
 *  - the tenant-integrity check flags a prep recipe line whose component
 *    belongs to another company.
 *
 * The suites run on SQLite; the Postgres-only CHECK constraints are verified
 * in the live-copy rehearsal (rehearse-p3.sh).
 */

use App\Models\Company;
use App\Services\TenantIntegrityChecks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p3Ingredient(int $companyId, string $name, array $extra = []): int
{
    return DB::table('pos_ingredients')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'name' => $name,
        'unit' => 'g',
        'default_unit_cost' => '0.002000',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function p3GrantMigration(): object
{
    return require database_path('migrations/2026_10_02_100004_grant_recipe_permission_to_default_roles.php');
}

function p3Role(int $teamId, string $name): int
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

/** @return list<int> role ids holding catalogue.recipes.manage */
function p3RolesWithRecipePermission(): array
{
    return DB::table('pos_role_has_permissions')
        ->join('pos_permissions', 'pos_permissions.id', '=', 'pos_role_has_permissions.permission_id')
        ->where('pos_permissions.name', 'catalogue.recipes.manage')
        ->orderBy('role_id')
        ->pluck('role_id')
        ->map(static fn ($id): int => (int) $id)
        ->all();
}

it('marks prep items with a yield and leaves every existing ingredient a plain one', function (): void {
    expect(Schema::hasColumns('pos_ingredients', ['is_prep', 'prep_yield_quantity']))->toBeTrue();

    $company = Company::factory()->create();
    $flour = p3Ingredient($company->id, 'Flour');
    $sauce = p3Ingredient($company->id, 'Tomato sauce', ['unit' => 'ml', 'is_prep' => true, 'prep_yield_quantity' => '2000.0000']);

    $row = DB::table('pos_ingredients')->find($flour);
    expect((bool) $row->is_prep)->toBeFalse()
        ->and($row->prep_yield_quantity)->toBeNull();

    $prep = DB::table('pos_ingredients')->find($sauce);
    expect((bool) $prep->is_prep)->toBeTrue()
        ->and((float) $prep->prep_yield_quantity)->toBe(2000.0);
});

it('stores a prep recipe per batch, one line per component, with the entered unit', function (): void {
    expect(Schema::hasColumns('pos_ingredient_recipes', [
        'id', 'prep_ingredient_id', 'ingredient_id', 'quantity', 'entered_unit', 'entered_quantity', 'sort_order', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $company = Company::factory()->create();
    $tomato = p3Ingredient($company->id, 'Tomato');
    $sauce = p3Ingredient($company->id, 'Tomato sauce', ['unit' => 'ml', 'is_prep' => true, 'prep_yield_quantity' => '2000']);

    DB::table('pos_ingredient_recipes')->insert([
        'prep_ingredient_id' => $sauce, 'ingredient_id' => $tomato, 'quantity' => '1500.0000',
        'entered_unit' => 'kg', 'entered_quantity' => '1.5000', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $line = DB::table('pos_ingredient_recipes')->first();
    expect((int) $line->sort_order)->toBe(0)
        ->and($line->entered_unit)->toBe('kg')
        ->and((float) $line->entered_quantity)->toBe(1.5)
        ->and((float) $line->quantity)->toBe(1500.0);

    // One line per component.
    expect(fn () => DB::table('pos_ingredient_recipes')->insert([
        'prep_ingredient_id' => $sauce, 'ingredient_id' => $tomato, 'quantity' => '1', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // A component cannot be hard-deleted while a recipe names it ...
    expect(fn () => DB::table('pos_ingredients')->where('id', $tomato)->delete())->toThrow(QueryException::class);
    // ... and the prep item's recipe goes with it.
    DB::table('pos_ingredients')->where('id', $sauce)->delete();
    expect(DB::table('pos_ingredient_recipes')->count())->toBe(0);
});

it('lets product recipe and add-on lines remember the unit they were entered in, existing lines staying NULL', function (): void {
    foreach (['pos_product_recipes', 'pos_addon_consumptions'] as $table) {
        expect(Schema::hasColumns($table, ['entered_unit', 'entered_quantity']))->toBeTrue("{$table} entered columns");
    }

    $migration = require database_path('migrations/2026_10_02_100003_add_entered_unit_to_recipe_lines.php');
    $migration->down();
    expect(Schema::hasColumn('pos_product_recipes', 'entered_unit'))->toBeFalse()
        ->and(Schema::hasColumn('pos_addon_consumptions', 'entered_quantity'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumns('pos_product_recipes', ['entered_unit', 'entered_quantity']))->toBeTrue()
        ->and(Schema::hasColumns('pos_addon_consumptions', ['entered_unit', 'entered_quantity']))->toBeTrue();
});

it('grants "Edit recipes" to every existing Super Admin and Manager role, and to nobody else', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $superA = p3Role($a->id, 'merchant_super_admin');
    $managerA = p3Role($a->id, 'merchant_manager');
    $viewerA = p3Role($a->id, 'merchant_viewer');
    $inventoryA = p3Role($a->id, 'merchant_inventory_manager');
    $customA = p3Role($a->id, 'Head chef');
    $managerB = p3Role($b->id, 'merchant_manager');
    $platform = p3Role(0, 'platform_recipe_probe');

    p3GrantMigration()->up();

    expect(DB::table('pos_permissions')->where('name', 'catalogue.recipes.manage')->where('guard_name', 'web')->count())->toBe(1)
        ->and(p3RolesWithRecipePermission())->toBe([$superA, $managerA, $managerB]);
    expect(p3RolesWithRecipePermission())->not->toContain($viewerA)
        ->not->toContain($inventoryA)
        ->not->toContain($customA)
        ->not->toContain($platform);

    // Idempotent: a second run adds nothing and fails on nothing.
    p3GrantMigration()->up();
    expect(DB::table('pos_permissions')->where('name', 'catalogue.recipes.manage')->count())->toBe(1)
        ->and(p3RolesWithRecipePermission())->toBe([$superA, $managerA, $managerB]);
});

it('keeps a grant the merchant already made and removes the permission on rollback', function (): void {
    $company = Company::factory()->create();
    $custom = p3Role($company->id, 'Head chef');
    $manager = p3Role($company->id, 'merchant_manager');
    $permissionId = (int) DB::table('pos_permissions')->where('name', 'catalogue.recipes.manage')->value('id');
    expect($permissionId)->toBeGreaterThan(0);
    DB::table('pos_role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $custom]);

    p3GrantMigration()->up();
    expect(p3RolesWithRecipePermission())->toBe([$custom, $manager]);

    p3GrantMigration()->down();
    expect(DB::table('pos_permissions')->where('name', 'catalogue.recipes.manage')->exists())->toBeFalse()
        ->and(p3RolesWithRecipePermission())->toBe([]);
});

it('flags a prep recipe line whose component belongs to another company', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $sauce = p3Ingredient($a->id, 'Sauce', ['unit' => 'ml', 'is_prep' => true, 'prep_yield_quantity' => '1000']);
    $own = p3Ingredient($a->id, 'Tomato');
    $foreign = p3Ingredient($b->id, 'Garlic');
    DB::table('pos_ingredient_recipes')->insert([
        'prep_ingredient_id' => $sauce, 'ingredient_id' => $own, 'quantity' => '500', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(app(TenantIntegrityChecks::class)->run()['prep_recipe_company']['count'])->toBe(0);

    $leak = DB::table('pos_ingredient_recipes')->insertGetId([
        'prep_ingredient_id' => $sauce, 'ingredient_id' => $foreign, 'quantity' => '5', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(app(TenantIntegrityChecks::class)->run()['prep_recipe_company'])
        ->toMatchArray(['count' => 1, 'sample_ids' => [$leak], 'classification' => 'violation']);
});
