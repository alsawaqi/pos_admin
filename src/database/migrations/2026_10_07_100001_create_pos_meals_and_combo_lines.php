<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH combo add-on (LAUNCH-COMBO_WORK_ORDER.md §1, §2.2) — combos and
 * meals are lists of LINES (owner decisions 1-4, 2026-10-07).
 *
 * pos_meals — a "Make it a meal?" setup:
 *   company_id            the merchant (cascade)
 *   name / name_ar        shown after the main's name ("Beef burger" + "meal")
 *   meal_price            added to the main's own price (>= 0, CHECK)
 *   status                'active' | 'inactive' (CHECK)
 *   on_sale_from / until  optional limited-time dates (inclusive, Asia/Muscat;
 *                         until >= from, CHECK)
 *   sort_order, timestamps, deleted_at (soft delete: order lines keep the id)
 *
 * pos_meal_categories — the categories whose products are the meal's mains
 *   (products added to such a category later join automatically).
 * pos_meal_excluded_products — the mains the merchant unticked.
 *   A main belongs to at most ONE active meal: the portal refuses a save that
 *   would make two active meals cover one product (the clash rule), and
 *   pos:check-tenant-integrity reports one that slipped in.
 *
 * pos_combo_lines — one line of a combo (combo_product_id) OR of a meal
 * (meal_id), exactly one owner (CHECK):
 *   kind 'fixed'   product_id + quantity (1..99): always included, never asked
 *   kind 'choice'  category_id + pick_count (1..20) + the question name /
 *                  name_ar: the customer picks pick_count items of the
 *                  category (repeats allowed)
 *   The other kind's columns stay NULL (CHECK). sort_order orders the lines.
 *
 * pos_combo_line_upgrades — a FIXED line's upgrades: a real product (sold on
 *   its own too) the customer may swap to, at upgrade_price (>= 0, CHECK).
 * pos_combo_line_items — a CHOICE line's per-item overrides: excluded (the
 *   merchant unticked it) and extra_price (>= 0, CHECK; 0 = free). A category
 *   product without a row is in, free.
 *
 * Every row carries company_id; pos:check-tenant-integrity checks that every
 * product, category, meal and owner named belongs to that company. On
 * Postgres the CHECKs back the rules; SQLite (the test mirror) relies on the
 * app and the integrity checks. pos_api and pos_merchant mirror the tables in
 * their test schemas. Every table is new and empty: no existing row changes.
 * down() refuses once a combo line or a meal exists.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> constraint => [table, expression] */
    private const CHECKS = [
        'pos_meals_meal_price_check' => ['pos_meals', '"meal_price" >= 0'],
        'pos_meals_status_check' => ['pos_meals', '"status" IN (\'active\', \'inactive\')'],
        'pos_meals_dates_check' => ['pos_meals', '"on_sale_until" IS NULL OR "on_sale_from" IS NULL OR "on_sale_until" >= "on_sale_from"'],
        'pos_combo_lines_owner_check' => ['pos_combo_lines', '("combo_product_id" IS NULL) <> ("meal_id" IS NULL)'],
        'pos_combo_lines_kind_check' => ['pos_combo_lines', '("kind" = \'fixed\' AND "product_id" IS NOT NULL AND "quantity" BETWEEN 1 AND 99
            AND "category_id" IS NULL AND "pick_count" IS NULL)
            OR ("kind" = \'choice\' AND "category_id" IS NOT NULL AND "pick_count" BETWEEN 1 AND 20 AND "name" IS NOT NULL
            AND "product_id" IS NULL AND "quantity" IS NULL)'],
        'pos_combo_line_upgrades_price_check' => ['pos_combo_line_upgrades', '"upgrade_price" >= 0'],
        'pos_combo_line_items_extra_price_check' => ['pos_combo_line_items', '"extra_price" >= 0'],
    ];

    public function up(): void
    {
        Schema::create('pos_meals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->decimal('meal_price', 12, 3)->default(0);
            $table->string('status', 16)->default('active');
            $table->date('on_sale_from')->nullable();
            $table->date('on_sale_until')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'status'], 'pos_meals_company_status_idx');
        });

        Schema::create('pos_meal_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('meal_id')->constrained('pos_meals')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('pos_product_categories')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['meal_id', 'category_id'], 'pos_meal_categories_meal_category_unique');
            $table->index(['category_id'], 'pos_meal_categories_category_idx');
        });

        Schema::create('pos_meal_excluded_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('meal_id')->constrained('pos_meals')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['meal_id', 'product_id'], 'pos_meal_excluded_products_meal_product_unique');
            $table->index(['product_id'], 'pos_meal_excluded_products_product_idx');
        });

        Schema::create('pos_combo_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('combo_product_id')->nullable()->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('meal_id')->nullable()->constrained('pos_meals')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->restrictOnDelete();
            $table->integer('quantity')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('pos_product_categories')->restrictOnDelete();
            $table->integer('pick_count')->nullable();
            $table->string('name', 64)->nullable();
            $table->string('name_ar', 64)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['combo_product_id', 'sort_order'], 'pos_combo_lines_combo_sort_idx');
            $table->index(['meal_id', 'sort_order'], 'pos_combo_lines_meal_sort_idx');
            // "Which combos and meals use this product / category?" (delete guards).
            $table->index(['product_id'], 'pos_combo_lines_product_idx');
            $table->index(['category_id'], 'pos_combo_lines_category_idx');
        });

        Schema::create('pos_combo_line_upgrades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('pos_combo_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->restrictOnDelete();
            $table->decimal('upgrade_price', 12, 3)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['line_id', 'product_id'], 'pos_combo_line_upgrades_line_product_unique');
            $table->index(['product_id'], 'pos_combo_line_upgrades_product_idx');
        });

        Schema::create('pos_combo_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('pos_combo_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->boolean('excluded')->default(false);
            $table->decimal('extra_price', 12, 3)->default(0);
            $table->timestamps();
            $table->unique(['line_id', 'product_id'], 'pos_combo_line_items_line_product_unique');
            $table->index(['product_id'], 'pos_combo_line_items_product_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::CHECKS as $name => [$table, $expression]) {
                DB::statement('ALTER TABLE "'.$table.'" ADD CONSTRAINT "'.$name.'" CHECK ('.$expression.')');
            }
        }
    }

    /**
     * Refuses once a merchant has set up a combo line or a meal: dropping
     * the tables would lose them (re-running up() cannot bring them back).
     */
    public function down(): void
    {
        if (Schema::hasTable('pos_combo_lines') && (DB::table('pos_combo_lines')->exists() || DB::table('pos_meals')->exists())) {
            throw new RuntimeException('Cannot roll back 2026_10_07_100001: combos or meals already use the new lines.');
        }
        Schema::dropIfExists('pos_combo_line_items');
        Schema::dropIfExists('pos_combo_line_upgrades');
        Schema::dropIfExists('pos_combo_lines');
        Schema::dropIfExists('pos_meal_excluded_products');
        Schema::dropIfExists('pos_meal_categories');
        Schema::dropIfExists('pos_meals');
    }
};
