<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P4 data contract — a combo's choice slots ("Main", "Side", "Drink").
 *
 *   combo_product_id  the combo (pos_products.product_type = 'combo'); its
 *                     slots go with it if the product is ever hard-deleted
 *   name / name_ar    the slot label in English / Arabic
 *   min_choices       how many items must be chosen (0 = optional)
 *   max_choices       how many items may be chosen (at least 1)
 *   sort_order        display order inside the combo
 *
 * The slot belongs to the combo's company (the portal enforces it and
 * pos:check-tenant-integrity checks it). On Postgres a CHECK constraint backs
 * the choice bounds; SQLite (the test mirror) enforces nothing.
 * pos_api and pos_merchant mirror the table in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_combo_slots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('combo_product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->integer('min_choices')->default(1);
            $table->integer('max_choices')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['combo_product_id', 'sort_order'], 'pos_combo_slots_combo_sort_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_combo_slots" ADD CONSTRAINT "pos_combo_slots_choices_check" CHECK ("min_choices" >= 0 AND "max_choices" >= 1 AND "max_choices" >= "min_choices")');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_combo_slots');
    }
};
