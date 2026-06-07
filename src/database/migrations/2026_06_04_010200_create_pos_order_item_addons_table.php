<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7a — Per-line add-on selections (blueprint §10.8).
 *
 * "Latte with extra shot + oat milk" → one pos_order_items
 * row (latte) + two pos_order_item_addons rows (extra shot,
 * oat milk).
 *
 * SNAPSHOT COLUMNS (same rationale as pos_order_items):
 *
 *   add_on_name_snapshot:
 *     Display name at write time. Receipts + reports show this
 *     even after the merchant renames or hides the add-on.
 *
 *   price_delta_snapshot:
 *     The price modifier this add-on contributed at write time.
 *     SIGNED — most add-ons are positive ("extra shot +0.500")
 *     but a "no milk" modifier could be negative if the merchant
 *     configured it that way.
 *
 *   ingredient_snapshot_json:
 *     The ingredient(s) the add-on consumed at write time
 *     (per add_ons.ingredient_id from Phase 4.9). Drives the
 *     Phase 8 add-on stock-deduction pipeline. NULL if the
 *     add-on doesn't tie to an ingredient (e.g. a sauce packet
 *     that isn't tracked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_item_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')
                ->constrained('pos_order_items')
                ->cascadeOnDelete();
            // Add-on nullable on hard-delete — historical line
            // survives.
            $table->foreignId('add_on_id')
                ->nullable()
                ->constrained('pos_addons')
                ->nullOnDelete();

            $table->string('add_on_name_snapshot');
            // SIGNED — negative add-ons are legitimate.
            $table->decimal('price_delta_snapshot', 12, 3);

            // JSON map { ingredient_id, qty, unit } for stock
            // deduction. NULL means "no ingredient tracking".
            $table->json('ingredient_snapshot_json')->nullable();

            $table->timestamps();

            $table->index(['order_item_id'], 'pos_order_item_addons_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_item_addons');
    }
};
