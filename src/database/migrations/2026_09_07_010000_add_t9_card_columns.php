<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_qr_session_scans', function (Blueprint $table): void {
            $table->string('outcome', 24)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
        });

        $this->dropPartialIndexForSqlite();
        Schema::table('pos_qr_sessions', function (Blueprint $table): void {
            $table->timestamp('released_at')->nullable();
            $table->foreignId('handover_from_id')->nullable()->constrained('pos_qr_sessions')->nullOnDelete();
            $table->index('released_at', 'pos_qr_sessions_released_idx');
        });
        $this->restorePartialIndexForSqlite();
    }

    public function down(): void
    {
        $this->dropPartialIndexForSqlite();
        Schema::table('pos_qr_sessions', function (Blueprint $table): void {
            $table->dropIndex('pos_qr_sessions_released_idx');
            $table->dropForeign(['handover_from_id']);
            $table->dropColumn(['released_at', 'handover_from_id']);
        });
        $this->restorePartialIndexForSqlite();

        Schema::table('pos_qr_session_scans', function (Blueprint $table): void {
            $table->dropColumn(['outcome', 'accuracy_m', 'distance_m']);
        });
    }

    private function dropPartialIndexForSqlite(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // Adding/removing the self-FK rebuilds SQLite's table; Laravel
            // otherwise recreates this unique index without its predicate.
            DB::statement('DROP INDEX IF EXISTS pos_qr_sessions_table_live_unique');
        }
    }

    private function restorePartialIndexForSqlite(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX pos_qr_sessions_table_live_unique
                 ON pos_qr_sessions (table_id)
                 WHERE table_id IS NOT NULL
                   AND status IN ('pending', 'active', 'ordered')"
            );
        }
    }
};
