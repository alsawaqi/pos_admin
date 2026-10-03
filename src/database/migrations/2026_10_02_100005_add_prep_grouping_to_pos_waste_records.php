<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P3 fix order 1, K4 — a prep waste is ONE event that names the prep.
 *
 * A prep item has no stock: "1 L of sauce thrown away" is recorded as one
 * waste record per raw ingredient behind it (each with its own stock
 * movement). Loss & Waste counted those as N events and never named the
 * sauce. Every record of one prep waste now carries:
 *
 *   prep_ingredient_id  the prep item that was wasted (NULL = a plain
 *                       ingredient waste, every existing row)
 *   waste_group_uuid    one value shared by all the records of that one
 *                       waste event (NULL = the record is its own event)
 *
 * The report counts one event per group and lists prep wastes by name.
 * Nullable columns only: no existing value changes. pos_merchant (and
 * pos_api) mirror the columns in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_waste_records', function (Blueprint $table): void {
            $table->foreignId('prep_ingredient_id')
                ->nullable()
                ->constrained('pos_ingredients')
                ->nullOnDelete();
            $table->uuid('waste_group_uuid')->nullable();
            $table->index('waste_group_uuid', 'pos_waste_records_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_waste_records', function (Blueprint $table): void {
            $table->dropIndex('pos_waste_records_group_idx');
            $table->dropColumn('waste_group_uuid');
            $table->dropConstrainedForeignId('prep_ingredient_id');
        });
    }
};
