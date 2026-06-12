<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G1 — kitchen production batches (pos_productions + pos_production_lines).
 *
 * "Cooked" products (stock_mode = 'cooked', a new value on the existing
 * unconstrained pos_products.stock_mode varchar — no DDL needed there) are
 * made fresh in batches by the chef AHEAD of sale: the recipe is consumed
 * at PRODUCTION time, and the finished pieces sell down from the branch
 * shelf stock (pos_branch_product.stock_qty) exactly like 'unit' products.
 *
 * Two-phase batch, recorded here:
 *
 *   START   chef picks product + quantity on the device Kitchen screen.
 *           Recipe amounts are LOCKED (quantity x recipe); anything beyond
 *           the recipe must be DECLARED as explicit extra lines. Ingredients
 *           are deducted from pos_branch_stock immediately (they have
 *           physically left the shelf — a parallel batch cannot claim them);
 *           started_at is stamped. Ledger rows: 'production_consumption'.
 *
 *   FINISH  finished_at stamped, duration_seconds recorded, quantity lands
 *           in pos_branch_product.stock_qty (+ a 'produced' row in
 *           pos_product_stock_movements) so every till's tile un-greys.
 *
 *   CANCEL  manager-PIN gated; ingredients return to pos_branch_stock
 *           ('production_return' rows) and the batch is marked cancelled.
 *
 * Lines snapshot WHAT the batch actually consumed: is_extra=false rows are
 * the locked recipe x quantity; is_extra=true rows are the declared extras.
 * Keeping them separate gives the kitchen variance view (recipe says vs
 * kitchen used) and the batch-duration statistics the merchant sees on the
 * read-only Production history page.
 *
 * Production is ONLINE-ONLY (pos_api validates against fresh balances at
 * each phase); rows are written exclusively by pos_api, read by
 * pos_merchant. Quantities are decimal(12,3) to match the stock ledgers
 * they feed (whole pieces are an application-level rule, not a DDL one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_productions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            // The device the batch was started from (audit; survives device
            // deletion).
            $table->foreignId('device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->nullOnDelete();

            // Pieces in the batch (whole numbers enforced app-side).
            $table->decimal('quantity', 12, 3);

            // App constants: in_progress / finished / cancelled.
            $table->string('status', 16)->default('in_progress');

            // Who did what. PIN-asserted device staff; the cancel approver
            // is the manager whose PIN passed server-side verification.
            $table->foreignId('started_by_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            $table->foreignId('finished_by_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            $table->foreignId('cancelled_by_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            $table->foreignId('cancel_approved_by_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // finished_at - started_at, recorded (not derived) per spec —
            // the batch-duration statistic the merchant reports on.
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            // Hot paths: the merchant Production history page (branch +
            // date filtered) and the device Kitchen screen's active list.
            $table->index(['company_id', 'branch_id', 'started_at'], 'pos_productions_company_branch_idx');
            $table->index(['branch_id', 'status'], 'pos_productions_branch_status_idx');
        });

        Schema::create('pos_production_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_id')
                ->constrained('pos_productions')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();

            // Total consumed for the WHOLE batch (positive; the signed
            // ledger rows live in pos_stock_movements).
            $table->decimal('quantity', 12, 3);
            // Ingredient unit snapshot at production time (mirrors
            // pos_product_recipes.unit_at_set).
            $table->string('unit_at_time', 16);
            // false = locked recipe x quantity; true = declared extra.
            $table->boolean('is_extra')->default(false);

            $table->timestamps();

            $table->index(['production_id'], 'pos_production_lines_production_idx');
            // Kitchen variance view: "what did we actually use of X".
            $table->index(['ingredient_id'], 'pos_production_lines_ingredient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_production_lines');
        Schema::dropIfExists('pos_productions');
    }
};
