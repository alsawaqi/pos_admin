<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditionally create a minimal `organizations` stub.
 *
 * In PRODUCTION the charity application owns this table (shared Postgres
 * instance per blueprint §3.2) — charity's migrations run first, so
 * `Schema::hasTable` is true and this migration is a complete no-op.
 *
 * In TESTS (SQLite :memory:) and fresh dev environments that haven't run the
 * charity migrations, the table doesn't exist and the pos_admin FK to
 * `organizations` (added by 2026_06_27_000000_add_organization_id_to_pos_devices)
 * would fail to create. This lays down a minimal stub with the columns the POS
 * app reads (id, name, is_active, timestamps).
 *
 * Mirrors {@see 2026_05_26_010000_ensure_banks_stub.php}.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op when the table already exists (production charity DB).
        if (Schema::hasTable('organizations')) {
            return;
        }

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally a no-op — dropping a charity-owned table in production
        // would be destructive; tests wipe everything via RefreshDatabase.
    }
};
