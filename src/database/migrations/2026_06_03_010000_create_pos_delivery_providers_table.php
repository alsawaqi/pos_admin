<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6c — per-merchant delivery providers (blueprint §6.3).
 *
 * The 3rd-party aggregators a merchant works with — Talabat,
 * Otlob, Hungerstation, Toyou, or a custom name for in-house
 * delivery contracts. Each merchant maintains their own list;
 * cross-merchant overlap on "Talabat" is fine — these are
 * just labels.
 *
 * Phase 8+ POS sale pipeline: when the cashier picks
 * "Delivery" as the order type, they then pick which provider.
 * That picks the price set used for the order's line items
 * (see pos_product_delivery_prices).
 *
 * Soft delete: orders (Phase 7+) will reference provider_id;
 * the audit trail breaks if a deleted provider can't be
 * resolved. Soft-delete hides the provider from the POS picker
 * but historical order lines stay readable.
 *
 * Color: optional 7-char hex (#RRGGBB) so the POS UI can
 * surface a coloured chip / pill per provider — visually
 * distinguishing Talabat (red) from Otlob (orange) etc. on
 * the order screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_delivery_providers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('name', 64);
            // 7-char hex color (#RRGGBB) — optional UI hint.
            $table->string('color', 7)->nullable();
            // When inactive the provider is hidden from the
            // POS provider picker but its historical price
            // rows stay resolvable.
            $table->boolean('is_active')->default(true);
            // Used by the merchant to put Talabat above Otlob
            // (or vice-versa) in the POS picker.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            // No two providers in the same merchant's book can
            // share a name — keeps the POS picker
            // unambiguous.
            $table->unique(['company_id', 'name'], 'pos_delivery_providers_company_name_unique');
            // List query: "active providers in display order
            // for company X".
            $table->index(
                ['company_id', 'is_active', 'sort_order'],
                'pos_delivery_providers_company_active_sort_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_delivery_providers');
    }
};
