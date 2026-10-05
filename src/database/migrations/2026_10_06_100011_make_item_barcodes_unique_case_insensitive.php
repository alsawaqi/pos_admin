<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH review add-on, fix A-2 — a barcode is unique per company ignoring
 * case, like a SKU (part B treats "ABC123" and "abc123" as the same code).
 *
 * 100006's partial unique (company_id, barcode) WHERE deleted_at IS NULL is
 * case-sensitive; it becomes (company_id, lower(barcode)) WHERE deleted_at IS
 * NULL. A new migration (not an edit of 100006) because local stacks have
 * already run 100006. Postgres and SQLite (the test suites) both support the
 * expression index. No row changes.
 *
 * up() refuses while two live barcodes of a company differ only in case (the
 * new index could not be built over them). down() restores the old index,
 * which every row that satisfied the new one satisfies too.
 */
return new class extends Migration
{
    private const OLD = 'pos_item_barcodes_company_barcode_unique';

    private const NEW = 'pos_item_barcodes_company_barcode_lower_unique';

    public function up(): void
    {
        $clash = DB::table('pos_item_barcodes')->whereNull('deleted_at')
            ->select('company_id', DB::raw('lower(barcode)'))
            ->groupBy('company_id', DB::raw('lower(barcode)'))
            ->havingRaw('count(*) > 1')
            ->exists();
        if ($clash) {
            throw new RuntimeException('Cannot make '.self::NEW.': two live barcodes of a company differ only in case.');
        }

        DB::statement('DROP INDEX IF EXISTS "'.self::OLD.'"');
        DB::statement('CREATE UNIQUE INDEX "'.self::NEW.'" ON "pos_item_barcodes" ("company_id", lower("barcode")) WHERE "deleted_at" IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "'.self::NEW.'"');
        DB::statement('CREATE UNIQUE INDEX "'.self::OLD.'" ON "pos_item_barcodes" ("company_id", "barcode") WHERE "deleted_at" IS NULL');
    }
};
