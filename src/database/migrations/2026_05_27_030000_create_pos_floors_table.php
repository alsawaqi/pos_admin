<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `pos_floors` — a logical area within a branch
 * (e.g. "Main Hall", "Patio", "VIP Section", "Drive-Thru
 * Lane"). Each floor groups tables for the POS device's
 * table-picker UI and for kitchen-display routing.
 *
 * Phase 5 — flat-list floor planner. Floors have an explicit
 * display_order so the merchant can pin the most-used area
 * (usually the main dining hall) to the top. Drag-and-drop
 * positioning of tables WITHIN a floor is deferred to a
 * future Phase 5.5 — those (x,y) coords would live on
 * pos_tables once we ship the visual planner.
 *
 * Why scope to branch (not just company):
 *   Floors belong to a physical location. A merchant with
 *   3 branches has 3 distinct "Main Hall" rows — one per
 *   branch — not one shared across all locations. The
 *   (branch_id, name) unique index keeps the merchant from
 *   accidentally duplicating a floor name within the same
 *   branch.
 *
 * Soft delete + cascade-on-branch-delete:
 *   If pos_admin retires a branch, every floor under it
 *   cascades out (and pos_tables cascade from those floors).
 *   We keep soft-delete on individual floors so a merchant
 *   tidying up doesn't break historical order joins that
 *   reference floor_id — withTrashed() resolves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_floors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // company_id denormalised so any tenant-scoped query
            // doesn't need to join through pos_branches. Cheap
            // and saves an index on the hot read path.
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');

            $table->timestamps();
            $table->softDeletes();

            // Two floors at the SAME branch can't share a name —
            // that would make the POS device's floor picker
            // ambiguous. Different branches can each have a
            // "Main Hall".
            $table->unique(['branch_id', 'name'], 'pos_floors_branch_name_unique');
            $table->index(['branch_id', 'status'], 'pos_floors_branch_status_idx');
            $table->index(['company_id', 'status'], 'pos_floors_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_floors');
    }
};
