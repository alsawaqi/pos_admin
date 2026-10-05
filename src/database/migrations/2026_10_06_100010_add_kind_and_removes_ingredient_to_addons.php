<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH review add-on §3.1 / 10 — tap lists (owner decision D12, tester
 * call 1: Remove and instructions are add-on groups with a kind).
 *
 *   pos_addon_groups.kind            'extras' (today's groups, the default),
 *                                    'remove' (a product's owned "Remove"
 *                                    group, managed from its recipe) or
 *                                    'instructions' (price-0 quick
 *                                    instructions); Postgres CHECK
 *   pos_addons.removes_ingredient_id the recipe ingredient a Remove option
 *                                    leaves out of the line's recipe copy
 *                                    (so it is not taken from stock);
 *                                    emptied if the ingredient is deleted
 *
 * Every existing group is 'extras' and every add-on removes nothing, so
 * the order wire and today's installed apps are unchanged.
 * pos:check-tenant-integrity checks the ingredient's company, that a remove
 * group is owned and that remove / instruction options are price 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_addon_groups', function (Blueprint $table): void {
            $table->string('kind', 16)->default('extras');
        });

        Schema::table('pos_addons', function (Blueprint $table): void {
            $table->foreignId('removes_ingredient_id')->nullable()->constrained('pos_ingredients')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_addon_groups" ADD CONSTRAINT "pos_addon_groups_kind_check" CHECK ("kind" IN (\'extras\', \'remove\', \'instructions\'))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_addon_groups" DROP CONSTRAINT IF EXISTS "pos_addon_groups_kind_check"');
        }

        Schema::table('pos_addons', function (Blueprint $table): void {
            $table->dropForeign(['removes_ingredient_id']);
            $table->dropColumn('removes_ingredient_id');
        });

        Schema::table('pos_addon_groups', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
