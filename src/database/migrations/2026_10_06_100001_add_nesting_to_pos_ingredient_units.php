<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH review add-on §3.1 / 01 — containers ("How do you buy it?").
 *
 * The containers are today's pack sizes in pos_ingredient_units. `factor`
 * keeps its meaning: base units in ONE container. A container may hold a
 * number of another container of the same item ("crate holds 12 × bottle
 * 1 l"): contains_unit_id + contains_quantity, and the crate's factor is
 * 12 × the bottle's factor (worked out by the portal on save).
 *
 * The same word may now be used with different sizes ("bottle" 1.5 l and
 * "bottle" 500 ml), so UNIQUE (ingredient_id, name) — which also counted
 * soft-deleted rows — becomes a partial unique on the live size:
 * (ingredient_id, lower(name), factor, COALESCE(contains_unit_id, 0))
 * WHERE deleted_at IS NULL. Partial and expression indexes work on Postgres
 * and SQLite (the test suites).
 *
 * contains_unit_id refuses the delete of a container another one holds.
 * It is NO ACTION (checked at the end of the statement) rather than
 * RESTRICT, so the existing cascade from pos_ingredients still removes a
 * whole set of nested containers in one statement. On Postgres a CHECK
 * keeps the pair whole: both NULL, or both set with a quantity above 0.
 *
 * No existing row changes. down() refuses once two rows of one ingredient
 * share a name (the old index cannot be recreated over them).
 */
return new class extends Migration
{
    private const OLD = 'pos_ingredient_units_ingredient_name_unique';

    private const LIVE = 'pos_ingredient_units_live_size_unique';

    private const CHECK = 'pos_ingredient_units_contains_check';

    public function up(): void
    {
        Schema::table('pos_ingredient_units', function (Blueprint $table): void {
            $table->foreignId('contains_unit_id')->nullable()->constrained('pos_ingredient_units');
            $table->decimal('contains_quantity', 14, 4)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_ingredient_units" ADD CONSTRAINT "'.self::CHECK.'" CHECK (("contains_unit_id" IS NULL AND "contains_quantity" IS NULL) OR ("contains_unit_id" IS NOT NULL AND "contains_quantity" IS NOT NULL AND "contains_quantity" > 0))');
        }

        Schema::table('pos_ingredient_units', function (Blueprint $table): void {
            $table->dropUnique(self::OLD);
        });

        DB::statement('CREATE UNIQUE INDEX "'.self::LIVE.'" ON "pos_ingredient_units" ("ingredient_id", lower("name"), "factor", COALESCE("contains_unit_id", 0)) WHERE "deleted_at" IS NULL');
    }

    public function down(): void
    {
        $clash = DB::table('pos_ingredient_units')->select('ingredient_id', 'name')
            ->groupBy('ingredient_id', 'name')->havingRaw('count(*) > 1')->exists();
        if ($clash) {
            throw new RuntimeException('Cannot roll back '.self::LIVE.': an ingredient has two containers with the same name.');
        }

        DB::statement('DROP INDEX IF EXISTS "'.self::LIVE.'"');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_ingredient_units" DROP CONSTRAINT IF EXISTS "'.self::CHECK.'"');
        }

        Schema::table('pos_ingredient_units', function (Blueprint $table): void {
            $table->dropForeign(['contains_unit_id']);
            $table->dropColumn(['contains_unit_id', 'contains_quantity']);
        });

        Schema::table('pos_ingredient_units', function (Blueprint $table): void {
            $table->unique(['ingredient_id', 'name'], self::OLD);
        });
    }
};
