<?php

use App\Services\P0SyncHistoryRepair;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_p0_token_revocations')) {
            Schema::create('pos_p0_token_revocations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('device_id');
                $t->string('reason');
                $t->timestamp('created_at');
            });
        }
        app(P0SyncHistoryRepair::class)->repair();
        DB::table('pos_devices')->whereNotNull('device_token')->orderBy('id')->chunkById(200, function ($devices): void {
            foreach ($devices as $device) {
                $issued = DB::table('pos_device_activation_tokens')->where('device_id', $device->id)->max('used_at');
                $valid = $device->status === 'active' && $device->deleted_at === null
                    && $device->company_id !== null && $device->branch_id !== null
                    && $issued !== null && $device->assigned_at !== null
                    && Carbon::parse($issued)->gte(Carbon::parse($device->assigned_at))
                    && DB::table('pos_branches')->where('id', $device->branch_id)->where('company_id', $device->company_id)->exists();
                if ($valid) {
                    DB::table('pos_devices')->where('id', $device->id)->update([
                        'token_company_id' => $device->company_id, 'token_branch_id' => $device->branch_id,
                        'token_issued_at' => $issued,
                    ]);
                } else {
                    DB::table('pos_p0_token_revocations')->insert([
                        'device_id' => $device->id, 'reason' => 'No active activation at or after the current assignment',
                        'created_at' => now(),
                    ]);
                    DB::table('pos_devices')->where('id', $device->id)->update([
                        'device_token' => null, 'token_company_id' => null, 'token_branch_id' => null,
                        'token_issued_at' => null,
                        'status' => $device->status === 'active' ? 'assigned' : $device->status,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Never revive revoked credentials or erase historical attribution.
    }
};
