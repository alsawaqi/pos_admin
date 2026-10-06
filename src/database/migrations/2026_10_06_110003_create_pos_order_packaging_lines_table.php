<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH packaging add-on §1 owner decision 3 — per-order packaging per
 * order type, set once per merchant (the same for every branch: no
 * branch_id). Each line is taken ONCE per whole order (× 1, not × items),
 * when the order's stock is taken (payment, or delivery hand-off).
 *
 *   company_id        the merchant (cascade)
 *   order_type        'dine_in' | 'quick' | 'to_go' | 'delivery'
 *                     (`car` orders use the to_go list; Postgres CHECK)
 *   ingredient_id     an ingredient (never a prep item: app rule), or
 *   product_id        a physical item / bought-in unit product, in pieces;
 *                     exactly one of the two (Postgres CHECK)
 *   quantity          base quantity (ingredient base unit, or pieces) > 0
 *   unit              the ingredient's base unit; NULL for product lines
 *   entered_unit /    how the merchant typed it (the P3-1 convention:
 *   entered_quantity  a unit of the ingredient's kind, '@piece', or a pack)
 *   sort_order        display order within the type's list
 *   timestamps, soft deletes
 *
 * A live item appears once per merchant and type (partial uniques).
 * pos:check-tenant-integrity checks that the item belongs to the merchant
 * and is not a prep item.
 */
return new class extends Migration
{
    private const TYPES_CHECK = 'pos_order_packaging_lines_order_type_check';

    private const REF_CHECK = 'pos_order_packaging_lines_ref_check';

    private const QTY_CHECK = 'pos_order_packaging_lines_quantity_check';

    public function up(): void
    {
        Schema::create('pos_order_packaging_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('order_type', 16);
            $table->foreignId('ingredient_id')->nullable()->constrained('pos_ingredients')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->string('unit', 16)->nullable();
            $table->string('entered_unit', 32)->nullable();
            $table->decimal('entered_quantity', 14, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'order_type', 'sort_order'], 'pos_order_packaging_lines_list_idx');
            $table->index(['ingredient_id'], 'pos_order_packaging_lines_ingredient_idx');
            $table->index(['product_id'], 'pos_order_packaging_lines_product_idx');
        });

        DB::statement('CREATE UNIQUE INDEX "pos_order_packaging_lines_ingredient_unique" ON "pos_order_packaging_lines" ("company_id", "order_type", "ingredient_id") WHERE "deleted_at" IS NULL AND "ingredient_id" IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX "pos_order_packaging_lines_product_unique" ON "pos_order_packaging_lines" ("company_id", "order_type", "product_id") WHERE "deleted_at" IS NULL AND "product_id" IS NOT NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_order_packaging_lines" ADD CONSTRAINT "'.self::TYPES_CHECK.'" CHECK ("order_type" IN (\'dine_in\', \'quick\', \'to_go\', \'delivery\'))');
            DB::statement('ALTER TABLE "pos_order_packaging_lines" ADD CONSTRAINT "'.self::REF_CHECK.'" CHECK (("ingredient_id" IS NULL) <> ("product_id" IS NULL))');
            DB::statement('ALTER TABLE "pos_order_packaging_lines" ADD CONSTRAINT "'.self::QTY_CHECK.'" CHECK ("quantity" > 0)');
        }
    }

    public function down(): void
    {
        // Fix order PK-A1 (M2) — dropping a merchant's live packaging list
        // would silently stop taking packaging: refuse while one exists
        // (soft-deleted lines are history the table can lose).
        if (Schema::hasTable('pos_order_packaging_lines')
            && DB::table('pos_order_packaging_lines')->whereNull('deleted_at')->exists()) {
            throw new RuntimeException('Cannot roll back 2026_10_06_110003: a merchant has a live order packaging line; delete the packaging lists first.');
        }

        Schema::dropIfExists('pos_order_packaging_lines');
    }
};
