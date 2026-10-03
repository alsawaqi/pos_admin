<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P4 M4 — an add-on group a product OWNS is named per product, so
 * every product can have its own "Size" group (owner decision 2026-10-03:
 * sizes on day one, as a required "Size" choice per product).
 *
 * The single UNIQUE (company_id, name) on pos_addon_groups is replaced by two
 * partial unique indexes:
 *   - shared groups (owner_product_id IS NULL): one name per company, as today;
 *   - owned groups: one name per owner product.
 * Soft-deleted rows still count, exactly like the old index, so the portal's
 * existing "name taken" checks keep their meaning.
 *
 * Partial indexes work on Postgres and SQLite (the test suites). No row
 * changes. Do not roll back once two products own a group of the same name:
 * the old index cannot be recreated over them.
 */
return new class extends Migration
{
    private const OLD = 'pos_addon_groups_company_name_unique';

    private const SHARED = 'pos_addon_groups_company_shared_name_unique';

    private const OWNED = 'pos_addon_groups_owner_name_unique';

    public function up(): void
    {
        Schema::table('pos_addon_groups', function ($table): void {
            $table->dropUnique(self::OLD);
        });

        DB::statement('CREATE UNIQUE INDEX "'.self::SHARED.'" ON "pos_addon_groups" ("company_id", "name") WHERE "owner_product_id" IS NULL');
        DB::statement('CREATE UNIQUE INDEX "'.self::OWNED.'" ON "pos_addon_groups" ("owner_product_id", "name") WHERE "owner_product_id" IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "'.self::OWNED.'"');
        DB::statement('DROP INDEX IF EXISTS "'.self::SHARED.'"');

        Schema::table('pos_addon_groups', function ($table): void {
            $table->unique(['company_id', 'name'], self::OLD);
        });
    }
};
