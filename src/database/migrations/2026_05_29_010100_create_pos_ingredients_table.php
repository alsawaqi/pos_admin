<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5a — Ingredients master list (blueprint §5.6.1 + §10.5).
 *
 * Per-merchant company-level master. Ingredients live at the
 * company level; their STOCK BALANCES are per-branch (see
 * pos_branch_stock). This split is the blueprint's choice and
 * makes it possible to:
 *   - share a single "Whole Milk" record across every branch
 *   - track per-branch consumption rates for the smart-restock
 *     allocator in Phase 5c
 *   - compute company-wide COGS aggregated from per-branch
 *     stock movements
 *
 * Columns:
 *   unit                  — kg / g / l / ml / piece / pack / box.
 *                            Application enum {@see App\Enums\IngredientUnit}
 *                            restricts values. Stored as plain
 *                            string so adding a new unit doesn't
 *                            need a migration.
 *   default_unit_cost     — decimal(12,3) OMR baisas precision.
 *                            Used as the unit cost on stock
 *                            movements when the merchant didn't
 *                            override it during a restock entry.
 *                            Phase 5b recipe-cost calc reads
 *                            this when computing theoretical cost.
 *   min_stock_threshold   — quantity below which the Branch Stock
 *                            page flags this ingredient as Low.
 *                            Same unit semantics as 'unit'. Phase
 *                            5c smart-allocator suggests restock
 *                            quantities to bring branches up to
 *                            (some multiple of) this threshold.
 *   primary_supplier_id   — nullable FK into pos_suppliers. If
 *                            set, autofills the supplier on a
 *                            restock entry.
 *
 * Soft delete: stock_movements references ingredient_id; never
 * hard-delete or historical reports break.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            // Free-form 16-char string at the DB level so the
            // enum can grow without a migration. Application
            // enum is the gatekeeper.
            $table->string('unit', 16);
            $table->decimal('default_unit_cost', 12, 3)->default(0);
            // Same unit as the 'unit' column above. NULL means
            // "no threshold set, never flag as low" — useful for
            // ingredients merchants don't actively track.
            $table->decimal('min_stock_threshold', 12, 3)->nullable();
            $table->foreignId('primary_supplier_id')
                ->nullable()
                ->constrained('pos_suppliers')
                ->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            // Two ingredients in the same company can't share a
            // name — keeps recipe/lookup UX unambiguous.
            $table->unique(['company_id', 'name'], 'pos_ingredients_company_name_unique');
            $table->index(['company_id', 'status'], 'pos_ingredients_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_ingredients');
    }
};
