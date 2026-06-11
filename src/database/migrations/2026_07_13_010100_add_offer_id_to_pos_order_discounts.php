<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-F9 — offer applications land as pos_order_discounts rows.
 *
 * An offer the device applied to an order is recorded as a discount-
 * application row carrying `offer_id` (name_snapshot = the offer's name,
 * frozen at sale time so a rename / soft-delete still reads correctly in
 * reports). discount_id stays NULL for those rows — an entry belongs to
 * either a discount rule or an offer, never both.
 *
 * NULL = the row is a plain discount application (every existing row).
 * SET NULL on (hard) delete mirrors discount_id; the merchant flow only
 * ever soft-deletes, where the snapshot keeps history intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_discounts', function (Blueprint $table): void {
            $table->foreignId('offer_id')
                ->nullable()
                ->after('discount_id')
                ->constrained('pos_offers')
                ->nullOnDelete();

            // by-offer report scan: company + offer.
            $table->index(['company_id', 'offer_id'], 'pos_order_discounts_company_offer_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_discounts', function (Blueprint $table): void {
            $table->dropIndex('pos_order_discounts_company_offer_idx');
            $table->dropConstrainedForeignId('offer_id');
        });
    }
};
