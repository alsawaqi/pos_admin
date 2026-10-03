<?php

declare(strict_types=1);

/*
 * LAUNCH-P3 fix order 1, K4 — a prep waste is ONE event that names the prep.
 * pos_waste_records gets two nullable columns: prep_ingredient_id (the prep
 * item wasted) and waste_group_uuid (shared by every record of that one
 * waste event). Existing rows stay NULL; the tenant-integrity check flags a
 * prep item of another company.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Services\TenantIntegrityChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function k4Ingredient(int $companyId, string $name, array $extra = []): int
{
    return DB::table('pos_ingredients')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'unit' => 'g',
        'default_unit_cost' => '0.002000', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function k4Waste(int $branchId, int $ingredientId, array $extra = []): int
{
    return DB::table('pos_waste_records')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'branch_id' => $branchId, 'ingredient_id' => $ingredientId, 'quantity' => '10.0000',
        'reason' => 'spoilage', 'unit_at_set' => 'g', 'unit_cost_at_time' => '0.002000',
        'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('adds the prep item and the event group to waste records, existing rows staying NULL', function (): void {
    expect(Schema::hasColumns('pos_waste_records', ['prep_ingredient_id', 'waste_group_uuid']))->toBeTrue();

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $garlic = k4Ingredient($company->id, 'Garlic');
    $toum = k4Ingredient($company->id, 'Toum', ['unit' => 'g', 'is_prep' => true, 'prep_yield_quantity' => '1000']);

    $plain = k4Waste($branch->id, $garlic);
    $group = (string) Str::uuid();
    $prepLine = k4Waste($branch->id, $garlic, ['prep_ingredient_id' => $toum, 'waste_group_uuid' => $group]);

    expect(DB::table('pos_waste_records')->find($plain)->prep_ingredient_id)->toBeNull()
        ->and(DB::table('pos_waste_records')->find($plain)->waste_group_uuid)->toBeNull()
        ->and((int) DB::table('pos_waste_records')->find($prepLine)->prep_ingredient_id)->toBe($toum)
        ->and(DB::table('pos_waste_records')->find($prepLine)->waste_group_uuid)->toBe($group);

    // The record outlives a hard-deleted prep item (it only loses the name).
    DB::table('pos_ingredients')->where('id', $toum)->delete();
    expect(DB::table('pos_waste_records')->find($prepLine)->prep_ingredient_id)->toBeNull();

    $migration = require database_path('migrations/2026_10_02_100005_add_prep_grouping_to_pos_waste_records.php');
    $migration->down();
    expect(Schema::hasColumn('pos_waste_records', 'prep_ingredient_id'))->toBeFalse()
        ->and(Schema::hasColumn('pos_waste_records', 'waste_group_uuid'))->toBeFalse()
        ->and(DB::table('pos_waste_records')->count())->toBe(2);
    $migration->up();
    expect(Schema::hasColumns('pos_waste_records', ['prep_ingredient_id', 'waste_group_uuid']))->toBeTrue();
});

it('flags a prep waste naming a prep item of another company', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branch = Branch::factory()->for($a)->create();
    $garlic = k4Ingredient($a->id, 'Garlic');
    $own = k4Ingredient($a->id, 'Toum', ['is_prep' => true, 'prep_yield_quantity' => '1000']);
    $foreign = k4Ingredient($b->id, 'Other toum', ['is_prep' => true, 'prep_yield_quantity' => '1000']);

    k4Waste($branch->id, $garlic, ['prep_ingredient_id' => $own, 'waste_group_uuid' => (string) Str::uuid()]);
    expect(app(TenantIntegrityChecks::class)->run()['waste_prep_company']['count'])->toBe(0);

    $leak = k4Waste($branch->id, $garlic, ['prep_ingredient_id' => $foreign, 'waste_group_uuid' => (string) Str::uuid()]);
    expect(app(TenantIntegrityChecks::class)->run()['waste_prep_company'])
        ->toMatchArray(['count' => 1, 'sample_ids' => [$leak], 'classification' => 'violation']);
});
