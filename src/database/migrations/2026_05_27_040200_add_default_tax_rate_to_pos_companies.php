<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `default_tax_rate` to pos_companies. Phase 6.
 *
 * Per the Phase 6 scope decisions: tax is company-level
 * default + per-product override. This column carries the
 * default. pos_products.tax_rate (NULL by default) overrides
 * when set.
 *
 * Default 5.00 because Oman VAT is 5% and that's the only
 * market the system targets in v1. Merchants in a future
 * zero-VAT zone or different jurisdiction edit this via
 * Settings → Company → Tax (not yet exposed in the UI —
 * stored value is queryable + used by orders/reports until
 * then).
 *
 * Stored as a percentage value (5.00 = 5%, not 0.05).
 * Avoids the "is this a fraction or a percent?" ambiguity
 * across the codebase by picking percent everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_companies', function (Blueprint $table): void {
            $table->decimal('default_tax_rate', 5, 2)->default(5.00)->after('default_locale');
        });

        // Backfill existing rows — the column has a default,
        // but defaults only apply to NEW inserts. Existing
        // rows would be NULL otherwise (Postgres ignores the
        // column default during ALTER TABLE on populated
        // tables in some versions). Explicit UPDATE makes the
        // behaviour deterministic across drivers.
        \Illuminate\Support\Facades\DB::table('pos_companies')
            ->whereNull('default_tax_rate')
            ->update(['default_tax_rate' => 5.00]);
    }

    public function down(): void
    {
        Schema::table('pos_companies', function (Blueprint $table): void {
            $table->dropColumn('default_tax_rate');
        });
    }
};
