<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5b — Append-only recipe-edit history (blueprint §9.9).
 *
 * Every time a product's recipe is edited (via
 * UpdateProductRecipeAction), the PRE-EDIT shape gets
 * snapshotted here so historical COGS reports never silently
 * shift when the current recipe changes.
 *
 * recipe_json is a JSON array of
 *   [{ingredient_id, ingredient_name, quantity, unit, unit_cost_at_time}, ...]
 * — denormalised so the version is meaningful even if the
 * ingredient is later deleted (soft delete).
 *
 * Append-only at the application layer; no UPDATE/DELETE
 * surface. Reading: ordered by edited_at DESC. The active
 * recipe is the one CURRENTLY in pos_product_recipes — the
 * versions table only holds historical (pre-edit) snapshots.
 *
 * note: optional free text the merchant can attach when
 * making a significant recipe change (e.g. "switched to
 * organic milk supplier").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_product_recipe_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            // recipe_json — the pre-edit snapshot. text not json
            // so the column works on every supported DB driver
            // including sqlite test mirror. Application layer
            // encodes/decodes.
            $table->text('recipe_json');
            $table->foreignId('edited_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('edited_at')->useCurrent();
            $table->index(['product_id', 'edited_at'], 'pos_product_recipe_versions_product_edited_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_recipe_versions');
    }
};
