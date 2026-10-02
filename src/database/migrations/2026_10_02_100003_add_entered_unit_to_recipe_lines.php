<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P3 P3-1 — the recipe editor keeps the entered unit.
 *
 * "5 g" of a kg ingredient used to come back as "0.005 kg", which invites a
 * 1000× "correction". A recipe line now also remembers how it was typed:
 *
 *   entered_unit      the unit picked in the editor: the base unit's name,
 *                     its metric pair (g / kg, ml / l), an extra unit's
 *                     name, or '@piece' (the ingredient's piece unit)
 *   entered_quantity  the quantity typed in that unit
 *
 * `quantity` and `unit_at_set` (product recipes) / `unit` (add-on lines) keep
 * their meaning — the BASE quantity and base unit, which the device API reads
 * unchanged. Existing lines keep NULLs, read as "entered in the base unit".
 *
 * pos_merchant and pos_api mirror the columns in their test schemas.
 */
return new class extends Migration
{
    private const TABLES = ['pos_product_recipes', 'pos_addon_consumptions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('entered_unit', 32)->nullable();
                $table->decimal('entered_quantity', 14, 4)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['entered_unit', 'entered_quantity']);
            });
        }
    }
};
