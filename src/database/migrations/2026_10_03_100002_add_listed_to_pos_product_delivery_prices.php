<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P4 data contract — the per-provider channel on delivery prices.
 *
 *   listed  false hides the product on that delivery provider. Existing rows
 *           stay listed (default true).
 *   price   becomes nullable: NULL means "the product's delivery price, else
 *           its base price". Existing prices are kept as they are.
 *
 * On Postgres the column only drops its NOT NULL (no rewrite, no value
 * change). Rolling back fills a NULL price with the price it stood for
 * (delivery price, else base price) before NOT NULL comes back.
 * pos_api and pos_merchant mirror the columns in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_product_delivery_prices', function (Blueprint $table): void {
            $table->boolean('listed')->default(true);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_product_delivery_prices" ALTER COLUMN "price" DROP NOT NULL');
        } else {
            Schema::table('pos_product_delivery_prices', function (Blueprint $table): void {
                $table->decimal('price', 12, 3)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('pos_product_delivery_prices')->whereNull('price')->update([
            'price' => DB::raw('(SELECT COALESCE(p.delivery_price, p.base_price) FROM pos_products p WHERE p.id = pos_product_delivery_prices.product_id)'),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_product_delivery_prices" ALTER COLUMN "price" SET NOT NULL');
        } else {
            Schema::table('pos_product_delivery_prices', function (Blueprint $table): void {
                $table->decimal('price', 12, 3)->nullable(false)->change();
            });
        }

        Schema::table('pos_product_delivery_prices', function (Blueprint $table): void {
            $table->dropColumn('listed');
        });
    }
};
