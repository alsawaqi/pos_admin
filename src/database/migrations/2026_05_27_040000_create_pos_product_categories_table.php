<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `pos_product_categories` — the flat menu grouping
 * that products belong to (Drinks / Mains / Desserts /
 * Sides). Phase 6a.
 *
 * Why pos_product_categories and not pos_categories:
 *   Defensively prefixed to leave headspace for future
 *   non-product category tables (customer categories, expense
 *   categories, etc.) without name collisions. The shared
 *   charity_db already taught us — see the
 *   2026_05_18_000000_drop_duplicate_pos_admin_infrastructure_tables
 *   migration where short generic names had to be moved aside.
 *
 * Phase 6a ships FLAT categories — no sub-category nesting.
 * If a merchant ever needs Drinks → Hot / Cold, we add a
 * nullable parent_id in a follow-up migration; today's
 * pos_products.category_id stays a leaf reference.
 *
 * Soft delete + cascade-on-company-delete: tearing down a
 * company wipes its catalog; archiving a category preserves
 * historical order_line.product_id → product.category_id
 * joins via withTrashed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_product_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();

            // Free-form URL. Phase 6 scope: merchant pastes a
            // link. Built-in upload UX is deferred to Phase 6e.
            $table->string('image_url', 500)->nullable();

            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');

            $table->timestamps();
            $table->softDeletes();

            // Two categories at the same company can't share a
            // name — the product picker would be ambiguous.
            // Different companies can each have a "Drinks".
            $table->unique(['company_id', 'name'], 'pos_product_categories_company_name_unique');
            $table->index(['company_id', 'status'], 'pos_product_categories_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_categories');
    }
};
