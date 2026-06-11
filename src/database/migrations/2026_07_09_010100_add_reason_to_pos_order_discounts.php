<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-F4 — discounts: cashier reason on manual discount applications.
 *
 * pos_order_discounts gains `reason` (nullable VARCHAR(160)) — the
 * cashier's free-text justification for a manual / custom discount
 * ("regular customer", "spilled drink remake", ...). Catalogue-rule
 * applications (discount_id set) normally carry no reason; the column
 * is most meaningful for ad-hoc rows (discount_id NULL), but the
 * writer (pos_api CreateOrderHandler::writeDiscounts) accepts it on
 * any discounts[] entry the device sends.
 *
 * NULL = no reason captured (every existing row + any entry that
 * omits the key). The wire value is trimmed and capped to 160 chars
 * server-side rather than rejected — an offline order batch must not
 * fail because a cashier typed a long note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_discounts', function (Blueprint $table): void {
            $table->string('reason', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_discounts', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
    }
};
