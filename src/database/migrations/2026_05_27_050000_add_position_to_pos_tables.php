<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.5 — visual floor planner: add position + size to
 * pos_tables.
 *
 *   position_x / position_y — pixel offset from the floor
 *                             canvas origin (top-left = 0,0).
 *                             NULL = "not placed yet"; the
 *                             planner UI auto-arranges these
 *                             in a grid until the merchant
 *                             drags them.
 *   width / height         — visual size in pixels. NULL =
 *                             "use the shape's default" (the
 *                             frontend derives a sensible
 *                             default per shape: round=80,
 *                             square=80, rectangle=120x60,
 *                             oval=100x70, counter=160x40).
 *                             Stored so a merchant who
 *                             resizes a specific table
 *                             persists their choice.
 *
 * All four columns are nullable unsignedSmallInteger
 * (0..65535). 65535 px is wildly larger than any sane
 * floor canvas (typical canvas is ~1200x800) so this leaves
 * room for ultra-wide branches without ever needing to grow
 * the column.
 *
 * No data-loss risk on existing rows — they default to NULL
 * and the planner treats NULL as "auto-arrange". The list
 * view (Phase 5) doesn't read these columns at all.
 *
 * No new index. Position columns are read only when the
 * floor's tables are loaded (already indexed via floor_id);
 * we never WHERE on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_tables', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position_x')->nullable()->after('display_order');
            $table->unsignedSmallInteger('position_y')->nullable()->after('position_x');
            $table->unsignedSmallInteger('width')->nullable()->after('position_y');
            $table->unsignedSmallInteger('height')->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('pos_tables', function (Blueprint $table): void {
            $table->dropColumn(['position_x', 'position_y', 'width', 'height']);
        });
    }
};
