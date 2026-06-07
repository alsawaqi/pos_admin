<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `pos_settings` — a generic key/value store for
 * platform-level configuration the admin can edit at runtime
 * (without touching `.env`).
 *
 * Why a generic table instead of typed columns:
 *   The settings catalogue grows over time (more Phase-X features
 *   add knobs). A typed table would require a migration every
 *   time. With key/value we just seed a new row.
 *
 * Why JSON for `value`:
 *   Storing everything as strings would force every consumer to
 *   parse back into the right type. JSONB lets us keep ints, bools,
 *   arrays (e.g. supported_locales = ["en","ar"]), and objects
 *   without an encoding dance.
 *
 * Why a per-row `type` column:
 *   The UI needs to know whether to render a text input, number,
 *   boolean toggle, select, multi-select, datetime, or textarea.
 *   The TypeScript side maps `type` → component.
 *
 * Why `group_key`:
 *   The Settings page is tabbed (General / Localization / Merchant
 *   defaults / Notifications / Maintenance banner). Group lets us
 *   render each tab from a single query rather than hardcoding the
 *   mapping client-side.
 *
 * Out of scope on purpose: auth-sensitive settings (idle timeout,
 * JWT TTL, login rate limit) stay env-only — pushing them to a
 * DB-editable UI invites lock-out (e.g. setting idle to 1 second
 * by accident).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_settings', function (Blueprint $table): void {
            $table->id();
            // Globally-unique key. Convention: "section.field_name"
            // — e.g. "general.support_email", "branner.start_at".
            $table->string('key')->unique();
            // The actual setting payload. JSONB on Postgres for
            // efficient indexed lookups; the SQLite test layer
            // uses TEXT under the hood + Eloquent's `array` cast
            // handles serialisation either way.
            $table->json('value')->nullable();
            // UI hint. Mirrors the Setting::TYPE_* constants.
            // string|integer|boolean|select|multiselect|datetime|textarea
            $table->string('type', 32);
            // Tab grouping for the Settings page.
            $table->string('group_key', 64)->index();
            // Bilingual labels + help text shown in the UI. Stored
            // on the row instead of via i18n keys so the admin can
            // see exactly what they're editing without a frontend
            // round-trip to a translation file.
            $table->string('label_en');
            $table->string('label_ar')->nullable();
            $table->text('help_en')->nullable();
            $table->text('help_ar')->nullable();
            // For select/multiselect types: the choice catalogue.
            // Shape: [{value, label_en, label_ar}, ...]
            $table->json('options')->nullable();
            // Display order within the group.
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['group_key', 'display_order'], 'pos_settings_group_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_settings');
    }
};
