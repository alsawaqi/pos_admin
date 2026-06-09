<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company merchant POS policy store (`pos_company_settings`).
 *
 * A generic per-company key/value table for POS policy the MERCHANT configures
 * at runtime — distinct from the platform-level `pos_settings` (admin/global,
 * globally-unique key). The merchant portal can't write the admin-owned
 * `pos_companies.settings` column (Company is read-only there), so per-merchant
 * knobs live here, owned-schema-by-admin / written-by-merchant / read-by-pos_api
 * — the same split as `pos_expense_categories`.
 *
 * First consumer (v2 #14): key `order_cancel_positions` → a JSON array of the
 * staff positions allowed to cancel a completed order at the POS; pos_api emits
 * it in /device/config and the device enforces it. The table is deliberately
 * generic so later POS policies just add a key, not a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_company_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            // Convention: a snake_case policy key, e.g. "order_cancel_positions".
            $table->string('key', 64);
            // JSON payload (scalar, array, or object). JSONB on Postgres; the
            // SQLite test layer uses TEXT + Eloquent's `array`/`json` cast.
            $table->json('value')->nullable();
            $table->timestamps();

            // One row per (company, key) — the write path upserts on this.
            $table->unique(['company_id', 'key'], 'pos_company_settings_company_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_company_settings');
    }
};
