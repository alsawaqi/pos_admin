<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH review add-on §3.1 / 05 — containers for physical items (owner
 * decision D3): "box holds 50 cups", nested cartons.
 *
 *   product_id         the physical item (pos_products, is_internal)
 *   name / name_ar     "box" / "علبة"
 *   pieces             pieces in ONE pack, nesting included (a carton of
 *                      10 boxes of 50 = 500); a whole number >= 2
 *   contains_pack_id   the pack it holds, with contains_quantity of it
 *
 * Partial unique on the live size: (product_id, lower(name), pieces,
 * COALESCE(contains_pack_id, 0)) WHERE deleted_at IS NULL, so the same
 * word may be used with different sizes.
 *
 * product_id and contains_pack_id refuse the delete of a referenced row
 * (NO ACTION, checked at the end of the statement, so a cascade from the
 * company still removes everything in one statement). On Postgres CHECKs
 * keep pieces a whole number >= 2 and the contains pair whole. The pack
 * belongs to its product's company (pos:check-tenant-integrity).
 */
return new class extends Migration
{
    private const LIVE = 'pos_product_packs_live_size_unique';

    public function up(): void
    {
        Schema::create('pos_product_packs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products');
            $table->string('name', 32);
            $table->string('name_ar', 32)->nullable();
            $table->decimal('pieces', 14, 4);
            $table->foreignId('contains_pack_id')->nullable()->constrained('pos_product_packs');
            $table->decimal('contains_quantity', 14, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'product_id'], 'pos_product_packs_company_product_idx');
        });

        DB::statement('CREATE UNIQUE INDEX "'.self::LIVE.'" ON "pos_product_packs" ("product_id", lower("name"), "pieces", COALESCE("contains_pack_id", 0)) WHERE "deleted_at" IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_product_packs" ADD CONSTRAINT "pos_product_packs_pieces_check" CHECK ("pieces" >= 2 AND "pieces" = trunc("pieces"))');
            DB::statement('ALTER TABLE "pos_product_packs" ADD CONSTRAINT "pos_product_packs_contains_check" CHECK (("contains_pack_id" IS NULL AND "contains_quantity" IS NULL) OR ("contains_pack_id" IS NOT NULL AND "contains_quantity" IS NOT NULL AND "contains_quantity" > 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_packs');
    }
};
