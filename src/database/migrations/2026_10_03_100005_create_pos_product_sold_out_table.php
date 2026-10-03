<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P4 data contract — the manual "sold out" switch (owner decision 4).
 *
 * A row present means the product is sold out at that branch, on every
 * channel (till, handheld, QR), until the row is deleted. It is never written
 * from stock numbers. Who set it: a portal user (set_by_user_id) or a device
 * staff member (set_by_pos_staff_id); either reference empties if that row is
 * ever hard-deleted, and the switch stays.
 *
 * One row per (branch, product). The row belongs to the branch's and the
 * product's company (pos:check-tenant-integrity checks both).
 * pos_api and pos_merchant mirror the table in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_product_sold_out', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('set_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->foreignId('set_by_pos_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->timestamp('set_at');
            $table->timestamps();
            $table->unique(['branch_id', 'product_id'], 'pos_product_sold_out_branch_product_unique');
            $table->index(['product_id'], 'pos_product_sold_out_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_sold_out');
    }
};
