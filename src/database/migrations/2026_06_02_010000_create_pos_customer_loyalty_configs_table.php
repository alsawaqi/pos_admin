<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b — per-company loyalty config (blueprint §6.2).
 *
 * Singleton per merchant — at most one row per company. The
 * unique constraint on company_id enforces that.
 *
 * Two integer rates drive everything:
 *
 *   points_per_omr
 *     How many points the customer earns per OMR spent on a
 *     completed sale. e.g. 1 = "1 OMR = 1 point", 10 = "1 OMR
 *     = 10 points". Pilot merchants reason in whole numbers,
 *     so integer is fine.
 *
 *   baisas_per_point
 *     What 1 point is worth when redeemed, in baisas
 *     (1/1000 OMR). e.g. 10 baisas/point = "100 points = 1.000
 *     OMR off"; 5 baisas/point = "200 points = 1.000 OMR off".
 *     Storing the redemption value as an integer of baisas
 *     keeps the math exact (no float ⇒ no rounding drift).
 *
 * is_active lets the merchant pause loyalty without losing
 * the configured rates. Phase 8 sale pipeline reads this flag
 * before writing the earn ledger entry.
 *
 * No earn-rule history table here — when the merchant changes
 * a rate, the new rate applies going forward. Historical ledger
 * entries already carry the points/cost they were created with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_loyalty_configs', function (Blueprint $table): void {
            $table->id();
            // Singleton per company.
            $table->foreignId('company_id')
                ->unique()
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            // Default 0 means "no auto-earn" — a deliberate
            // off-state. The merchant has to opt in by setting
            // a positive rate.
            $table->unsignedInteger('points_per_omr')->default(0);
            // Default 10 = "1 point = 10 baisas = 0.010 OMR".
            // 100 points = 1.000 OMR off. Most pilot merchants
            // pick this as a starting point and then tweak.
            $table->unsignedInteger('baisas_per_point')->default(10);
            // When false, Phase 8 sale pipeline skips writing
            // earn entries. Existing balances are untouched.
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_loyalty_configs');
    }
};
