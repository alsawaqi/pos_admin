<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D3 (blueprint §5.7.2 Customer Detail) — customer tags +
 * date of birth.
 *
 * Two additive columns on pos_customers:
 *
 *   date_of_birth — optional, blueprint: "date of birth (optional)".
 *     Powers the merchant CRM's "birthday soon" indicator; no time
 *     component, timezone-naive by design.
 *
 *   tags_json — free-form per-company tag strings (e.g. VIP,
 *     Blocked). The blueprint's data dictionary (§10.6) models
 *     this as a JSON column on customers — NOT a pivot — matching
 *     the codebase's existing _json columns (opening_hours_json,
 *     branch_scope_json, config_json). NULL ≡ no tags. The future
 *     loyalty "customer tag requirement (VIP only)" restriction
 *     (§5.8.1) matches by string inside the rule's config_json,
 *     so no FK consumer exists.
 *
 * No backfill needed — both columns are nullable and existing rows
 * simply have neither. The merchant app's Actions own validation +
 * normalisation (trim, dedupe); the device config slice does NOT
 * carry these fields (the POS uses customers only for attach-to-
 * order / loyalty / wallet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_customers', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->json('tags_json')->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('pos_customers', function (Blueprint $table): void {
            $table->dropColumn(['date_of_birth', 'tags_json']);
        });
    }
};
