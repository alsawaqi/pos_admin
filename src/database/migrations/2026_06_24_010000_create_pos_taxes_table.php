<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level taxes (blueprint §6 / merchant-configurable VAT + other charges).
 *
 * Each company defines its own taxes -- a free-form name (e.g. "VAT",
 * "Municipality") plus a percentage rate -- applied to EVERY branch's sales.
 * The Main POS fetches the active set in its /device/config bundle at staff
 * login and adds each one, as its own line, on top of the order total
 * (exclusive). This supersedes the single pos_companies.default_tax_rate with a
 * proper multi-tax list. Soft-deleted so historical orders stay resolvable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_taxes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            // Percentage, e.g. 5.00 = 5%. Added on top of the subtotal (exclusive).
            $table->decimal('rate_percent', 5, 2);
            // When inactive the tax is hidden from the POS / not applied, but
            // stays referenceable for historical orders.
            $table->boolean('is_active')->default(true);
            // Lets the merchant order taxes on the POS receipt.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            // No two taxes in the same company can share a name.
            $table->unique(['company_id', 'name'], 'pos_taxes_company_name_unique');
            // List query: "active taxes in display order for company X".
            $table->index(
                ['company_id', 'is_active', 'sort_order'],
                'pos_taxes_company_active_sort_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_taxes');
    }
};
