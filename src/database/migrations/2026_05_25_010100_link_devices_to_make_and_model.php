<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the free-text `model` string column on pos_devices with
 * proper FKs into the new pos_device_makes / pos_device_models
 * catalogue (created in the migration immediately before this one).
 *
 * No data migration — pos_devices is still effectively empty in
 * pilot, and any test rows that exist were factory-seeded with
 * model=null. Restoring the old behaviour means dropping the FKs
 * and reading back the model text from the catalogue join.
 *
 * Why both make_id AND model_id when model_id implies make_id?
 *   For two reasons:
 *     1. We sometimes know the make from scalefusion (e.g. the
 *        device reports "Sunmi" as its vendor string) before we
 *        know the exact model. Allowing make_id alone lets the
 *        admin record "this is a Sunmi" and refine later.
 *     2. The Register Device dropdown is two-step (pick make →
 *        models filtered by it). Storing both columns lets the
 *        list endpoint render "Sunmi / P2 Mini" without a JOIN
 *        chain through models→makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            // FKs that null out if the catalogue row is deleted.
            // In practice the DeleteAction blocks delete when any
            // device references the row, so this cascade only fires
            // when the entry was already orphan.
            $table->foreignId('make_id')
                ->nullable()
                ->after('device_type')
                ->constrained('pos_device_makes')
                ->nullOnDelete();
            $table->foreignId('model_id')
                ->nullable()
                ->after('make_id')
                ->constrained('pos_device_models')
                ->nullOnDelete();
        });

        // Drop the legacy free-text column. Done in its own
        // Schema::table call so the column drop doesn't tangle with
        // the FK adds — some drivers (looking at you, SQLite test
        // adapter) get confused when you mix structural changes.
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropColumn('model');
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            // Re-add the legacy column. Existing rows get the empty
            // default; the original migration had it nullable so
            // this preserves the original behaviour.
            $table->string('model')->nullable()->after('device_type');
        });

        Schema::table('pos_devices', function (Blueprint $table): void {
            // dropConstrainedForeignId removes both the constraint
            // and the column in one call.
            $table->dropConstrainedForeignId('model_id');
            $table->dropConstrainedForeignId('make_id');
        });
    }
};
