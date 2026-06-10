<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1 — menu time-windows (per-product daily availability).
 *
 * pos_products gains two additive columns:
 *
 *   available_from + available_until — VARCHAR(8) nullable,
 *     'HH:MM:SS' strings. The daily window in which POS
 *     devices sell the product (e.g. a breakfast menu sold
 *     06:00→11:00). Both NULL = always available (the
 *     default for every existing row).
 *
 * The shape deliberately clones the proven pos_discounts
 * time_start/time_end convention (2026_06_05_010000):
 * 'HH:MM:SS' strings compared lexicographically, NULL =
 * "no bound on that side / all day", and midnight-wrap when
 * start > end — available_from=22:00:00 +
 * available_until=02:00:00 matches the 22:00→24:00 AND
 * 00:00→02:00 windows (overnight menus need no extra
 * schema). The device evaluates the predicate against its
 * local clock, exactly like the discount evaluator.
 *
 * Plain columns on the row (not a windows JSON, not a
 * pivot) keep the merchant update whitelist + audit diffing
 * trivial, and an availability edit bumps the product's own
 * updated_at so delta-syncing devices pick the change up —
 * the same delta-sync rationale as the Phase D2 catalog
 * flags (2026_07_04_010000). If per-day variation is ever
 * needed, add a dayofweek_mask tinyint like pos_discounts
 * rather than reshaping into JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->string('available_from', 8)->nullable()->after('show_on_customer_tablet');
            $table->string('available_until', 8)->nullable()->after('available_from');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn(['available_from', 'available_until']);
        });
    }
};
