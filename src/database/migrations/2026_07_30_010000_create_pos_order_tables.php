<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joined dine-in tables (v2) — the EXTRA tables a single shared order covered.
 *
 * When a party is seated across several tables (a big group pulls in a free
 * neighbouring table and joins it), the POS keeps ONE order for the party. The
 * order's PRIMARY table stays on pos_orders.table_id as always; this pivot
 * records the additional tables the same order covered, so the merchant
 * per-table record can show that order under EVERY table it spanned ("joined
 * with T2, T3").
 *
 * Written by the pos_api sale pipeline at order.create / order.hold (the
 * device sends joined_table_ids; the handler validates them in-tenant and
 * writes one row per EXTRA table, excluding the primary). Idempotent: a
 * re-hold/finalize purges + reinserts these rows like the other order children.
 *
 *   - order_id  cascadeOnDelete  → a deleted order drops its coverage rows.
 *   - table_id  nullOnDelete     → matches the pos_orders.table_id precedent
 *     (a hard-deleted table doesn't erase the historical order; merchants
 *     SOFT-delete tables, so the label still resolves via withTrashed()).
 *
 * Pure join table — no money/qty columns. The primary table is NEVER stored
 * here (it lives on pos_orders.table_id), and unique(order_id, table_id)
 * keeps the per-table fan-out idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_tables', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('pos_orders')
                ->cascadeOnDelete();

            $table->foreignId('table_id')
                ->nullable()
                ->constrained('pos_tables')
                ->nullOnDelete();

            $table->timestamps();

            // One coverage row per (order, table); guards the analytics fan-out.
            $table->unique(['order_id', 'table_id'], 'pos_order_tables_order_table_unique');
            // Reverse lookup: "every order that covered table X" (per-table report).
            $table->index(['table_id'], 'pos_order_tables_table_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_tables');
    }
};
