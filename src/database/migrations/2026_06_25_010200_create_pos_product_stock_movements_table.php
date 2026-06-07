<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — append-only ledger for UNIT (finished-good) PRODUCT stock, the
 * product-units parallel of pos_stock_movements (which is ingredient-only).
 *
 * Every change to a product's central pool OR a branch's unit count writes one
 * signed row. branch_id NULL = the central company pool; a branch_id = that
 * branch's count (pos_branch_product.stock_qty). Movement types:
 *   received         (+central)  — merchant receives finished goods centrally
 *   allocation_out   (-central)  — units leave the central pool toward a branch
 *   allocation_in    (+branch)   — units arrive at a branch from central
 *   transfer_out     (-branch)   — units leave a branch (branch -> branch)
 *   transfer_in      (+branch)   — units arrive at a branch (branch -> branch)
 *   sale_consumption (-branch)   — a POS sale (written by pos_api at order.pay)
 *   adjustment       (+/- either)— manual correction
 *   waste            (-either)   — spoilage / breakage
 *
 * Append-only: never updated/deleted; corrections are new adjustment rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_product_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            // NULL = the central company pool; set = a specific branch's count.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->string('movement_type', 32);
            // SIGNED — positive = inflow, negative = outflow.
            $table->decimal('quantity', 12, 3);
            // Polymorphic link to the trigger (Order / Transfer / Allocation) —
            // NULL for manual receives / adjustments.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->foreignId('recorded_by_pos_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'product_id', 'occurred_at'], 'pos_product_stock_mov_company_product_idx');
            $table->index(['branch_id', 'occurred_at'], 'pos_product_stock_mov_branch_idx');
            $table->index(['reference_type', 'reference_id'], 'pos_product_stock_mov_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_stock_movements');
    }
};
