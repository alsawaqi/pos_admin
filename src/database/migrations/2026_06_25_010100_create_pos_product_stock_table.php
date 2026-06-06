<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — central company stock pool for UNIT (finished-good) products.
 *
 * One row per (company, product). `quantity` is the central balance the merchant
 * holds BEFORE distributing units out to branches: receiving finished goods
 * credits it, allocating to branches debits it. Per-branch unit balances stay on
 * pos_branch_product.stock_qty.
 *
 * Invariant: quantity == SUM(pos_product_stock_movements.quantity) for the same
 * (company, product) where branch_id IS NULL (the central side of the ledger) —
 * the stock actions keep both writes atomic in one DB transaction.
 *
 * Lazy creation: the row appears on the first receive for that product. No soft
 * delete — a zero balance keeps the row so the next receive needn't re-create it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_product_stock', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'product_id'], 'pos_product_stock_company_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_stock');
    }
};
