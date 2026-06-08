<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `organization_id` to pos_devices — the beneficiary organization a
 * device's card round-up donations go to (charity-owned `organizations` table
 * in the shared Postgres instance, §3.2).
 *
 * Chosen by the admin on the Register Device page (like commission_profile_id),
 * then carried onto the charity_transaction when a round-up is recorded so the
 * charity system knows which org each round-up funds.
 *
 * Same shape as the {@see commission_profile_id} / {@see bank_id} FKs — nullable
 * in the schema so pre-existing test/seed rows survive; the FormRequest enforces
 * REQUIRED on new registrations. restrictOnDelete so an org bound to live devices
 * can't be removed out from under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('bank_id')
                ->constrained('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
