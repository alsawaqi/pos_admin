<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch product availability + unit stock.
 *
 * Links a product to the branches that SELL it (is_available) and carries an
 * optional per-branch product-unit stock (stock_qty; NULL = not unit-tracked
 * at this branch -- availability only / recipe-depleted). A product with NO
 * rows here is available at EVERY branch (backward compatible). The device
 * config bundle filters products by this table so a branch's terminal only
 * fetches the products assigned to that branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_branch_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->decimal('stock_qty', 12, 3)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_branch_product');
    }
};
