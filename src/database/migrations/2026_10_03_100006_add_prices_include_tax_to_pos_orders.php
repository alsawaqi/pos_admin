<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P4 data contract — how an order's tax was priced (owner decision 1).
 *
 *   prices_include_tax  stamped from the order. true = the menu prices
 *                       already contained VAT, so grand_total already
 *                       contains tax_total (subtotal − discount − comp =
 *                       grand); false = tax was added on top, like every
 *                       order before LAUNCH-P4 (subtotal − discount − comp +
 *                       tax = grand). Reports read net sales as grand − tax
 *                       for true rows.
 *
 * Every existing order stays false (default): no value changes.
 * pos_api and pos_merchant mirror the column in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->boolean('prices_include_tax')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn('prices_include_tax');
        });
    }
};
