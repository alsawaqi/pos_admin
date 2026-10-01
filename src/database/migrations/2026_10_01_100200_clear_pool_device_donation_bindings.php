<?php

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Device;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P1 B1 / P1-9 — the round-up commission profile and beneficiary
 * organization are chosen at ASSIGN, with the merchant and branch, and are
 * cleared when a device is unassigned or moved, so they never follow a device
 * to another merchant.
 *
 * Currently assigned devices keep their values. Devices in the unassigned pool
 * lose them now (they would otherwise travel to the next merchant); each
 * cleared pair is kept in the audit log as `device.donation_bindings_cleared`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $pool = DB::table('pos_devices')
                ->where(fn ($q) => $q->whereNull('company_id')->orWhereNull('branch_id'))
                ->where(fn ($q) => $q->whereNotNull('commission_profile_id')->orWhereNotNull('organization_id'))
                ->orderBy('id')->get(['id', 'commission_profile_id', 'organization_id']);
            foreach ($pool as $row) {
                DB::table('pos_devices')->where('id', $row->id)
                    ->update(['commission_profile_id' => null, 'organization_id' => null]);
                app(WriteAuditLogAction::class)->handle(new AuditLogData(
                    event: 'device.donation_bindings_cleared',
                    auditableType: Device::class,
                    auditableId: (int) $row->id,
                    oldValues: ['commission_profile_id' => $row->commission_profile_id, 'organization_id' => $row->organization_id],
                    newValues: ['commission_profile_id' => null, 'organization_id' => null],
                    metadata: ['reason' => 'LAUNCH-P1: chosen at assignment; unassigned devices carry none'],
                ));
            }
        });
    }

    public function down(): void
    {
        // Not reversible here; the cleared values are in the audit log.
    }
};
