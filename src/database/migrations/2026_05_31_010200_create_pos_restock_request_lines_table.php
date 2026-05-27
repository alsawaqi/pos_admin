<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5c — Restock request line items.
 *
 * One row per (request, ingredient) — the things the branch
 * wants restocked. Composite-unique on (restock_request_id,
 * ingredient_id) keeps the same ingredient from appearing
 * twice in one request (the UI should sum them; the server
 * rejects duplicates).
 *
 * quantity_allocated starts at 0 and is set when the parent
 * request is allocated:
 *   - 0      = nothing sent for this line (e.g. partial fulfill
 *               where HQ chose to skip this one)
 *   - n      = n of the unit was actually delivered
 *   - equals quantity_requested = exact fulfillment
 *
 * unit_at_set is denormalised from the ingredient at request-
 * creation time so the request stays meaningful even if the
 * ingredient's unit changes later (Phase 5a blocks unit changes
 * once history exists, but defensive).
 *
 * Cascade-on-request-delete: deleting a request (which only
 * happens on hard-delete; status=cancelled is the usual path)
 * wipes its lines. Cascade-on-ingredient-delete keeps the
 * referential integrity tight — but Phase 5b's recipe guard
 * also blocks ingredient deletion when in any active recipe,
 * and we should consider adding a similar guard for requests
 * that are still draft/submitted/approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_restock_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restock_request_id')
                ->constrained('pos_restock_requests')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('pos_ingredients')
                ->cascadeOnDelete();
            $table->decimal('quantity_requested', 12, 3);
            $table->decimal('quantity_allocated', 12, 3)->default(0);
            // Denormalised from the ingredient at request time —
            // see class doc.
            $table->string('unit_at_set', 16);
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(
                ['restock_request_id', 'ingredient_id'],
                'pos_restock_request_lines_request_ingredient_unique',
            );
            $table->index(['ingredient_id'], 'pos_restock_request_lines_ingredient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_restock_request_lines');
    }
};
