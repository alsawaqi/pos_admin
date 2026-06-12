<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G4 — central ingredient warehouse ("buy 100 kg of sugar once, then split
 * 20/20/25 to branches; the remainder stays in the warehouse").
 *
 * Mirrors the PRODUCT central-pool design (pos_product_stock +
 * pos_product_stock_movements, 2026_06_25_0101/0200) for INGREDIENTS:
 *
 *   pos_ingredient_stock — one row per (company, ingredient): the central
 *   company balance held BEFORE distributing to branches. Lazy-created on the
 *   first central receive; kept at zero rather than deleted. Invariant:
 *   quantity == SUM(pos_stock_movements.quantity) for the same ingredient
 *   where branch_id IS NULL — the stock actions keep both writes atomic.
 *
 *   pos_stock_movements.branch_id goes NULLABLE — NULL = a central-pool row
 *   (received / allocation_out / central adjustment), set = a branch row,
 *   exactly the pos_product_stock_movements convention. Tenancy of a central
 *   row anchors on ingredient_id -> pos_ingredients.company_id (the join every
 *   aggregate already does); per-branch balances stay on pos_branch_stock.
 *
 * New movement types (application enums gate them): received (+central),
 * allocation_out (-central), allocation_in (+branch). Devices never read or
 * write central rows — every pos_api query filters branch_id = device branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_ingredient_stock', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            // Same unit semantics + precision as pos_branch_stock.quantity.
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'ingredient_id'], 'pos_ingredient_stock_company_ingredient_unique');
        });

        Schema::table('pos_stock_movements', function (Blueprint $table): void {
            // NULL = the central company pool; set = a specific branch.
            $table->unsignedBigInteger('branch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pos_stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable(false)->change();
        });

        Schema::dropIfExists('pos_ingredient_stock');
    }
};
