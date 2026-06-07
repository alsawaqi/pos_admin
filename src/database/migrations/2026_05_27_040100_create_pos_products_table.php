<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `pos_products` — the orderable items the merchant
 * sells. Phase 6b.
 *
 * Currency precision:
 *   Oman uses 3-decimal currency (baisas: 1 OMR = 1000
 *   baisas). decimal(12, 3) gives ~999,999,999.999 OMR
 *   range — plenty even for high-ticket items. Same shape
 *   for cost_price (which only management roles need to
 *   see; the cashier-facing payload omits it).
 *
 * Tax model (Phase 6 scope answer):
 *   tax_rate column is NULL by default → product inherits
 *   the company's default_tax_rate (added in a sibling
 *   migration). Non-null → override (used for zero-rated
 *   items like fresh bread or exempt categories). decimal(5,2)
 *   handles 0.00 to 999.99 — more than enough for 0% / 5% /
 *   15% scenarios. Stored as a percentage value, not a
 *   fraction (5.00 = 5%).
 *
 * SKU / barcode:
 *   Both nullable + unique-per-company-when-set. Tables that
 *   take handwritten orders (cafés, restaurants) leave them
 *   blank; retail / grocery scan barcodes on the POS device.
 *   Postgres partial-unique index on each handles the "many
 *   nulls allowed, distinct values when set" requirement.
 *
 * Soft delete: orders.line_items will reference product_id;
 * deleting a product must not break historical reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            // Nullable so a product can be created before its
            // category is decided (drafts), and so deleting a
            // category doesn't cascade-wipe products. The
            // Action layer refuses to delete a category that
            // has products to force the merchant to re-assign
            // first.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('pos_product_categories')
                ->nullOnDelete();

            // Optional identifiers. Unique-per-company partial
            // index added at the end of this method (Postgres
            // syntax; sqlite test mirror uses plain unique).
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();

            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();

            // Money columns — 3 decimals for OMR baisas.
            $table->decimal('base_price', 12, 3);
            // Cost is gated to admin-tier roles in the
            // resource; column is just NULLable for any
            // product the merchant hasn't tracked.
            $table->decimal('cost_price', 12, 3)->nullable();

            // Per-product tax override. NULL → inherit
            // company default. See migration comment above.
            $table->decimal('tax_rate', 5, 2)->nullable();

            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'status'], 'pos_products_category_status_idx');
            $table->index(['company_id', 'status'], 'pos_products_company_status_idx');
        });

        // Postgres partial unique — sku/barcode unique per
        // company ONLY when set + not soft-deleted. Lets
        // products live without SKUs and lets a re-created
        // product reuse a code the soft-deleted predecessor
        // held. SQLite test mirror uses a plain UNIQUE which
        // accepts multiple NULLs natively.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX pos_products_company_sku_unique
                 ON pos_products (company_id, sku)
                 WHERE sku IS NOT NULL AND deleted_at IS NULL'
            );
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX pos_products_company_barcode_unique
                 ON pos_products (company_id, barcode)
                 WHERE barcode IS NOT NULL AND deleted_at IS NULL'
            );
        } else {
            Schema::table('pos_products', function (Blueprint $table): void {
                $table->unique(['company_id', 'sku'], 'pos_products_company_sku_unique');
                $table->unique(['company_id', 'barcode'], 'pos_products_company_barcode_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_products');
    }
};
