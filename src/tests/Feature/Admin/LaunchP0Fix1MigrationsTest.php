<?php

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function fix1ApplyMigration(string $name): void
{
    // Run the migration set shipped by the candidate under test. The old
    // candidate has no corrective migrations; that is the real before state.
    $path = database_path('migrations/'.$name.'.php');
    if (is_file($path)) {
        (require $path)->up();
    }
}
function fix1MigrationDevice(array $extra = []): Device
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    return Device::factory()->create(array_merge([
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'status' => 'active', 'assigned_at' => now()->subDays(2),
        'device_token' => hash('sha256', Str::random()),
        'token_company_id' => $company->id, 'token_branch_id' => $branch->id,
    ], $extra));
}
function fix1SyncRow(Device $device, string $status = 'failed'): int
{
    return DB::table('pos_sync_events')->insertGetId([
        'device_id' => $device->id, 'client_event_id' => Str::uuid(),
        'event_type' => 'expense.log', 'payload_json' => '{"amount":3}',
        'client_timestamp' => now()->subDay(), 'server_received_at' => now()->subDay(),
        'ack_status' => $status, 'result_json' => '{"error":"retryable original"}',
    ]);
}

it('fix1 B6 preserves and restores continuous history while ambiguous history stays reviewable', function () {
    $device = fix1MigrationDevice();
    DB::table('pos_device_assignments_history')->insert([
        'device_id' => $device->id, 'company_id' => $device->company_id,
        'branch_id' => $device->branch_id, 'assigned_at' => now()->subDays(2),
    ]);
    $old = require database_path('migrations/2026_09_30_000002_snapshot_pos_sync_identity.php');
    $old->down();
    $failed = fix1SyncRow($device);
    $received = fix1SyncRow($device, 'received');
    $unknown = fix1SyncRow(fix1MigrationDevice());
    fix1ApplyMigration('2026_09_30_000000_preserve_pos_sync_history');
    $old->up();
    fix1ApplyMigration('2026_09_30_000008_repair_pos_p0_history_and_bindings');
    foreach ([$failed => 'failed', $received => 'received'] as $id => $status) {
        $row = DB::table('pos_sync_events')->find($id);
        expect($row->ack_status)->toBe($status)
            ->and((int) $row->company_id)->toBe($device->company_id)
            ->and((int) $row->branch_id)->toBe($device->branch_id)
            ->and(json_decode($row->result_json, true))->toBe(['error' => 'retryable original']);
    }
    expect(DB::table('pos_sync_events')->find($unknown)->ack_status)->toBe('needs_review');
});

it('fix1 B6 revokes inactive and pre-assignment tokens but retains a proven current activation', function () {
    $good = fix1MigrationDevice();
    $stale = fix1MigrationDevice();
    $inactive = fix1MigrationDevice(['status' => 'inactive']);
    $unknown = fix1MigrationDevice();
    foreach ([$good, $inactive, $stale] as $device) {
        DeviceActivationToken::factory()->create(['device_id' => $device->id,
            'used_at' => $device->id === $stale->id ? now()->subDays(3) : now()->subDay()]);
    }
    fix1ApplyMigration('2026_09_30_000008_repair_pos_p0_history_and_bindings');
    expect($good->refresh()->device_token)->not->toBeNull()
        ->and($good->getAttribute('token_issued_at'))->not->toBeNull();
    foreach ([$stale, $inactive, $unknown] as $device) {
        expect($device->refresh()->device_token)->toBeNull()
            ->and($device->token_company_id)->toBeNull()->and($device->token_branch_id)->toBeNull();
    }
});

it('fix1 backfills live table-card hashes without changing closed sessions', function () {
    $device = fix1MigrationDevice();
    $floor = DB::table('pos_floors')->insertGetId(['uuid' => Str::uuid(),
        'company_id' => $device->company_id, 'branch_id' => $device->branch_id, 'name' => 'Test']);
    $table = DB::table('pos_tables')->insertGetId(['uuid' => Str::uuid(),
        'company_id' => $device->company_id, 'floor_id' => $floor, 'label' => 'T1', 'qr_token' => 'test-card']);
    $id = DB::table('pos_qr_sessions')->insertGetId([
        'uuid' => Str::uuid(), 'token' => Str::random(48), 'company_id' => $device->company_id,
        'branch_id' => $device->branch_id, 'table_id' => $table, 'origin' => 'table_card',
        'status' => 'active', 'token_expires_at' => now()->addHour(), 'expires_at' => now()->addHour(),
    ]);
    fix1ApplyMigration('2026_09_30_000009_backfill_pos_card_hashes_and_terminal_history');
    expect(DB::table('pos_qr_sessions')->find($id)->table_qr_token_hash)->toBe(hash('sha256', 'test-card'));
});

