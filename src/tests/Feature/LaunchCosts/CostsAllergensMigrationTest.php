<?php

declare(strict_types=1);

/*
 * LAUNCH costs & allergens add-on, Part A schema (LAUNCH-COSTS_ALLERGENS_WORK_ORDER.md):
 * pos_admin owns the allergen tags (ingredients, prep items, products), the
 * dish's own target food cost % and the "seen" record of a price alert.
 *
 * The suites run on SQLite; the Postgres-only CHECK constraints are proven by
 * the live-copy rehearsal's must-fail SQL (rehearse-costs.sh).
 */

use App\Models\Company;
use App\Models\User;
use App\Services\TenantIntegrityChecks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function caIngredient(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_ingredients')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'unit' => 'g',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function caProduct(int $companyId, string $name, array $extra = []): int
{
    return (int) DB::table('pos_products')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'base_price' => '1.000',
        'stock_mode' => 'untracked', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** A goods-received line of $companyId's receipt; returns the line id. */
function caReceiptLine(int $companyId, ?int $ingredientId, string $type = 'ingredient'): int
{
    $receipt = (int) DB::table('pos_purchase_receipts')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) DB::table('pos_purchase_receipt_lines')->insertGetId([
        'purchase_receipt_id' => $receipt, 'item_type' => $type, 'ingredient_id' => $ingredientId, 'item_name' => 'Item',
        'quantity' => '1000', 'line_cost' => '1.000', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array<string, list<int>> the costs & allergens checks that report something */
function caFlagged(): array
{
    return collect(app(TenantIntegrityChecks::class)->run())
        ->filter(fn (array $r, string $check): bool => $r['count'] > 0
            && in_array($check, ['ingredient_allergen_company', 'product_allergen_company', 'price_alert_review_company'], true))
        ->map(fn (array $r): array => $r['sample_ids'])->all();
}

it('creates the allergen tables, the dish target column, the alert reviews and the price-history index', function (): void {
    expect(Schema::hasColumns('pos_ingredient_allergens', ['id', 'company_id', 'ingredient_id', 'allergen', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('pos_product_allergens', ['id', 'company_id', 'product_id', 'allergen', 'kind', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumn('pos_products', 'target_food_cost_percent'))->toBeTrue()
        ->and(Schema::hasColumns('pos_price_alert_reviews', ['id', 'company_id', 'purchase_receipt_line_id', 'seen_by_user_id', 'seen_at']))->toBeTrue()
        ->and(collect(Schema::getIndexes('pos_purchase_receipt_lines'))->pluck('name')->all())->toContain('pos_purchase_receipt_lines_ingredient_idx');

    $company = Company::factory()->create();
    $product = caProduct($company->id, 'Burger');
    expect(DB::table('pos_products')->where('id', $product)->value('target_food_cost_percent'))->toBeNull();
});

it('keeps one tag per ingredient and allergen, one per product, allergen and kind, and one review per line', function (): void {
    $company = Company::factory()->create();
    $cheese = caIngredient($company->id, 'Cheese');
    $burger = caProduct($company->id, 'Burger');
    $tag = ['company_id' => $company->id, 'ingredient_id' => $cheese, 'allergen' => 'milk', 'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_ingredient_allergens')->insert($tag);
    expect(fn () => DB::table('pos_ingredient_allergens')->insert($tag))->toThrow(QueryException::class);

    $own = ['company_id' => $company->id, 'product_id' => $burger, 'allergen' => 'sesame', 'kind' => 'may_contain', 'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_product_allergens')->insert($own);
    DB::table('pos_product_allergens')->insert(['kind' => 'contains'] + $own);
    expect(fn () => DB::table('pos_product_allergens')->insert($own))->toThrow(QueryException::class);

    $line = caReceiptLine($company->id, $cheese);
    $review = ['company_id' => $company->id, 'purchase_receipt_line_id' => $line, 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_price_alert_reviews')->insert($review);
    expect(fn () => DB::table('pos_price_alert_reviews')->insert($review))->toThrow(QueryException::class);

    // The tags and the review go with their ingredient / product / line.
    DB::table('pos_ingredient_allergens')->where('ingredient_id', $cheese)->delete();
    DB::table('pos_purchase_receipt_lines')->where('id', $line)->delete();
    DB::table('pos_products')->where('id', $burger)->delete();
    expect(DB::table('pos_price_alert_reviews')->count())->toBe(0)
        ->and(DB::table('pos_product_allergens')->count())->toBe(0);
});

it('reports tags and reviews that reach another merchant, never its own', function (): void {
    $mine = Company::factory()->create();
    $other = Company::factory()->create();
    $myCheese = caIngredient($mine->id, 'Cheese');
    $myBurger = caProduct($mine->id, 'Burger');
    $myLine = caReceiptLine($mine->id, $myCheese);
    $myUser = User::factory()->merchant()->create(['company_id' => $mine->id]);
    $otherUser = User::factory()->merchant()->create(['company_id' => $other->id]);

    DB::table('pos_ingredient_allergens')->insert(['company_id' => $mine->id, 'ingredient_id' => $myCheese, 'allergen' => 'milk', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_product_allergens')->insert(['company_id' => $mine->id, 'product_id' => $myBurger, 'allergen' => 'sesame', 'kind' => 'contains', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_price_alert_reviews')->insert(['company_id' => $mine->id, 'purchase_receipt_line_id' => $myLine, 'seen_by_user_id' => $myUser->id, 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(caFlagged())->toBe([]);

    $badTag = (int) DB::table('pos_ingredient_allergens')->insertGetId(['company_id' => $other->id, 'ingredient_id' => $myCheese, 'allergen' => 'eggs', 'created_at' => now(), 'updated_at' => now()]);
    $badOwn = (int) DB::table('pos_product_allergens')->insertGetId(['company_id' => $other->id, 'product_id' => $myBurger, 'allergen' => 'fish', 'kind' => 'may_contain', 'created_at' => now(), 'updated_at' => now()]);
    $otherLine = caReceiptLine($other->id, caIngredient($other->id, 'Flour'));
    $badReview = (int) DB::table('pos_price_alert_reviews')->insertGetId(['company_id' => $mine->id, 'purchase_receipt_line_id' => $otherLine, 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $productLine = caReceiptLine($mine->id, null, 'product');
    $notIngredient = (int) DB::table('pos_price_alert_reviews')->insertGetId(['company_id' => $mine->id, 'purchase_receipt_line_id' => $productLine, 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $mySecondLine = caReceiptLine($mine->id, $myCheese);
    $foreignUser = (int) DB::table('pos_price_alert_reviews')->insertGetId(['company_id' => $mine->id, 'purchase_receipt_line_id' => $mySecondLine, 'seen_by_user_id' => $otherUser->id, 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    expect(caFlagged())->toBe([
        'ingredient_allergen_company' => [$badTag],
        'product_allergen_company' => [$badOwn],
        'price_alert_review_company' => [$badReview, $notIngredient, $foreignUser],
    ]);
});

it('refuses to roll back once a tag, a dish target or a seen alert exists, and rolls back cleanly before', function (): void {
    $migration = require database_path('migrations/2026_10_07_110001_add_costs_and_allergens.php');
    $company = Company::factory()->create();
    $burger = caProduct($company->id, 'Burger', ['target_food_cost_percent' => '28.50']);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back 2026_10_07_110001')
        ->and(Schema::hasColumn('pos_products', 'target_food_cost_percent'))->toBeTrue();

    DB::table('pos_products')->where('id', $burger)->update(['target_food_cost_percent' => null]);
    $cheese = caIngredient($company->id, 'Cheese');
    DB::table('pos_ingredient_allergens')->insert(['company_id' => $company->id, 'ingredient_id' => $cheese, 'allergen' => 'milk', 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back 2026_10_07_110001');

    DB::table('pos_ingredient_allergens')->delete();
    $migration->down();
    expect(Schema::hasTable('pos_ingredient_allergens'))->toBeFalse()
        ->and(Schema::hasTable('pos_product_allergens'))->toBeFalse()
        ->and(Schema::hasTable('pos_price_alert_reviews'))->toBeFalse()
        ->and(Schema::hasColumn('pos_products', 'target_food_cost_percent'))->toBeFalse()
        ->and(collect(Schema::getIndexes('pos_purchase_receipt_lines'))->pluck('name')->all())->not->toContain('pos_purchase_receipt_lines_ingredient_idx')
        ->and(DB::table('pos_products')->where('id', $burger)->exists())->toBeTrue();

    $migration->up();
    expect(Schema::hasTable('pos_price_alert_reviews'))->toBeTrue()->and(Schema::hasColumn('pos_products', 'target_food_cost_percent'))->toBeTrue();
});
