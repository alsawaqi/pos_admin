<?php

use App\Services\P0SyncHistoryRepair;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

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
        if (! Schema::hasColumn('pos_devices', 'assignment_activated_at')) {
            Schema::table('pos_devices', fn (Blueprint $t) => $t->timestamp('assignment_activated_at')->nullable());
        }
        app(P0SyncHistoryRepair::class)->repair();
        DB::table('pos_devices')->whereNotNull('device_token')->orderBy('id')->chunkById(200, function ($devices): void {
            foreach ($devices as $device) {
                $history = app(P0SyncHistoryRepair::class)->currentAssignment($device);
                $activations = DB::table('pos_device_activation_tokens')->where('device_id', $device->id)
                    ->whereNotNull('used_at')->orderBy('used_at')->pluck('used_at');
                $issued = $activations->last();
                $start = $history?->assigned_at;
                // assigned_at alone is not evidence of a move: main also bumped
                // it for a bank/terminal/PIN edit. Require the historical identity.
                $activatedAssignment = $issued === null ? null : app(P0SyncHistoryRepair::class)
                    ->assignment((object) ['device_id' => $device->id, 'server_received_at' => $issued], false);
                $moved = $activatedAssignment !== null
                    && ((int) $activatedAssignment->company_id !== (int) $device->company_id
                        || (int) $activatedAssignment->branch_id !== (int) $device->branch_id);
                // Moving away and back requires an intervening different identity.
                // A timestamp gap or missing early history alone proves no move.
                $moved = $moved || ($start !== null && $issued !== null
                    && Carbon::parse($issued)->lt(Carbon::parse($start))
                    && DB::table('pos_device_assignments_history')->where('device_id', $device->id)
                        ->whereNotNull('company_id')->whereNotNull('branch_id')
                        ->where('assigned_at', '<', $start)->where('unassigned_at', '>', $issued)
                        ->where(fn ($q) => $q->where('company_id', '<>', $device->company_id)
                            ->orWhere('branch_id', '<>', $device->branch_id))->exists());
                $valid = ! $moved && $device->status === 'active' && $device->deleted_at === null
                    && $device->company_id !== null && $device->branch_id !== null
                    && DB::table('pos_branches')->where('id', $device->branch_id)->where('company_id', $device->company_id)->exists();
                $first = $start === null ? $device->token_issued_at
                    : $activations->first(fn ($at) => Carbon::parse($at)->gte(Carbon::parse($start)));
                if ($valid) {
                    DB::table('pos_devices')->where('id', $device->id)->update([
                        'token_company_id' => $device->company_id, 'token_branch_id' => $device->branch_id,
                        'token_issued_at' => $first, 'assignment_activated_at' => $first,
                    ]);
                } else {
                    DB::table('pos_p0_token_revocations')->insert([
                        'device_id' => $device->id, 'reason' => 'Proven assignment move or inactive/invalid device',
                        'created_at' => now(),
                    ]);
                    DB::table('pos_devices')->where('id', $device->id)->update([
                        'device_token' => null, 'token_company_id' => null, 'token_branch_id' => null,
                        'token_issued_at' => null, 'assignment_activated_at' => null,
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
