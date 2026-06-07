<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4.9 — Modifiers / Add-ons (blueprint §5.5.4 + §10.4).
 *
 * Three tables:
 *
 *   pos_addon_groups       — a named bundle of related add-ons
 *                            ("Milk Choice", "Sugar Level", "Extras").
 *                            selection_mode controls whether the
 *                            customer picks exactly one (radio) or
 *                            many (checkbox). is_global=true means
 *                            the group applies to every product of
 *                            the company; otherwise the pivot below
 *                            restricts it to specific products.
 *
 *   pos_addons             — individual add-on options under a group
 *                            ("Whole milk", "Oat milk", "Extra shot").
 *                            price_delta can be 0 (free) or positive.
 *                            ingredient_id / ingredient_qty are
 *                            placeholders for Phase 5 — once Ingredients
 *                            land, an "Extra shot" addon will deduct
 *                            from espresso-bean stock. ingredient_id
 *                            stays NULL until then (NO foreign-key
 *                            constraint yet — Phase 5 adds it once
 *                            pos_ingredients exists).
 *
 *   pos_addon_group_products — pivot for non-global groups attached
 *                              to specific products. Global groups
 *                              skip this table entirely (the
 *                              resource resolver UNIONs is_global=true
 *                              groups with pivot-joined groups).
 *
 * Delete semantics:
 *   - Soft delete on groups + addons so historical orders that
 *     reference an add-on by id still resolve via withTrashed().
 *   - Group delete cascades hard to its addons + pivot rows
 *     (handled by FK on delete cascade — we never want orphaned
 *     addons floating without their parent group).
 *   - DeleteAddOnGroupAction refuses if the group is currently
 *     attached to any product (or is_global=true and any product
 *     would use it). Forces the merchant to detach first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_addon_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            // single | multi. Application enum
            // {@see \App\Enums\AddOnSelectionMode} restricts inputs.
            $table->string('selection_mode', 16)->default('single');
            // is_global=true: applies to every product in the
            // company catalog. Useful for "Service Type" or
            // "Sugar Level" universal options.
            $table->boolean('is_global')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            // Unique (company_id, name) — same pattern as categories.
            $table->unique(['company_id', 'name'], 'pos_addon_groups_company_name_unique');
            $table->index(['company_id', 'is_global', 'status'], 'pos_addon_groups_company_global_status_idx');
        });

        Schema::create('pos_addons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // company_id is denormalised onto the addon too even
            // though the group already carries it. Defence-in-depth
            // for the global tenant scope + lets us index addons
            // by tenant directly without a join when, e.g., the
            // POS sync endpoint needs ALL addons for a branch.
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('add_on_group_id')
                ->constrained('pos_addon_groups')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            // Decimal(12,3) so add-ons can be free (0.000), cheap
            // (0.250), or premium (5.000). Same shape as base_price
            // so the POS-side math stays in OMR baisa precision.
            // Stored as a delta (added to the base price), not as
            // an absolute price — that's how the blueprint frames it.
            $table->decimal('price_delta', 12, 3)->default(0);
            // PHASE 5 placeholders. No FK constraint yet because
            // pos_ingredients doesn't exist — Phase 5 will add an
            // ALTER TABLE to wire the FK once it does.
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('ingredient_qty', 10, 3)->nullable();
            $table->string('ingredient_unit', 16)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['add_on_group_id', 'status'], 'pos_addons_group_status_idx');
            $table->index(['company_id', 'status'], 'pos_addons_company_status_idx');
        });

        Schema::create('pos_addon_group_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('add_on_group_id')
                ->constrained('pos_addon_groups')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            // A group attaches to a product at most once.
            $table->unique(['add_on_group_id', 'product_id'], 'pos_addon_group_products_unique');
            $table->index(['product_id'], 'pos_addon_group_products_product_idx');
        });
    }

    public function down(): void
    {
        // Reverse FK order: pivot → addons → groups. (Pivot
        // references both, addons reference groups.)
        Schema::dropIfExists('pos_addon_group_products');
        Schema::dropIfExists('pos_addons');
        Schema::dropIfExists('pos_addon_groups');
    }
};
