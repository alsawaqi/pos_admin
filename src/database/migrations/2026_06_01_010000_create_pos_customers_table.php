<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6a — Customers master list (blueprint §6.1).
 *
 * Per-merchant company-level customer book. Same tenancy
 * model as ingredients + suppliers: customers belong to ONE
 * company (no cross-company sharing). A given person who
 * patronises two merchants would appear as TWO customer rows
 * — by design, since the two merchants don't share data.
 *
 * Minimum scope for Phase 6a: name + phone only. No email,
 * no notes, no Arabic name yet — keeps the surface small
 * for the pilot. Later phases can additive-extend.
 *
 * Phone is the natural lookup key at the POS counter:
 *   - the cashier types a phone number; we find or create
 *     the customer in one step
 *   - the (company_id, phone) unique constraint guarantees
 *     this lookup returns at most one row
 *
 * Vehicle plates live in a sibling 1:N table — one customer
 * can have many plates (a family with two cars). See the
 * companion migration pos_customer_vehicle_plates.
 *
 * Soft delete: future order rows (Phase 7+) will reference
 * customer_id, so we never hard-delete. Deleting just hides
 * the row from default queries; historical sales reports
 * still resolve withTrashed().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('name');
            // 32 chars is enough for any international number with
            // formatting (e.g. '+968 9123 4567'). The Action layer
            // is responsible for normalising before write.
            $table->string('phone', 32);
            $table->timestamps();
            $table->softDeletes();
            // Phone is the natural identifier within a tenant —
            // two customers in the same company can't share a
            // phone. Cross-company collisions are fine (different
            // merchants have separate books).
            $table->unique(['company_id', 'phone'], 'pos_customers_company_phone_unique');
            // Search-by-name hot path (the Customers UI typeahead).
            $table->index(['company_id', 'name'], 'pos_customers_company_name_idx');
            // "Recently added" list ordering.
            $table->index(['company_id', 'created_at'], 'pos_customers_company_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customers');
    }
};
