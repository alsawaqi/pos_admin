<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH combo add-on (tester call 1) — the old "choice slot" model goes
 * away: pos_combo_slots and pos_combo_slot_options are dropped. Slot combos
 * never reached production (Phase 4 is not deployed), so on live both tables
 * are created by the Phase 4 migrations of the same release and are empty
 * here (the rehearsal proves it).
 *
 * A combo that still had slots (local test data only, e.g. "Coffee & cake
 * (test)" and "tet") cannot be converted exactly (a slot lists products, a
 * choice line names a category), so it is switched INACTIVE (updated_at
 * moves, so devices drop it on their next refresh) and is recreated in the
 * new combo editor. Its past order lines keep their combo_slot_id snapshot.
 *
 * down() recreates the two tables EMPTY (as they stood after
 * 2026_10_06_100009) and never re-activates a combo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_combo_slots')) {
            DB::table('pos_products')
                ->where('product_type', 'combo')
                ->where('status', 'active')
                ->whereIn('id', DB::table('pos_combo_slots')->select('combo_product_id'))
                ->update(['status' => 'inactive', 'updated_at' => now()]);
        }

        Schema::dropIfExists('pos_combo_slot_options');
        Schema::dropIfExists('pos_combo_slots');
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_combo_slots')) {
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
                $table->boolean('is_main')->default(false);
                $table->index(['combo_product_id', 'sort_order'], 'pos_combo_slots_combo_sort_idx');
            });
            DB::statement('CREATE UNIQUE INDEX "pos_combo_slots_one_main_unique" ON "pos_combo_slots" ("combo_product_id") WHERE "is_main"');
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE "pos_combo_slots" ADD CONSTRAINT "pos_combo_slots_choices_check" CHECK ("min_choices" >= 0 AND "max_choices" >= 1 AND "max_choices" >= "min_choices")');
            }
        }

        if (! Schema::hasTable('pos_combo_slot_options')) {
            Schema::create('pos_combo_slot_options', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
                $table->foreignId('slot_id')->constrained('pos_combo_slots')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('pos_products')->restrictOnDelete();
                $table->decimal('extra_price', 12, 3)->default(0);
                $table->boolean('is_default')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['slot_id', 'product_id'], 'pos_combo_slot_options_slot_product_unique');
                $table->index(['product_id'], 'pos_combo_slot_options_product_idx');
            });
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE "pos_combo_slot_options" ADD CONSTRAINT "pos_combo_slot_options_extra_price_check" CHECK ("extra_price" >= 0)');
            }
        }
    }
};
