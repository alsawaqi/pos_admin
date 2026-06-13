<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PD3a — physical items become a first-class Inventory concept in the
 * merchant portal (created there, never in the catalogue). Under the
 * hood they stay internal unit products; this column records WHICH KIND
 * of physical item a row is:
 *
 *   'packaging' — used with food (cups, lids, boxes, wrappers): offered
 *                 by the product-composition picker, counted per sale.
 *   'general'   — branch use (light bulbs, cleaning items, devices):
 *                 pure branch inventory, never attachable to food.
 *   NULL        — non-internal products, and legacy internal items
 *                 created before PD3a (treated as 'packaging' by the
 *                 picker until edited).
 *
 * The device never sees this column (internal items are excluded from
 * /device/config), so no pos_api/device schema version is involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->string('internal_purpose', 16)->nullable()->after('is_internal');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('internal_purpose');
        });
    }
};
