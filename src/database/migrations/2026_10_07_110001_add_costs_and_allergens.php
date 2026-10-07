<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH costs & allergens add-on (LAUNCH-COSTS_ALLERGENS_WORK_ORDER.md,
 * tester calls 1-3), Part A schema.
 *
 * pos_ingredient_allergens — the allergens a merchant ticked on an
 *   ingredient row: a raw ingredient or a prep item (a prep item's own ticks
 *   add to what its recipe brings).
 *   company_id   the ingredient's merchant (cascade; integrity-checked)
 *   allergen     one of the 14 fixed codes (CHECK): gluten, crustaceans,
 *                eggs, fish, peanuts, soy, milk, tree_nuts, celery, mustard,
 *                sesame, sulphites, lupin, molluscs
 *   One row per ingredient and allergen (UNIQUE).
 *
 * pos_product_allergens — what a merchant set by hand on a product:
 *   kind 'contains'     the product itself contains it (a bought-in item or
 *                       a physical item has no recipe to work it out from)
 *   kind 'may_contain'  "may contain" (traces), added by hand on a dish
 *   One row per product, allergen and kind (UNIQUE). A dish's allergens are
 *   otherwise WORKED OUT, never stored: its recipe (prep items followed down
 *   to their ingredients), its components and, for a combo, its items.
 *
 * pos_products.target_food_cost_percent — the dish's own target food cost %
 *   (NULL = the company target, a pos_company_settings key; > 0 and <= 100,
 *   CHECK).
 *
 * pos_price_alert_reviews — a price alert a manager marked as seen. An
 *   alert is not stored: it is a goods-received ingredient line whose price
 *   per base unit moved from the previous purchase of the same ingredient by
 *   at least the company threshold; this row records that it was seen, by
 *   whom and when. One row per receipt line (UNIQUE).
 *
 * Index pos_purchase_receipt_lines (ingredient_id): the price history reads
 * an ingredient's purchase lines.
 *
 * Every table is new and empty and the new column is NULL on every row: no
 * existing value changes. On Postgres the CHECKs back the codes and ranges;
 * SQLite (the test mirrors) relies on the app and the integrity checks.
 * down() refuses once a tag, a target or a review exists.
 */
return new class extends Migration
{
    public const ALLERGENS = [
        'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
        'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs',
    ];

    /** @return array<string, array{0: string, 1: string}> constraint => [table, expression] */
    private function checks(): array
    {
        $codes = implode(', ', array_map(static fn (string $c): string => "'".$c."'", self::ALLERGENS));

        return [
            'pos_ingredient_allergens_allergen_check' => ['pos_ingredient_allergens', '"allergen" IN ('.$codes.')'],
            'pos_product_allergens_allergen_check' => ['pos_product_allergens', '"allergen" IN ('.$codes.')'],
            'pos_product_allergens_kind_check' => ['pos_product_allergens', '"kind" IN (\'contains\', \'may_contain\')'],
            'pos_products_target_food_cost_check' => ['pos_products', '"target_food_cost_percent" IS NULL OR ("target_food_cost_percent" > 0 AND "target_food_cost_percent" <= 100)'],
        ];
    }

    public function up(): void
    {
        Schema::create('pos_ingredient_allergens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->string('allergen', 16);
            $table->timestamps();
            $table->unique(['ingredient_id', 'allergen'], 'pos_ingredient_allergens_ingredient_allergen_unique');
            $table->index(['company_id'], 'pos_ingredient_allergens_company_idx');
        });

        Schema::create('pos_product_allergens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->string('allergen', 16);
            $table->string('kind', 16);
            $table->timestamps();
            $table->unique(['product_id', 'allergen', 'kind'], 'pos_product_allergens_product_allergen_kind_unique');
            $table->index(['company_id'], 'pos_product_allergens_company_idx');
        });

        Schema::table('pos_products', function (Blueprint $table): void {
            $table->decimal('target_food_cost_percent', 5, 2)->nullable();
        });

        Schema::create('pos_price_alert_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('purchase_receipt_line_id')->constrained('pos_purchase_receipt_lines')->cascadeOnDelete();
            $table->foreignId('seen_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->unique(['purchase_receipt_line_id'], 'pos_price_alert_reviews_line_unique');
            $table->index(['company_id', 'seen_at'], 'pos_price_alert_reviews_company_seen_idx');
        });

        Schema::table('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->index(['ingredient_id'], 'pos_purchase_receipt_lines_ingredient_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->checks() as $name => [$table, $expression]) {
                DB::statement('ALTER TABLE "'.$table.'" ADD CONSTRAINT "'.$name.'" CHECK ('.$expression.')');
            }
        }
    }

    /**
     * Refuses once a merchant ticked an allergen, set a dish target or marked
     * an alert seen: dropping them would lose that work.
     */
    public function down(): void
    {
        if (Schema::hasTable('pos_ingredient_allergens') && (
            DB::table('pos_ingredient_allergens')->exists()
            || DB::table('pos_product_allergens')->exists()
            || DB::table('pos_price_alert_reviews')->exists()
            || DB::table('pos_products')->whereNotNull('target_food_cost_percent')->exists()
        )) {
            throw new RuntimeException('Cannot roll back 2026_10_07_110001: allergens, dish targets or seen price alerts already exist.');
        }

        Schema::table('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->dropIndex('pos_purchase_receipt_lines_ingredient_idx');
        });
        Schema::dropIfExists('pos_price_alert_reviews');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_products" DROP CONSTRAINT IF EXISTS "pos_products_target_food_cost_check"');
        }
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('target_food_cost_percent');
        });
        Schema::dropIfExists('pos_product_allergens');
        Schema::dropIfExists('pos_ingredient_allergens');
    }
};
