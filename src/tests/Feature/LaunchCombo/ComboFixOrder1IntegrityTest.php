<?php

declare(strict_types=1);

/*
 * LAUNCH combo add-on, Part A fix order 1 (C-4, L6): a main belongs to at
 * most one ACTIVE, ON-SALE meal. pos:check-tenant-integrity reports two
 * meals on one main only while both are active, neither has ended and their
 * sale dates overlap.
 */

use App\Models\Company;
use App\Services\TenantIntegrityChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function cf1Clash(): array
{
    return app(TenantIntegrityChecks::class)->run()['meal_main_in_two_meals']['sample_ids'];
}

it('reports a main in two meals only while both are active, not ended and overlapping in dates', function (): void {
    $company = Company::factory()->create();
    $burgers = (int) DB::table('pos_product_categories')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Burgers', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $beef = (int) DB::table('pos_products')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Beef burger',
        'base_price' => '2.000', 'stock_mode' => 'untracked', 'status' => 'active', 'category_id' => $burgers, 'created_at' => now(), 'updated_at' => now()]);
    $meal = function (string $name, ?string $from, ?string $until) use ($company, $burgers): int {
        $id = (int) DB::table('pos_meals')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => $name,
            'meal_price' => '1.200', 'on_sale_from' => $from, 'on_sale_until' => $until, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_meal_categories')->insert(['company_id' => $company->id, 'meal_id' => $id, 'category_id' => $burgers, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $today = now()->format('Y-m-d');
    $meal('Summer meal', null, now()->subDay()->format('Y-m-d'));
    $meal('Burger meal', null, null);
    // An ended meal does not count.
    expect(cf1Clash())->toBe([]);

    // A meal that starts after another ends does not overlap it.
    $autumn = $meal('Autumn meal', now()->addDays(10)->format('Y-m-d'), now()->addDays(20)->format('Y-m-d'));
    DB::table('pos_meals')->where('name', 'Burger meal')->update(['on_sale_until' => now()->addDays(5)->format('Y-m-d')]);
    expect(cf1Clash())->toBe([]);

    // Overlapping dates clash.
    DB::table('pos_meals')->where('id', $autumn)->update(['on_sale_from' => $today]);
    expect(cf1Clash())->toBe([$beef]);
});
