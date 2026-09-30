<?php

use App\Services\P0SyncHistoryRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately sorts BEFORE the six immutable P0 migrations on a fresh
        // deployment. Laravel also runs this new pending file on upgraded DBs.
        if (! Schema::hasTable('pos_p0_sync_history')) {
            Schema::create('pos_p0_sync_history', function (Blueprint $t): void {
                $t->unsignedBigInteger('sync_event_id')->primary();
                $t->string('fingerprint', 64);
                $t->string('ack_status', 32);
                $t->jsonb('result_json')->nullable();
                $t->string('source');
                $t->timestamp('created_at');
            });
        }
        app(P0SyncHistoryRepair::class)->preserve();
    }

    public function down(): void
    {
        // Retain recovery evidence through rollback.
    }
};
