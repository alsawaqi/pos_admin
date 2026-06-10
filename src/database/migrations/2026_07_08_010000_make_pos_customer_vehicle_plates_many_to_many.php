<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-F2 — vehicle plate links go many-to-many.
 *
 * The original Phase 6a model (2026_06_01_010100) made a plate belong
 * to AT MOST ONE customer per company via the
 * (company_id, plate_number) unique. Real life disagrees: a family car
 * is driven by several loyalty members, so the business now wants
 * one customer ↔ many plates AND one plate ↔ many customers.
 *
 * Changes (no data backfill needed — the old unique is strictly
 * stronger than the new one, so every existing row already satisfies
 * the new constraint):
 *
 *   1. DROP unique (company_id, plate_number) — frees a plate to link
 *      to several customers within the same merchant's book.
 *   2. ADD unique (company_id, customer_id, plate_number) — one row
 *      per LINK; the same customer can't hold the same plate twice.
 *   3. ADD plain index (company_id, plate_number) — the drive-thru
 *      "plate → customer(s)" lookup hot path, which the dropped
 *      unique used to serve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_customer_vehicle_plates', function (Blueprint $table): void {
            // Name matches the explicit name in the create migration.
            $table->dropUnique('pos_customer_vehicle_plates_company_plate_unique');
            $table->unique(
                ['company_id', 'customer_id', 'plate_number'],
                'pos_cvp_company_customer_plate_unique',
            );
            $table->index(
                ['company_id', 'plate_number'],
                'pos_cvp_company_plate_index',
            );
        });
    }

    public function down(): void
    {
        // NOTE: restoring the old unique fails if any plate is now linked
        // to more than one customer — by design; the rollback is only
        // safe before merchants start sharing plates.
        Schema::table('pos_customer_vehicle_plates', function (Blueprint $table): void {
            $table->dropIndex('pos_cvp_company_plate_index');
            $table->dropUnique('pos_cvp_company_customer_plate_unique');
            $table->unique(
                ['company_id', 'plate_number'],
                'pos_customer_vehicle_plates_company_plate_unique',
            );
        });
    }
};
