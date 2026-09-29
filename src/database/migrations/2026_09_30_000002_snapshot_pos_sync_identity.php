<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sync_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
        });
        // Historical rows have no provable receipt identity. Do not invent one
        // from the device's current assignment or replay them automatically.
        DB::table('pos_sync_events')->whereIn('ack_status', ['received', 'failed'])->update([
            'ack_status' => 'needs_review',
            'result_json' => json_encode(['error' => 'identity_mismatch', 'permanent' => true, 'reason' => 'Historical receipt identity unavailable']),
        ]);
    }

    public function down(): void
    {
        // Historical quarantine is retained; rollback must not restart old work.
        Schema::table('pos_sync_events', fn (Blueprint $table) => $table->dropColumn(['company_id', 'branch_id']));
    }
};
