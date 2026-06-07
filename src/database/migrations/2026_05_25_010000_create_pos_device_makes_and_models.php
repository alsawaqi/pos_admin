<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data for the physical devices MITHQAL ships to merchants.
 *
 * Two tables:
 *
 *   pos_device_makes  — the manufacturers (Sunmi, PAX, NEXGO,
 *                       Ingenico …). Each row is a brand.
 *
 *   pos_device_models — the specific products from each maker (Sunmi
 *                       P2 Mini, PAX A920 Pro …). Each row is FK'd
 *                       to one make and cascade-deleted with it.
 *
 * Why a structured catalogue instead of free text on pos_devices:
 *   The blueprint (§4.4.2) lists hardware model as "auto from
 *   scalefusion when possible; otherwise manual". When typed by
 *   hand, the same physical device ends up as "Sunmi P2 Mini",
 *   "sunmi-p2-mini", or "Sunmi P2-Mini" in three records — making
 *   fleet reports across "all the P2 Minis" impossible. Pinning
 *   each device to a row in this catalogue gives us a single
 *   spelling per device class and a single page for the admin to
 *   manage the list.
 *
 * Why is_active vs hard delete: the delete action refuses to remove
 * a make/model that is still in use by any device (409 Conflict).
 * Toggling is_active hides the entry from the Register Device form
 * without affecting any already-registered devices. Same pattern as
 * the Business Activities catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. Makes -------------------------------------------------
        Schema::create('pos_device_makes', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique('pos_device_makes_name_unique');
            // Lower display_order surfaces earlier in the dropdown.
            // Default 0 keeps everything in name order until the admin
            // promotes specific entries.
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---- 2. Models ------------------------------------------------
        Schema::create('pos_device_models', function (Blueprint $table): void {
            $table->id();

            // Cascade so removing a make wipes its models too. The
            // Action layer refuses to remove an in-use make so this
            // cascade only fires when the make is genuinely orphan.
            $table->foreignId('make_id')
                ->constrained('pos_device_makes')
                ->cascadeOnDelete();

            // Display name shown in the Register Device dropdown.
            // Unique within a make — Sunmi can have one "P2 Mini",
            // but two different makes can each have a "Pro" model.
            $table->string('name');

            // Optional short code (e.g. "P2-MINI") for export / API
            // use. Not displayed in the dropdown — name is.
            $table->string('code', 64)->nullable();

            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['make_id', 'name'], 'pos_device_models_make_name_unique');
        });
    }

    public function down(): void
    {
        // Models first (FK into makes).
        Schema::dropIfExists('pos_device_models');
        Schema::dropIfExists('pos_device_makes');
    }
};
