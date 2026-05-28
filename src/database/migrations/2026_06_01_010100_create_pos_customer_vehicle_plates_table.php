<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6a — Vehicle plates per customer (blueprint §6.1).
 *
 * 1:N to pos_customers. A family with two cars is one
 * customer row with two plate rows. The drive-thru cashier
 * types a plate; we find the customer and surface their
 * full history in one step.
 *
 * Tenancy: company_id is DENORMALISED from the parent
 * customer row. Two upsides over joining-through-customer:
 *   1. The (company_id, plate_number) unique constraint —
 *      a plate is a real-world unique identifier and the
 *      same plate can't exist twice in the same merchant's
 *      book.
 *   2. The "find customer by plate" lookup at the POS doesn't
 *      need a join — index hit on the plate, follow customer_id.
 *
 * We accept the cost: if the customer's company changes
 * (which it can't — that would be a re-tenant, not an edit),
 * we'd need to propagate. Since company moves don't happen,
 * the denormalisation is safe.
 *
 * Plate format: stored as plain string. Oman plates look like
 * "12345 A" or "1 PA"; UAE plates look like "DXB A 12345".
 * We don't enforce a format at the DB layer — the Action
 * normalises (trim + uppercase) and the UI guides input.
 *
 * Cascade-on-customer-delete: deleting a customer (soft or
 * hard) does NOT cascade — the FK is cascadeOnDelete here
 * but the customer's deleted_at soft-delete leaves the
 * customer row in place. Plates only vanish when the customer
 * row is physically deleted, which is a deliberate manual
 * operation (admin-tooling area).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_vehicle_plates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')
                ->constrained('pos_customers')
                ->cascadeOnDelete();
            // Denormalised from the parent customer for unique-
            // constraint + index-hit reasons (see class doc).
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            // 32 chars handles every plate format we've seen
            // across the Gulf (Oman / UAE / KSA). Stored
            // upper-case (Action normalises).
            $table->string('plate_number', 32);
            $table->timestamps();
            // Real-world uniqueness — within one merchant's
            // book, a plate appears on at most one customer
            // record. Cross-merchant overlaps are fine.
            // The unique index also serves the drive-thru
            // "lookup customer by plate" hot path; no separate
            // covering index needed.
            $table->unique(['company_id', 'plate_number'], 'pos_customer_vehicle_plates_company_plate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_vehicle_plates');
    }
};
