<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * LAUNCH review add-on §3.1 / 03 (data) — the count container becomes a
 * container row (inventory audit A3, tester call 6).
 *
 * For each ingredient with a count-container label (piece_unit_label, not
 * blank) and units_per_piece > 0 whose count_container_id is still empty:
 *   - link the live, un-nested container of that ingredient with the same
 *     lower(name) and the same factor, if there is one;
 *   - otherwise insert one: name = the label, name_ar = the Arabic label,
 *     factor = units_per_piece, after the item's other containers;
 *   - then set count_container_id.
 *
 * Query builder only. It never touches updated_at, the piece_* /
 * units_per_piece / allow_fractional_pieces columns (they stay as the
 * mirror installed apps read) or any stock table. Idempotent: a re-run
 * finds every count_container_id set and does nothing.
 *
 * An inserted container gets a uuid derived from its ingredient's uuid
 * (uuid v5), so down() can remove exactly the rows it inserted (never a
 * linked container the merchant made) while nothing else uses them.
 */
return new class extends Migration
{
    private const NAMESPACE = 'b3c1f0a4-6f1e-4c3a-9a51-2a0d6e8f7c10';

    public static function insertedUuid(string $ingredientUuid): string
    {
        return Uuid::uuid5(self::NAMESPACE, 'mithqal:count-container:'.$ingredientUuid)->toString();
    }

    public function up(): void
    {
        $now = Carbon::now();

        DB::table('pos_ingredients')
            ->whereNull('count_container_id')
            ->whereNotNull('piece_unit_label')
            ->whereRaw("trim(piece_unit_label) <> ''")
            ->where('units_per_piece', '>', 0)
            ->orderBy('id')
            ->select(['id', 'uuid', 'company_id', 'piece_unit_label', 'piece_unit_label_ar', 'units_per_piece'])
            ->chunkById(200, function ($ingredients) use ($now): void {
                foreach ($ingredients as $ingredient) {
                    DB::transaction(function () use ($ingredient, $now): void {
                        $containerId = DB::table('pos_ingredient_units')
                            ->where('ingredient_id', $ingredient->id)
                            ->whereNull('deleted_at')
                            ->whereNull('contains_unit_id')
                            ->whereRaw('lower(name) = lower(?)', [$ingredient->piece_unit_label])
                            ->where('factor', $ingredient->units_per_piece)
                            ->orderBy('id')
                            ->value('id');

                        if ($containerId === null) {
                            $containerId = DB::table('pos_ingredient_units')->insertGetId([
                                'uuid' => self::insertedUuid((string) $ingredient->uuid),
                                'company_id' => $ingredient->company_id,
                                'ingredient_id' => $ingredient->id,
                                'name' => $ingredient->piece_unit_label,
                                'name_ar' => $ingredient->piece_unit_label_ar,
                                'factor' => $ingredient->units_per_piece,
                                'sort_order' => (int) DB::table('pos_ingredient_units')
                                    ->where('ingredient_id', $ingredient->id)->max('sort_order') + 1,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }

                        DB::table('pos_ingredients')->where('id', $ingredient->id)->whereNull('count_container_id')
                            ->update(['count_container_id' => $containerId]);
                    });
                }
            });
    }

    public function down(): void
    {
        DB::table('pos_ingredients')->orderBy('id')->select(['id', 'uuid'])
            ->chunkById(200, function ($ingredients): void {
                foreach ($ingredients as $ingredient) {
                    $uuid = self::insertedUuid((string) $ingredient->uuid);
                    DB::table('pos_ingredients')->where('id', $ingredient->id)
                        ->whereIn('count_container_id', DB::table('pos_ingredient_units')->select('id')->where('uuid', $uuid))
                        ->update(['count_container_id' => null]);
                    DB::table('pos_ingredient_units')->where('uuid', $uuid)->where('ingredient_id', $ingredient->id)
                        ->whereNotExists(fn ($q) => $q->from('pos_ingredient_units as outer_unit')
                            ->whereColumn('outer_unit.contains_unit_id', 'pos_ingredient_units.id'))
                        ->whereNotExists(fn ($q) => $q->from('pos_ingredients as linked')
                            ->whereColumn('linked.count_container_id', 'pos_ingredient_units.id'))
                        ->delete();
                }
            });
    }
};