it('fix1 reserves historical payment terminals using the orders merchant', function () {
    $device = fix1MigrationDevice();
    $order = DB::table('pos_orders')->insertGetId(['uuid' => Str::uuid(),
        'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => 5, 'grand_total' => 5, 'opened_at' => now()]);
    $bank = DB::table('banks')->insertGetId(['name' => 'Test', 'short_name' => 'T']);
    DB::table('pos_payments')->insert(['uuid' => Str::uuid(), 'order_id' => $order,
        'method' => 'card', 'amount' => 5, 'bank_id' => $bank, 'terminal_id' => ' historic 01 ']);
    fix1ApplyMigration('2026_09_30_000009_backfill_pos_card_hashes_and_terminal_history');
    $this->assertDatabaseHas('pos_terminal_reservations', ['bank_id' => $bank,
        'terminal_id' => 'HISTORIC01', 'company_id' => $device->company_id]);
});

it('fix1 B6 imports original backup results with fingerprint checks and audits every repair', function () {
    $this->seed(PlatformRoleSeeder::class);
    $actor = User::factory()->create();
    $actor->assignRole(PlatformRole::SuperAdmin->value);
    $device = fix1MigrationDevice();
    DB::table('pos_device_assignments_history')->insert(['device_id' => $device->id,
        'company_id' => $device->company_id, 'branch_id' => $device->branch_id, 'assigned_at' => now()->subDays(2)]);
    $id = fix1SyncRow($device);
    $backup = DB::table('pos_sync_events')->find($id);
    DB::table('pos_sync_events')->where('id', $id)->update(['ack_status' => 'needs_review',
        'result_json' => '{"error":"identity_mismatch"}']);
    $file = tempnam(sys_get_temp_dir(), 'p0-backup-');
    file_put_contents($file, json_encode($backup)."\n");
    try {
        $args = ['--file' => $file, '--actor' => $actor->id, '--reason' => 'Verified local pre-P0 backup'];
        $this->artisan('pos:repair-sync-history', ['operation' => 'import'] + $args)->assertSuccessful();
        $this->artisan('pos:repair-sync-history', ['operation' => 'repair'] + $args)->assertSuccessful();
        expect(DB::table('pos_sync_events')->find($id)->ack_status)->toBe('failed');
        expect(json_decode(DB::table('pos_sync_events')->find($id)->result_json, true))->toBe(['error' => 'retryable original']);
        $this->assertDatabaseHas('pos_audit_logs', ['event' => 'sync.history.import', 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('pos_audit_logs', ['event' => 'sync.history.repair']);
        $backup->payload_json = '{"amount":999}';
        file_put_contents($file, json_encode($backup)."\n");
        $this->artisan('pos:repair-sync-history', ['operation' => 'import'] + $args)->assertFailed();
        expect(DB::table('pos_p0_sync_history')->count())->toBe(1);
    } finally {
        unlink($file);
    }
});

it('fix1 B6 requires Super Admin evidence and preserves an immutable original snapshot for replay', function () {
    $this->seed(PlatformRoleSeeder::class);
    $actor = User::factory()->create();
    $device = fix1MigrationDevice();
    $id = fix1SyncRow($device, 'needs_review');
    $file = tempnam(sys_get_temp_dir(), 'p0-review-');
    file_put_contents($file, json_encode(['company_id' => $device->company_id,
        'branch_id' => $device->branch_id, 'bank_id' => null, 'terminal_id' => null,
        'commission_profile_id' => null, 'organization_id' => null, 'device_type' => 'pos_terminal']));
    try {
        $args = ['--event' => $id, '--file' => $file, '--actor' => $actor->id, '--reason' => 'Original assignment evidence'];
        $this->artisan('pos:repair-sync-history', ['operation' => 'attribute'] + $args)->assertFailed();
        $actor->assignRole(PlatformRole::SuperAdmin->value);
        $this->artisan('pos:repair-sync-history', ['operation' => 'attribute'] + $args)->assertSuccessful();
        $this->artisan('pos:repair-sync-history', ['operation' => 'attribute'] + $args)->assertFailed();
        $this->artisan('pos:repair-sync-history', ['operation' => 'replay'] + $args)->assertSuccessful();
        $this->assertDatabaseHas('pos_sync_event_reviews', ['sync_event_id' => $id, 'status' => 'queued',
            'company_id' => $device->company_id, 'branch_id' => $device->branch_id]);
        $this->assertDatabaseHas('pos_audit_logs', ['event' => 'sync.history.attribute', 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('pos_audit_logs', ['event' => 'sync.history.replay', 'actor_user_id' => $actor->id]);
    } finally {
        unlink($file);
    }
});
