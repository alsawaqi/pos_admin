<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product-unique add-ons (v2 #6).
 *
 * A nullable owner_product_id turns an add-on GROUP into one PRIVATELY OWNED
 * by a single product — options defined right on that product, never shown as
 * a shared group and never attachable elsewhere. NULL = the existing shared
 * behaviour (global, or attached via the pos_addon_group_products pivot).
 *
 * A product may own SEVERAL private groups (e.g. "Milk", "Syrup"), so this is
 * deliberately NOT unique. An owned group is still attached to its product via
 * the existing pivot, so the device config + render need no change. Backward
 * compatible: every existing group keeps owner_product_id = NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_addon_groups', function (Blueprint $table): void {
            $table->foreignId('owner_product_id')
                ->nullable()
                ->after('company_id')
                ->constrained('pos_products')
                ->cascadeOnDelete();
            $table->index(['company_id', 'owner_product_id'], 'pos_addon_groups_company_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_addon_groups', function (Blueprint $table): void {
            $table->dropForeign(['owner_product_id']);
            $table->dropIndex('pos_addon_groups_company_owner_idx');
            $table->dropColumn('owner_product_id');
        });
    }
};
