<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two new columns on pos_devices that the admin captures at device
 * registration time:
 *
 *   terminal_id           — Bank-issued terminal identifier. This is
 *                           the permanent id the bank uses to route
 *                           the soft-POS card transactions back to
 *                           the right merchant. Free-form string
 *                           (banks vary — some use 8-digit numerics,
 *                           others use alphanumeric like "T-OM-001").
 *                           Globally unique because the bank treats
 *                           it as the device's primary identity.
 *
 *   commission_profile_id — FK into the charity database's
 *                           `commission_profiles` table (which lives
 *                           in the same Postgres instance — shared
 *                           with the charity app per blueprint §3.2).
 *                           Drives the round-up commission split
 *                           calculation when a card payment lands.
 *
 * Both are nullable in the schema so any pre-existing test/seed
 * rows survive the migration. The FormRequest layer makes them
 * REQUIRED on new device registrations — that way we don't break
 * historical factories but every real registration captures them.
 *
 * Why a real FK to commission_profiles instead of a soft pointer?
 *   The charity DB is the same Postgres instance, so the FK
 *   constraint is enforceable. A soft pointer would let an admin
 *   bind a device to a commission profile id that gets deleted out
 *   from under them; the cascade keeps that impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            // Terminal id — text, not integer, because banks issue a
            // mix of numeric and alphanumeric identifiers depending
            // on region/processor. Unique constraint enforces the
            // "one device per terminal id" rule the bank reconciler
            // depends on.
            $table->string('terminal_id', 64)
                ->nullable()
                ->after('kiosk_id');
            $table->unique('terminal_id', 'pos_devices_terminal_id_unique');

            // Commission profile FK — restrictOnDelete because
            // accidentally removing a commission profile that's
            // bound to live devices would silently break the
            // donation split calculation. Better to force the
            // charity-side admin to first unbind it everywhere.
            $table->foreignId('commission_profile_id')
                ->nullable()
                ->after('terminal_id')
                ->constrained('commission_profiles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            // dropConstrainedForeignId removes the column AND the
            // FK + index in one call.
            $table->dropConstrainedForeignId('commission_profile_id');

            $table->dropUnique('pos_devices_terminal_id_unique');
            $table->dropColumn('terminal_id');
        });
    }
};
