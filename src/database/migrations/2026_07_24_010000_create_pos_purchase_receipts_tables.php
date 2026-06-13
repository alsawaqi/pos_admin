<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PD6 — the Goods Received Note (Saved Purchase Receipt).
 *
 * The merchant asked for ONE page where a whole delivery is recorded in a
 * single document: pick many items (ingredients + ready/bought-in products +
 * physical items) mixed freely, give each a quantity + cost, optionally split
 * each one across branches right there, and add any number of named extra
 * charges (delivery, customs, handling…). The receipt is SAVED and reopenable.
 *
 * Three tables:
 *
 * 1. pos_purchase_receipts — the document header. supplier_id + reference +
 *    received_at + note, and three frozen totals (items / charges / grand).
 *    branch_id is intentionally ABSENT: a receipt arrives at the company's
 *    central warehouse and is allocated OUT to branches per line, so the
 *    document itself is company-wide (matching the cash-model HQ purchase the
 *    PD5 receives book at branch_id NULL).
 *
 * 2. pos_purchase_receipt_lines — one row per item bought. item_type is
 *    'ingredient' or 'product' (a product covers a ready/bought-in sellable AND
 *    a physical item — is_internal on the product decides the expense category),
 *    with the matching nullable FK set. quantity is in the item's base unit;
 *    line_cost books the categorized purchase expense. allocations_json snapshots
 *    where the line was distributed ([{branch_id, branch_uuid, branch_name,
 *    quantity}]); whatever is not allocated stays in the central warehouse and
 *    can be allocated later from the item's existing Stock dialog. expense_id
 *    links the booked pos_expenses row for audit/traceability.
 *
 * 3. pos_purchase_receipt_charges — the named receipt-level extra charges. Each
 *    books its OWN pos_expenses row under its chosen category (default
 *    'delivery'); name is the freeform label ("Customs", "Handling").
 *
 * Schema owner: pos_admin. Mirrored into pos_merchant's test schema. pos_api +
 * pos_machine are untouched — the device reads stock exactly as before; a
 * receipt is a portal data-entry concept that simply drives the same
 * receive/allocate/expense machinery the per-item flows already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('pos_suppliers')
                ->nullOnDelete();
            // Vendor invoice / delivery-note number, free text.
            $table->string('reference', 100)->nullable();
            // Frozen money totals (OMR, 3-decimal baisas): Σ line costs,
            // Σ named charges, and their sum. Stored so the document reads
            // back identically even if an item is later renamed/retired.
            $table->decimal('items_total', 12, 3)->default(0);
            $table->decimal('charges_total', 12, 3)->default(0);
            $table->decimal('grand_total', 12, 3)->default(0);
            $table->string('status', 32)->default('received');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            // The date the goods were received (the accounting date the
            // line + charge expenses are stamped with). Defaults to now.
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'received_at'], 'pos_purchase_receipts_company_received_idx');
        });

        Schema::create('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_id')
                ->constrained('pos_purchase_receipts')
                ->cascadeOnDelete();
            // 'ingredient' | 'product' — the matching FK below is set, the
            // other stays NULL (XOR enforced in the app layer).
            $table->string('item_type', 16);
            $table->foreignId('ingredient_id')
                ->nullable()
                ->constrained('pos_ingredients')
                ->nullOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('pos_products')
                ->nullOnDelete();
            // Snapshot of the item name at receipt time — keeps the document
            // readable after a rename/soft-delete.
            $table->string('item_name');
            // Total received, in the item's BASE unit.
            $table->decimal('quantity', 12, 3);
            // Base-unit label snapshot for ingredients ("kg"); NULL for a
            // product (a finished unit has no measure).
            $table->string('unit', 16)->nullable();
            // The total cost of this line — what books the categorized
            // purchase expense. 0 = a no-cost line (free sample / correction),
            // which books nothing.
            $table->decimal('line_cost', 12, 3)->default(0);
            // Snapshot of the category the line cost booked to
            // ('ingredients' | 'stock_purchases' | 'physical_items').
            $table->string('expense_category', 32)->nullable();
            // [{branch_id, branch_uuid, branch_name, quantity}] — where this
            // line was distributed. NULL/[] = all of it stayed central.
            $table->json('allocations_json')->nullable();
            // The booked item-cost pos_expenses row (NULL when line_cost = 0).
            $table->foreignId('expense_id')
                ->nullable()
                ->constrained('pos_expenses')
                ->nullOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['purchase_receipt_id'], 'pos_purchase_receipt_lines_receipt_idx');
        });

        Schema::create('pos_purchase_receipt_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_id')
                ->constrained('pos_purchase_receipts')
                ->cascadeOnDelete();
            // Freeform charge label ("Delivery", "Customs", "Handling").
            $table->string('name');
            // The expense category this charge books to (default 'delivery').
            $table->string('expense_category', 32)->default('delivery');
            $table->decimal('amount', 12, 3)->default(0);
            // The booked pos_expenses row for this charge.
            $table->foreignId('expense_id')
                ->nullable()
                ->constrained('pos_expenses')
                ->nullOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['purchase_receipt_id'], 'pos_purchase_receipt_charges_receipt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_purchase_receipt_charges');
        Schema::dropIfExists('pos_purchase_receipt_lines');
        Schema::dropIfExists('pos_purchase_receipts');
    }
};
