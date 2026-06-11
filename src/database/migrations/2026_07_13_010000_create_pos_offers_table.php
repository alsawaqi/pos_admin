<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-F9 — merchant offers / promotions (pos_offers).
 *
 * Merchant-defined OFFER rules the POS device evaluates by itself (the
 * device-side engine is pure; the server stores/edits/emits). Each row is
 * a `type` + type-specific `config` JSON — the proven pos_loyalty_rules
 * pattern. Five types:
 *
 *   bogo          buy-X-get-Y (free or % off), product/category selectors
 *   bundle        fixed-price meal deal of N groups (ALWAYS cashier-picked:
 *                 auto_apply is forced FALSE merchant-side for this type)
 *   multi_buy     N of a selector for a fixed price (e.g. 3 for 1 OMR)
 *   cheapest_free buy N, the cheapest M of them free
 *   spend_get     order subtotal ≥ X → % off / fixed off / free product
 *
 * The shared applicability axes MIRROR pos_discounts exactly (see the
 * 2026_06_05_010000 migration): validity window (NULL = no bound),
 * dayofweek_mask bitmask Sun=1..Sat=64 (NULL = every day), HH:MM:SS
 * time-of-day window with midnight wrap (NULL = all day), branch_scope_json
 * (NULL/[] = all branches), status active|paused.
 *
 * Money INSIDE config is integer BAISAS (price_baisas /
 * min_subtotal_baisas / fixed-off reward_value) — the device does no
 * float math; this is the device-config wire convention.
 *
 * max_per_order: how many times one order may apply this offer
 * (NULL = unlimited).
 *
 * Soft delete: applications are snapshotted on pos_order_discounts
 * (name_snapshot), and soft-deleted ids surface in the device config
 * delta `deleted.offers` purge list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();
            // App enum {@see OfferType}: bogo / bundle / multi_buy /
            // cheapest_free / spend_get.
            $table->string('type', 24);
            // Type-specific shape, validated strictly per type by the
            // merchant requests; emitted to the device verbatim.
            $table->json('config');

            // true = the device applies the offer by itself to every
            // qualifying order; false = the cashier picks it. Bundle is
            // ALWAYS cashier-picked (write actions force false).
            $table->boolean('auto_apply')->default(true);

            // Validity window. NULL = "no bound" on that end.
            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();

            // Day-of-week bitmask (Sun=1..Sat=64). NULL = every day.
            $table->smallInteger('dayofweek_mask')->nullable();

            // Time-of-day window, HH:MM:SS. NULL = all day; start > end
            // wraps midnight (the pos_discounts evaluator convention).
            $table->string('time_start', 8)->nullable();
            $table->string('time_end', 8)->nullable();

            // NULL = all branches; array of int branch_ids = subset.
            $table->json('branch_scope_json')->nullable();

            // Max applications of this offer on one order. NULL = unlimited.
            $table->smallInteger('max_per_order')->nullable();

            // App enum {@see OfferStatus}: active / paused.
            $table->string('status', 16)->default('active');

            $table->timestamps();
            $table->softDeletes();

            // Hot path: "all offers for company X" — the device config
            // bundle + the merchant Offers page.
            $table->index(['company_id', 'status'], 'pos_offers_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_offers');
    }
};
