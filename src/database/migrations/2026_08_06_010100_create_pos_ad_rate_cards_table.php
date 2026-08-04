<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (advertiser billing) — the RATE CARD: what an advertiser pays
 * for delivery on the POS screens.
 *
 *   pricing_model 'per_day_per_screen'       — rate × each DISTINCT
 *                  (device, calendar day) their content actually played on
 *   pricing_model 'per_thousand_impressions' — rate × plays ÷ 1000
 *
 * Billing is DELIVERY-based, metered from pos_marketing_impressions
 * (replay-guarded: over-billing is structurally impossible; a play lost
 * on a flaky link under-bills, never the reverse).
 *
 * advertiser_id references the marketing-api-owned `advertisers` table —
 * app-level link, NO DB FK (cross-app ownership, same as
 * content_assets.advertiser_id). One ACTIVE card per advertiser,
 * enforced by SetAdRateCardAction (older cards are deactivated, kept
 * as pricing history; invoices snapshot the model+rate anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_ad_rate_cards')) {
            return;
        }

        Schema::create('pos_ad_rate_cards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('advertiser_id')->index();
            $table->string('pricing_model', 32);
            $table->decimal('rate', 12, 3);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('pos_users')->nullOnDelete();
            $table->timestamps();
            $table->index(['advertiser_id', 'is_active'], 'pos_ad_rate_cards_advertiser_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_ad_rate_cards');
    }
};
