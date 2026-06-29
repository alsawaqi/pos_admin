<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `is_advertiser_only` to pos_companies — Phase 2A+ advertiser onboarding.
 *
 * The marketing platform lets the admin onboard a brand-new *advertising-only*
 * company (trade name, CR, owner, business activities) the same way a merchant
 * is onboarded, minus the commission step. Those companies live in the SAME
 * pos_companies table as real merchants but are flagged here so they're kept
 * out of the Merchants list / device fan-out and never treated as POS tenants.
 *
 * A real POS merchant has is_advertiser_only = false (the default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_companies', function (Blueprint $table): void {
            $table->boolean('is_advertiser_only')->default(false)->after('status');
        });

        // Defaults only apply to NEW inserts; backfill existing rows so the
        // Merchants-list filter (`where is_advertiser_only = false`) sees every
        // current company. Deterministic across Postgres + SQLite.
        DB::table('pos_companies')
            ->whereNull('is_advertiser_only')
            ->update(['is_advertiser_only' => false]);
    }

    public function down(): void
    {
        Schema::table('pos_companies', function (Blueprint $table): void {
            $table->dropColumn('is_advertiser_only');
        });
    }
};
