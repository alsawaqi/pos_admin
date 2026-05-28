<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7a — Orders header table (blueprint §10.8).
 *
 * The transactional spine of the platform. Every sale flowing
 * through the POS lands here. Phase 7 reports query this table
 * and its lines; Phase 8+ POS sale pipeline writes against it.
 *
 * Snapshot columns (subtotal / discount_total / tax_total /
 * grand_total) are calculated at order-write time and frozen.
 * Recipe + price snapshots live on the LINES (see
 * pos_order_items.recipe_snapshot_json), not here.
 *
 * Status lifecycle (per §10.8 + the §5.11 reporting expectations):
 *
 *   open  ──Hold──> held  ──SendToKitchen──> kitchen ──Pay──> paid
 *     │                                                       │
 *     │                                                       ↓
 *     └──Void──> void                                      refunded
 *
 *   Status values: open / held / kitchen / paid / void / refunded.
 *   Application enum (App\Enums\OrderStatus) gates inputs; the
 *   column is a plain 32-char string at the DB level so adding
 *   a new status (e.g. partial_refund) doesn't need a migration.
 *
 * order_type catalogue (per §5.3.1 + §10.8): quick / dine_in /
 * to_go / delivery / car. 'car' is the drive-thru / car-side
 * pickup case from the customer-tablet flow (§5.7.3): the
 * cashier types the plate, we tie the order to a customer
 * record via the (company_id, plate_number) lookup from Phase 6a.
 *
 * source catalogue: main_pos / handheld / customer_tablet —
 * which device family wrote the order. Used by §5.11.10 Staff
 * Activity Report and the device-health observability dashboard.
 *
 * client_event_id UNIQUE: the offline POS keeps its own event
 * IDs while disconnected so sync replays are idempotent (server-
 * authoritative dedupe via this UNIQUE constraint per blueprint
 * §11.4 /api/v1/device/sync/push).
 *
 * Indexes lead with company_id per the blueprint's multi-tenancy
 * rule (§9.11). Composite indexes on (company_id, opened_at)
 * + (branch_id, opened_at) cover the "sales today" + "branch
 * activity" dashboard queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // ---- Tenant + scope ----
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            // device_id is nullable for orders captured via paths
            // that don't tie to a specific device (a future
            // Customer Tablet kiosk-mode flow doesn't bind to
            // one device the same way the main POS does).
            $table->foreignId('device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->nullOnDelete();
            // staff_id nullable so a system-generated order
            // (e.g. auto-refund replay during reconciliation)
            // doesn't fall over the FK constraint.
            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            // customer optional — walk-in orders don't tie to one.
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('pos_customers')
                ->nullOnDelete();
            // table only meaningful for dine_in.
            $table->foreignId('table_id')
                ->nullable()
                ->constrained('pos_tables')
                ->nullOnDelete();

            // ---- Classification ----
            // 32-char string. Application enum
            // {@see App\Enums\OrderType} restricts values:
            // quick / dine_in / to_go / delivery / car.
            $table->string('order_type', 32);
            // 32-char string. App enum {@see App\Enums\OrderStatus}.
            // open / held / kitchen / paid / void / refunded.
            $table->string('status', 32)->default('open');
            // 32-char string. App enum {@see App\Enums\OrderSource}.
            // main_pos / handheld / customer_tablet.
            $table->string('source', 32);

            // Denormalised plate for drive-thru / car-side orders.
            // Even when customer_id is null we can still resolve
            // who arrived in which car for §5.7.3 Customer
            // Identification at POS.
            $table->string('plate_number', 32)->nullable();

            // ---- Money (signed totals snapshotted at write-time) ----
            // decimal(12,3) OMR baisas precision. NEVER float.
            // Invariant: subtotal - discount_total + tax_total
            // == grand_total to within baisa precision.
            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('discount_total', 12, 3)->default(0);
            $table->decimal('tax_total', 12, 3)->default(0);
            $table->decimal('grand_total', 12, 3)->default(0);

            // ---- Timestamps + sync ----
            // opened_at = when the cashier hit "new order" on
            // the POS. closed_at = when status flipped to paid
            // / void / refunded (terminal). Both stored separately
            // from created_at / updated_at so reports query the
            // BUSINESS-meaningful timestamps (the merchant cares
            // about "when was the sale", not "when was the DB
            // row written").
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();

            // The offline-POS client event id. UNIQUE so a
            // sync replay (POS retries after the network blip)
            // can't write the same order twice. Per blueprint
            // §11.4 /api/v1/device/sync/push semantics.
            $table->string('client_event_id', 64)->nullable()->unique('pos_orders_client_event_id_unique');

            $table->text('note')->nullable();

            $table->timestamps();

            // ---- Hot-path indexes ----
            // "Sales today across all branches" → company-scoped
            // by-opened_at sort.
            $table->index(['company_id', 'opened_at'], 'pos_orders_company_opened_idx');
            // "Activity for one branch over a window" → branch-
            // scoped by-opened_at.
            $table->index(['branch_id', 'opened_at'], 'pos_orders_branch_opened_idx');
            // "Open orders awaiting payment" → company + status.
            $table->index(['company_id', 'status'], 'pos_orders_company_status_idx');
            // Customer history lookup (§5.7.2 Order History tab).
            $table->index(['customer_id'], 'pos_orders_customer_idx');
            // Staff Activity Report (§5.11.10).
            $table->index(['staff_id', 'opened_at'], 'pos_orders_staff_opened_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_orders');
    }
};
