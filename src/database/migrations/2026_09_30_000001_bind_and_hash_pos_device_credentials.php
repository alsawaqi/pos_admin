<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->unsignedBigInteger('token_company_id')->nullable();
            $table->unsignedBigInteger('token_branch_id')->nullable();
            $table->unsignedInteger('pending_outbox_count')->nullable();
            $table->unsignedInteger('quarantined_count')->default(0);
            $table->timestamp('outbox_reported_at')->nullable();
            $table->string('printer_status', 100)->nullable();
        });
        // Existing releases continue to send the same raw credential. SHA-256
        // is one-way; no raw credential or reversible backup is retained.
        DB::table('pos_devices')->whereNotNull('device_token')->orderBy('id')
            ->chunkById(200, function ($devices): void {
                foreach ($devices as $device) {
                    DB::table('pos_devices')->where('id', $device->id)->update([
                        'device_token' => hash('sha256', $device->device_token),
                        'token_company_id' => $device->company_id,
                        'token_branch_id' => $device->branch_id,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Hashes cannot be reversed. Rollback deliberately revokes credentials;
        // reverting the server requires new activation codes for every device.
        DB::table('pos_devices')->update(['device_token' => null]);
        DB::table('pos_devices')->where('status', 'active')->update(['status' => 'assigned']);
        DB::table('pos_device_activation_tokens')->whereNull('used_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropColumn(['token_company_id', 'token_branch_id', 'pending_outbox_count',
                'quarantined_count', 'outbox_reported_at', 'printer_status']);
        });
    }
};
