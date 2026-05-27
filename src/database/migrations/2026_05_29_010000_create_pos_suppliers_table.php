<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5a — Suppliers master list (blueprint §10.5).
 *
 * Lightweight per-merchant supplier directory. Ingredients
 * point at a primary supplier; restock rows in Phase 5c can
 * optionally tag which supplier the inflow came from. Phase
 * 5a doesn't enforce any business workflow beyond CRUD —
 * tracking supplier performance lands in a future report.
 *
 * Soft delete preserves historical references from
 * pos_ingredients.primary_supplier_id when a supplier is
 * retired but old stock movements still mention them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            // Two suppliers in the same company can't share a
            // name — keeps the picker unambiguous.
            $table->unique(['company_id', 'name'], 'pos_suppliers_company_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_suppliers');
    }
};
