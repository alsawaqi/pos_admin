<?php

declare(strict_types=1);

/*
 * LAUNCH review add-on, fix A-2 — 2026_10_06_100011: a live barcode is unique
 * per company ignoring case (like a SKU). The Postgres index is proven by the
 * rehearsal's must-fail SQL (barcode_differs_only_in_case).
 */

use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function a2Migration(): object
{
    return require database_path('migrations/2026_10_06_100011_make_item_barcodes_unique_case_insensitive.php');
}

function a2Barcode(int $companyId, int $ingredientId, string $code, array $extra = []): int
{
    return (int) DB::table('pos_item_barcodes')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'barcode' => $code, 'ingredient_id' => $ingredientId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function a2Ingredient(int $companyId): int
{
    return (int) DB::table('pos_ingredients')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'name' => 'Milk', 'unit' => 'ml', 'created_at' => now(), 'updated_at' => now()]);
}

it('refuses a second live barcode of the company that differs only in case', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $milkA = a2Ingredient($a->id);
    $milkB = a2Ingredient($b->id);
    $first = a2Barcode($a->id, $milkA, 'ABC-0062911');

    expect(fn () => a2Barcode($a->id, $milkA, 'abc-0062911'))->toThrow(QueryException::class);

    // Another company may use it; a soft-deleted one frees it.
    a2Barcode($b->id, $milkB, 'abc-0062911');
    DB::table('pos_item_barcodes')->where('id', $first)->update(['deleted_at' => now()]);
    a2Barcode($a->id, $milkA, 'abc-0062911');
    expect(DB::table('pos_item_barcodes')->whereNull('deleted_at')->count())->toBe(2);
});

it('rolls back to the case-sensitive index and forward again', function (): void {
    $company = Company::factory()->create();
    $milk = a2Ingredient($company->id);
    $migration = a2Migration();

    $migration->down();
    a2Barcode($company->id, $milk, 'XYZ');
    a2Barcode($company->id, $milk, 'xyz');
    expect(fn () => a2Barcode($company->id, $milk, 'XYZ'))->toThrow(QueryException::class);

    // Two live codes that differ only in case: the index cannot be made, nothing changes.
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'differ only in case');
    DB::table('pos_item_barcodes')->where('barcode', 'xyz')->update(['deleted_at' => now()]);

    $migration->up();
    expect(fn () => a2Barcode($company->id, $milk, 'xYz'))->toThrow(QueryException::class);
});
