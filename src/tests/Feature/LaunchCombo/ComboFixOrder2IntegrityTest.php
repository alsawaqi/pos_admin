<?php

declare(strict_types=1);

/*
 * LAUNCH combo add-on, Part A fix order 2 (C-17): the meal clash integrity
 * check uses the merchant's date (Asia/Muscat, UTC+4) from the app clock,
 * like the portal and the server, not the database's CURRENT_DATE.
 */

use App\Models\Company;
use App\Services\TenantIntegrityChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('ends a meal at midnight in Muscat, not at the database date', function (): void {
    $company = Company::factory()->create();
    $burgers = (int) DB::table('pos_product_categories')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Burgers', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $beef = (int) DB::table('pos_products')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Beef burger',
        'base_price' => '2.000', 'stock_mode' => 'untracked', 'status' => 'active', 'category_id' => $burgers, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['Winter meal', '2030-01-01'], ['Burger meal', null]] as [$name, $until]) {
        $id = (int) DB::table('pos_meals')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => $name,
            'meal_price' => '1.200', 'on_sale_until' => $until, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_meal_categories')->insert(['company_id' => $company->id, 'meal_id' => $id, 'category_id' => $burgers, 'created_at' => now(), 'updated_at' => now()]);
    }
    $clash = static fn (): array => app(TenantIntegrityChecks::class)->run()['meal_main_in_two_meals']['sample_ids'];

    // 23:59 on 1 January in Muscat (19:59 UTC): the winter meal's last day, both on sale.
    Carbon::setTestNow(Carbon::parse('2030-01-01 19:59:00', 'UTC'));
    expect($clash())->toBe([$beef]);

    // 00:00 on 2 January in Muscat (20:00 UTC, still 1 January in UTC): it has ended.
    Carbon::setTestNow(Carbon::parse('2030-01-01 20:00:00', 'UTC'));
    expect($clash())->toBe([]);
    Carbon::setTestNow();
});
