<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditionally create a minimal `banks` stub.
 *
 * In PRODUCTION the charity application owns this table (shared
 * Postgres instance per blueprint §3.2) — charity's migrations
 * always run first, so `Schema::hasTable` is true and this
 * migration is a complete no-op.
 *
 * In TESTS (SQLite :memory:) and on a fresh dev environment that
 * has not yet run the charity migrations, the table doesn't exist
 * and the pos_admin FK to `banks` would fail to create. This
 * migration lays down a minimal stub with the columns the POS app
 * actually reads (id, name, short_name, swift_code, is_active,
 * timestamps) so the downstream
 * `2026_05_26_020000_add_bank_id_to_pos_devices` migration can
 * attach its FK.
 *
 * The stub is INTENTIONALLY minimal — the production charity
 * table has more columns (country_id, iban_example, branch_name,
 * phone, email, website, notes) but the POS app only renders
 * name + short_name + swift_code on its dropdown + Show page, so
 * those are the only columns the stub needs to expose. If the POS
 * app ever needs more, add them here AND verify the production
 * column exists in charity_db before reading it.
 *
 * Mirrors the pattern set by
 * {@see 2026_05_25_015000_ensure_commission_profiles_stub.php}.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op when the table already exists (production charity DB).
        if (Schema::hasTable('banks')) {
            return;
        }

        Schema::create('banks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('swift_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally a no-op. Dropping a table that the charity
        // app owns in production would be destructive; in tests the
        // RefreshDatabase trait wipes everything anyway, so the
        // forward-migration on the next test run recreates the stub.
    }
};
