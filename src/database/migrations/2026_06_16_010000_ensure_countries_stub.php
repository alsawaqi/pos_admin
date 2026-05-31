<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditionally create a `countries` stub. The charity application owns
 * this table in the shared Postgres DB (blueprint §3.2); its migrations
 * run first so this is a no-op in production. In the SQLite test DB it
 * lays the table down so pos_admin geo features can read/write it.
 *
 * Mirrors {@see 2026_05_26_010000_ensure_banks_stub.php}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('countries')) {
            return;
        }

        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->char('iso_code', 2)->unique();
            $table->string('phone_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // No-op: the charity app owns this table in production.
    }
};
