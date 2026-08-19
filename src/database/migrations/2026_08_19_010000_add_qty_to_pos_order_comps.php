<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMP-001 — quantity-based line comps.
 *
 * Plain nullable decimal add => SQLite-safe (pos_admin's test suite runs
 * these real migrations on sqlite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_comps', function (Blueprint $table): void {
            $table->decimal('qty', 12, 3)->nullable()->after('is_gift');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_comps', function (Blueprint $table): void {
            $table->dropColumn('qty');
        });
    }
};
