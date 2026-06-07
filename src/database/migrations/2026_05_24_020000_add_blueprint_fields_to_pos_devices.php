<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the `pos_devices` table up to the MITHQAL 2.0 spec (blueprint
 * §4.4 / §10.3) and introduces the immutable assignment-history table
 * the platform admin uses to audit every (de)assignment.
 *
 * Why each new column matters:
 *
 *   kiosk_id          — the scalefusion kiosk identifier. scalefusion
 *                       owns device enrolment and the MITHQAL POS app
 *                       reads this id at first boot to pair with the
 *                       backend (blueprint §6.1). Without it no real
 *                       device can ever exchange its activation token
 *                       for the long-lived device_token. Unique.
 *
 *   model             — hardware model auto-fetched from scalefusion
 *                       (e.g. "Sunmi P2 Mini"). Drives layout choices
 *                       on the POS app (dual-screen terminals get a
 *                       customer-facing display, handhelds don't).
 *
 *   label             — internal admin-only label like "POS-001" or
 *                       "HAND-A03" so support can find the device in
 *                       the field. Free text.
 *
 *   device_token      — long-lived per-device API token (NOT a Sanctum
 *                       personal access token because the POS family
 *                       lives outside the user/portal guards). Issued
 *                       after the one-time activation token is redeemed.
 *
 *   last_lat / lng    — the GPS coordinates the device last reported on
 *                       its heartbeat. The geo-fence middleware (§9.4)
 *                       compares these against the branch coordinates +
 *                       configured radius on every order/payment call.
 *
 *   last_battery      — 0–100 battery level surfaced on the Admin
 *                       dashboard "low battery" tile (§4.8).
 *
 * Why the *_history table:
 *
 *   The blueprint mandates a full audit trail of who assigned a device
 *   to which branch and when (§4.4.3 + §9.7.1). A single row per device
 *   is not enough — when admin reassigns a device, the previous
 *   assignment must remain queryable. This table is append-only:
 *   assignment opens a new row, unassignment stamps `unassigned_at` on
 *   the open row. The Device.assignment_history relation surfaces it
 *   on the Device Detail page.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Extend pos_devices with the blueprint columns ------------
        Schema::table('pos_devices', function (Blueprint $table): void {
            // scalefusion kiosk id. Nullable initially so existing rows
            // (factory-created during prior tests) survive the migration;
            // production rows will always carry one via the device
            // registration form. Unique when present.
            $table->string('kiosk_id')->nullable()->after('serial_number');
            $table->unique('kiosk_id', 'pos_devices_kiosk_id_unique');

            // Hardware model + admin label. Both free text.
            $table->string('model')->nullable()->after('device_type');
            $table->string('label')->nullable()->after('model');

            // Long-lived per-device API token (hashed at rest like
            // Sanctum tokens — never stored in plaintext). Sized 80 for
            // future-proofing against larger token formats.
            $table->string('device_token', 80)->nullable()->unique('pos_devices_device_token_unique')->after('label');

            // Heartbeat-reported GPS + battery. Indexed on last_seen_at
            // already, no extra index needed here (geo-fence checks are
            // per-device, so primary-key lookup is enough).
            $table->decimal('last_lat', 10, 7)->nullable()->after('last_seen_at');
            $table->decimal('last_lng', 10, 7)->nullable()->after('last_lat');
            $table->unsignedTinyInteger('last_battery')->nullable()->after('last_lng');
        });

        // --- 2. Migrate the legacy device_type value --------------------
        // The original scaffolding defaulted to 'pos_terminal'. The
        // blueprint vocabulary is 'fixed_pos' / 'handheld' /
        // 'customer_tablet' (see DeviceType enum). Rewrite any existing
        // rows so the enum cast won't blow up when re-reading them.
        DB::table('pos_devices')
            ->where('device_type', 'pos_terminal')
            ->update(['device_type' => 'fixed_pos']);

        // --- 3. Create the append-only assignments history table --------
        Schema::create('pos_device_assignments_history', function (Blueprint $table): void {
            $table->id();

            // Which device, where it was sent, and who triggered it.
            // Cascading delete on the device because a soft-deleted
            // device is removed from the live system but its history
            // chain can be archived together with the parent row.
            $table->foreignId('device_id')
                ->constrained('pos_devices')
                ->cascadeOnDelete();

            // Tenant + branch the device was bound to. Both nullable
            // ONLY in the edge case of a partial assignment where the
            // admin set company first then aborted — normal flow always
            // writes both. Kept as foreign keys so the report can join
            // safely without orphan refs.
            $table->foreignId('company_id')
                ->nullable()
                ->constrained('pos_companies')
                ->nullOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('pos_branches')
                ->nullOnDelete();

            // When the assignment opened. When it closed (NULL while
            // still active — see Device Detail's "current assignment").
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('unassigned_at')->nullable();

            // Which platform admin triggered the action. Pos_users
            // because admin accounts live there; nullOnDelete so we
            // don't accidentally lose history when an admin is removed.
            $table->foreignId('assigned_by_admin_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();

            // Optional unassign reason for the audit trail
            // ("device damaged", "branch closed", ...).
            $table->string('unassign_reason')->nullable();

            // Created_at only — this table is INSERT-then-UPDATE-once
            // (only `unassigned_at` + `unassign_reason` ever mutate),
            // an updated_at would be misleading.
            $table->timestamp('created_at')->useCurrent();

            // The two index patterns we actually query:
            //   1. "show me everything for this device" — Device Detail
            //   2. "show me the OPEN assignment for this device" — read
            //      paths that need the current company/branch context.
            $table->index(['device_id', 'assigned_at'], 'pos_device_assignments_history_device_index');
            $table->index(['device_id', 'unassigned_at'], 'pos_device_assignments_history_open_index');
        });
    }

    public function down(): void
    {
        // Drop the history table first because it FKs into pos_devices.
        Schema::dropIfExists('pos_device_assignments_history');

        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropUnique('pos_devices_kiosk_id_unique');
            $table->dropUnique('pos_devices_device_token_unique');

            $table->dropColumn([
                'kiosk_id',
                'model',
                'label',
                'device_token',
                'last_lat',
                'last_lng',
                'last_battery',
            ]);
        });

        // Roll back the data migration too so re-running up()->down()->up()
        // ends in the same state we started.
        DB::table('pos_devices')
            ->where('device_type', 'fixed_pos')
            ->update(['device_type' => 'pos_terminal']);
    }
};
