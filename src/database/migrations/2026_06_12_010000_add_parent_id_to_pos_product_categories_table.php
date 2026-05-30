<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subcategories — a self-referential parent on product categories (§5.5.1).
 *
 * NULL parent_id = a top-level category; a set parent_id = a subcategory of
 * that category. The menu hierarchy is capped at TWO levels (a category whose
 * parent_id is set cannot itself be a parent) — enforced in the application
 * layer (Create/UpdateCategoryAction), alongside the cross-tenant + self-parent
 * + no-orphan guards.
 *
 * parent_id is a plain foreign-id column WITHOUT a DB-level self-referential
 * FK: SQLite (the test path) can't add a foreign key to an existing table via
 * ALTER, and pos_api's mirror already establishes app-layer-enforced foreign
 * ids. Integrity is owned by the action layer; deletes are guarded so a parent
 * with subcategories can't be removed out from under its children.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_product_categories', function (Blueprint $table): void {
            // No ->after(): it's a MySQL-only modifier; prod is Postgres and
            // the test path is sqlite, where column order is cosmetic anyway.
            $table->foreignId('parent_id')->nullable();
            $table->index(['company_id', 'parent_id'], 'pos_product_categories_company_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_product_categories', function (Blueprint $table): void {
            $table->dropIndex('pos_product_categories_company_parent_idx');
            $table->dropColumn('parent_id');
        });
    }
};
