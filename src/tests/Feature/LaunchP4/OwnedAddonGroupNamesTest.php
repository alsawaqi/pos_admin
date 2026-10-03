<?php

declare(strict_types=1);

/*
 * LAUNCH-P4 M4 — owned add-on group names are unique per owner product, so
 * every product can own a "Size" group; shared groups stay one name per
 * company, as before. Soft-deleted rows still count.
 */

use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function m4Product(int $companyId, string $name): int
{
    return (int) DB::table('pos_products')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'base_price' => '1.000',
        'stock_mode' => 'untracked', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function m4Group(int $companyId, string $name, ?int $ownerProductId = null, array $extra = []): int
{
    return (int) DB::table('pos_addon_groups')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name,
        'owner_product_id' => $ownerProductId, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('lets every product own its own "Size" group', function (): void {
    $company = Company::factory()->create();
    $latte = m4Product($company->id, 'Latte');
    $mocha = m4Product($company->id, 'Mocha');

    m4Group($company->id, 'Size', $latte);
    m4Group($company->id, 'Size', $mocha);

    expect(DB::table('pos_addon_groups')->where('name', 'Size')->count())->toBe(2);
});

it('still refuses two owned groups of the same name on one product, even a deleted one', function (): void {
    $company = Company::factory()->create();
    $latte = m4Product($company->id, 'Latte');
    m4Group($company->id, 'Size', $latte, ['deleted_at' => now()]);

    expect(fn () => m4Group($company->id, 'Size', $latte))->toThrow(QueryException::class);
});

it('keeps shared group names unique per company and lets an owned group reuse a shared name', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $latte = m4Product($a->id, 'Latte');

    m4Group($a->id, 'Extras');
    m4Group($b->id, 'Extras');
    m4Group($a->id, 'Extras', $latte);

    expect(fn () => m4Group($a->id, 'Extras'))->toThrow(QueryException::class);
    expect(DB::table('pos_addon_groups')->where('name', 'Extras')->count())->toBe(3);
});
