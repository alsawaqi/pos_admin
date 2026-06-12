<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G2 — physical items (cups / lids / tissue / boxes).
 *
 * A paper cup is NOT an ingredient (ingredients are food only — the user
 * was emphatic). Physical items are countable goods the company buys, and
 * they live in the PRODUCT unit-stock world: purchased into the central
 * pool, Receive & Distributed to branches, transferred, counted — all
 * machinery that already exists and is reused unchanged.
 *
 * pos_products.is_internal: internal items NEVER appear on the POS menu
 * or the customer tablet (pos_api filters them out of /device/config) but
 * participate fully in stock. Plain flag — internal items are ordinary
 * unit-mode products otherwise.
 *
 * pos_product_components: the "Physical items" section of the product
 * form — per ONE unit sold of `product_id`, consume `quantity` of each
 * `component_product_id` from the branch's unit stock (coffee = 1 x cup
 * 12oz + 1 x lid). Components must be unit-mode products (app-enforced);
 * consumption happens in pos_api at order.pay alongside the recipe, and
 * reverses on void. ONE level only — components have no components.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->boolean('is_internal')->default(false)->after('show_on_customer_tablet');
        });

        Schema::create('pos_product_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            $table->foreignId('component_product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            // Per ONE unit sold of product_id. Decimal to match the unit
            // stock ledgers it feeds (whole pieces are an app-level rule).
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->unique(['product_id', 'component_product_id'], 'pos_product_components_pair_unique');
            // "Which products consume cup 12oz?" (deletion guards + reports).
            $table->index(['component_product_id'], 'pos_product_components_component_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_components');

        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('is_internal');
        });
    }
};
