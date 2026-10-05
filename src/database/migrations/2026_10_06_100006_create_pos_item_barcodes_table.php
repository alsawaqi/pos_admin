<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH review add-on §3.1 / 06 — barcodes (owner decision D8, tester
 * call 12). Several per container, per physical item and per pack.
 *
 *   barcode        a trimmed string, leading zeros kept
 *   ingredient_id  an ingredient, optionally one of its containers
 *   product_id     a physical item, optionally one of its packs
 *   label          an optional brand ("Al Safi")
 *
 * One live code per company: partial unique (company_id, barcode) WHERE
 * deleted_at IS NULL. The rule against pos_products.barcode is an app
 * check in both directions. On Postgres a CHECK keeps exactly one of
 * ingredient_id / product_id, container_id only with ingredient_id and
 * pack_id only with product_id. A barcode goes with its item, container or
 * pack. pos:check-tenant-integrity checks the item's company.
 */
return new class extends Migration
{
    private const LIVE = 'pos_item_barcodes_company_barcode_unique';

    public function up(): void
    {
        Schema::create('pos_item_barcodes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('barcode', 64);
            $table->foreignId('ingredient_id')->nullable()->constrained('pos_ingredients')->cascadeOnDelete();
            $table->foreignId('container_id')->nullable()->constrained('pos_ingredient_units')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('pack_id')->nullable()->constrained('pos_product_packs')->cascadeOnDelete();
            $table->string('label', 80)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['ingredient_id'], 'pos_item_barcodes_ingredient_idx');
            $table->index(['product_id'], 'pos_item_barcodes_product_idx');
        });

        DB::statement('CREATE UNIQUE INDEX "'.self::LIVE.'" ON "pos_item_barcodes" ("company_id", "barcode") WHERE "deleted_at" IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_item_barcodes" ADD CONSTRAINT "pos_item_barcodes_item_check" CHECK ((("ingredient_id" IS NULL) <> ("product_id" IS NULL)) AND ("container_id" IS NULL OR "ingredient_id" IS NOT NULL) AND ("pack_id" IS NULL OR "product_id" IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_item_barcodes');
    }
};
