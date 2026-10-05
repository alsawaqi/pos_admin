<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH review add-on §3.1 / 04 (data) — a SKU for every existing live
 * ingredient (tester call 11).
 *
 * Per company, in id order, each live ingredient without a SKU gets the
 * next free ING-#### (at least 4 digits: ING-0001 … ING-9999, ING-10000),
 * skipping every code already used, case-insensitively, by any ingredient
 * or product row of that company (soft-deleted rows included, so a restore
 * never collides).
 *
 * Writes the new sku column only (query builder: updated_at, the device
 * config deltas and every existing value stay as they are). Soft-deleted
 * ingredients get one on their next save. Idempotent: a re-run finds no
 * live ingredient without a SKU. Physical items (pos_products) are not
 * touched: they get one on their next save, or from the portal's
 * "Generate missing SKUs" button.
 *
 * down() is a no-op: the column goes with 100002's down().
 */
return new class extends Migration
{
    public static function code(int $number): string
    {
        return 'ING-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function up(): void
    {
        $companies = DB::table('pos_ingredients')->whereNull('sku')->whereNull('deleted_at')
            ->distinct()->orderBy('company_id')->pluck('company_id');

        foreach ($companies as $companyId) {
            DB::transaction(function () use ($companyId): void {
                if (DB::getDriverName() === 'pgsql') {
                    // A per-company lock (key hashtext('pos_sku:<company id>')); the
                    // deploy runs with the portal down, so this is belt and braces.
                    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['pos_sku:'.$companyId]);
                }

                $used = [];
                foreach (['pos_ingredients', 'pos_products'] as $table) {
                    foreach (DB::table($table)->where('company_id', $companyId)->whereNotNull('sku')->pluck('sku') as $sku) {
                        $used[mb_strtolower(trim((string) $sku))] = true;
                    }
                }

                $next = 1;
                $ids = DB::table('pos_ingredients')->where('company_id', $companyId)
                    ->whereNull('sku')->whereNull('deleted_at')->orderBy('id')->pluck('id');
                foreach ($ids as $id) {
                    while (isset($used[mb_strtolower(self::code($next))])) {
                        $next++;
                    }
                    $code = self::code($next);
                    $used[mb_strtolower($code)] = true;
                    DB::table('pos_ingredients')->where('id', $id)->whereNull('sku')->update(['sku' => $code]);
                }
            });
        }
    }

    public function down(): void
    {
        // The sku column (and every value written here) goes with 100002's down().
    }
};
