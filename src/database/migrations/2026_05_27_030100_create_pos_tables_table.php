<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `pos_tables` — the actual physical tables (or
 * counter seats, or delivery slots) within a floor. Phase 5.
 *
 * Important: the table NAME column is reserved by Postgres in
 * some contexts, but `pos_tables` is fine as a relation name
 * because Laravel quotes it. The column we use for the
 * human-readable id is `label` (e.g. "T1", "VIP-3",
 * "Counter-A") so it doesn't collide with the SQL keyword.
 *
 * Column shape locks in the answers from Phase 5 scope:
 *
 *   seats        — typical capacity (used by POS to suggest
 *                  the right table for a party).
 *   min_party    — refuse seating below this (e.g. don't
 *                  sit a 2-person party at a 10-top during
 *                  a busy night). NULL = no minimum.
 *   max_party    — refuse seating above this. NULL = no max.
 *   shape        — round / square / rectangle / oval /
 *                  counter. Cosmetic now; Phase 5.5 floor
 *                  planner will use it to render shapes.
 *   qr_token     — unique token for scan-to-order. Each
 *                  table gets a stable token at create time;
 *                  the customer-facing menu URL is built as
 *                  /menu?t={qr_token} so it never leaks the
 *                  internal table id. Regenerable via the
 *                  RegenerateTableQrAction (e.g. if the
 *                  printed QR card is stolen / lost).
 *   notes        — free-text for waiters ("by window",
 *                  "wheelchair accessible", "booth", etc.)
 *   status       — active / inactive. inactive = staff can
 *                  see it on the roster but new orders can't
 *                  open against it.
 *   display_order — manual reorder within a floor for the
 *                   POS device picker.
 *
 * No (x,y) coords yet — flat-list planner is Phase 5; visual
 * drag-and-drop ships in Phase 5.5.
 *
 * Cascade-on-floor-delete: removing a floor wipes its tables.
 * Soft delete kept on individual tables so historical order
 * joins (orders.table_id) still resolve via withTrashed().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_tables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            $table->foreignId('floor_id')
                ->constrained('pos_floors')
                ->cascadeOnDelete();

            // The human-readable id printed on the table itself.
            $table->string('label', 32);
            $table->unsignedSmallInteger('seats')->default(4);
            $table->unsignedSmallInteger('min_party')->nullable();
            $table->unsignedSmallInteger('max_party')->nullable();

            // Free-form string (not an enum at the DB level) so
            // adding "booth" or "high-top" in a future phase
            // doesn't need a migration. Application enum
            // {@see \App\Enums\TableShape} restricts inputs.
            $table->string('shape', 24)->default('square');

            $table->text('notes')->nullable();

            // 24-char URL-safe random token. Globally unique
            // across all merchants so a stolen card from one
            // restaurant can't accidentally land on another's
            // menu. Application generator: Str::random(24).
            $table->string('qr_token', 64)->unique();

            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('display_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Two tables on the SAME floor can't share a label
            // — the POS picker needs unambiguous identification.
            // Different floors can each have a "T1".
            $table->unique(['floor_id', 'label'], 'pos_tables_floor_label_unique');
            $table->index(['floor_id', 'status'], 'pos_tables_floor_status_idx');
            $table->index(['company_id', 'status'], 'pos_tables_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_tables');
    }
};
