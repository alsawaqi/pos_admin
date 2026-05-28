<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditionally create a minimal `commission_profiles` stub.
 *
 * In PRODUCTION the charity application owns this table (shared
 * Postgres instance per blueprint §3.2) — charity's migrations
 * always run first, so `Schema::hasTable` is true and this
 * migration is a complete no-op.
 *
 * In TESTS (SQLite :memory:) and on a fresh dev environment that
 * has not yet run the charity migrations, the table doesn't exist
 * and the pos_admin FK to it would fail to create. This migration
 * lays down a minimal stub with the columns the POS app actually
 * reads (id, name, description, is_active, timestamps) so the
 * downstream `2026_05_25_020000_add_terminal_id_and_commission_profile`
 * migration can attach its FK.
 *
 * The stub is INTENTIONALLY minimal — if the charity app adds
 * more columns (commission rate fields, etc.), the production
 * table already has them and the POS app doesn't depend on them.
 * The stub only needs to satisfy the FK + the dropdown listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op when the table already exists (production charity DB).
        if (Schema::hasTable('commission_profiles')) {
            return;
        }

        Schema::create('commission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
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
