<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P4 data contract — the products a combo slot offers.
 *
 *   slot_id      the slot; its options go with it
 *   product_id   the item offered: a STANDARD product of the slot's company
 *                (app-enforced, checked by pos:check-tenant-integrity). A
 *                product named by an option cannot be hard-deleted (restrict).
 *   extra_price  what choosing it adds to the combo price (0 or more), the
 *                same on every channel
 *   is_default   pre-selected in the combo builder
 *
 * One row per product in a slot. On Postgres a CHECK backs extra_price >= 0;
 * SQLite (the test mirror) enforces nothing.
 * pos_api and pos_merchant mirror the table in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_combo_slot_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained('pos_combo_slots')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->restrictOnDelete();
            $table->decimal('extra_price', 12, 3)->default(0);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['slot_id', 'product_id'], 'pos_combo_slot_options_slot_product_unique');
            // "Which combos offer this product?" (delete guards, reports).
            $table->index(['product_id'], 'pos_combo_slot_options_product_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_combo_slot_options" ADD CONSTRAINT "pos_combo_slot_options_extra_price_check" CHECK ("extra_price" >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_combo_slot_options');
    }
};
