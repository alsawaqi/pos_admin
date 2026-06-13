<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PD3b — per-option consumption.
 *
 * An add-on OPTION can now carry its own stock-usage lines: picking
 * "Large" on a coffee consumes a large cup instead of a medium one and
 * more beans; "Extra patty" consumes another cooked patty; "Remove
 * salad" hands the salad back. Each line is either an INGREDIENT line
 * (ingredient_id + unit) or a PRODUCT line (component_product_id —
 * packaging physical items, cooked prepared components, or bought-in
 * unit products) — XOR app-enforced, like pos_addons' own
 * ingredient/linked_product pair.
 *
 * direction 'add' consumes on top of the parent product's recipe and
 * components; 'remove' subtracts from them — the pay-time engine merges
 * per ingredient/product and clamps the EFFECTIVE consumption at zero
 * (a removal never restocks). Quantities are per ONE parent line unit,
 * matching the existing addon-snapshot semantics.
 *
 * pos_order_item_addons.consumption_snapshot_json freezes the lines at
 * order create (server-built — devices keep sending only add_on_id),
 * so later edits to the definition never rewrite history. When lines
 * exist they SUPERSEDE the legacy single-ingredient trio snapshot for
 * that addon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_addon_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('add_on_id')->constrained('pos_addons')->cascadeOnDelete();
            // XOR: exactly one of the two refs per line (app-enforced).
            $table->foreignId('ingredient_id')->nullable()->constrained('pos_ingredients')->cascadeOnDelete();
            $table->foreignId('component_product_id')->nullable()->constrained('pos_products')->cascadeOnDelete();
            $table->string('direction', 8)->default('add');
            $table->decimal('quantity', 12, 3);
            // Ingredient lines only; product lines are whole pieces.
            $table->string('unit', 16)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            // One add + one remove per ref per option at most (nullable
            // columns keep the two uniques from colliding cross-kind).
            $table->unique(['add_on_id', 'ingredient_id', 'direction'], 'pos_addon_consumptions_ing_dir_unique');
            $table->unique(['add_on_id', 'component_product_id', 'direction'], 'pos_addon_consumptions_prod_dir_unique');
        });

        Schema::table('pos_order_item_addons', function (Blueprint $table): void {
            $table->json('consumption_snapshot_json')->nullable()->after('product_snapshot_json');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_item_addons', function (Blueprint $table): void {
            $table->dropColumn('consumption_snapshot_json');
        });

        Schema::dropIfExists('pos_addon_consumptions');
    }
};
