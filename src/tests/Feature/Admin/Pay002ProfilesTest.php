<?php

declare(strict_types=1);

use App\Actions\Admin\RegisterDeviceAction;
use App\Data\Admin\RegisterDeviceData;
use App\Enums\DeviceType;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    $actor = User::factory()->create();
    $actor->assignRole(PlatformRole::DeviceOperations->value);
    $this->actingAs($actor);
    DB::table('banks')->insert([
        ['id' => 99101, 'name' => 'Dhofar fixture', 'is_active' => true],
        ['id' => 99102, 'name' => 'Muscat fixture', 'is_active' => true],
        ['id' => 99103, 'name' => 'Retired fixture', 'is_active' => false],
    ]);
});

it('lists every bank with a nullable profile and refuses actors without device control', function (): void {
    $this->getJson('/admin/api/v1/bank-softpos-profiles')
        ->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.profile', null)->assertJsonPath('data.2.is_active', false);
    $viewer = User::factory()->create();
    $viewer->assignRole(PlatformRole::Support->value);
    $this->actingAs($viewer);
    $this->getJson('/admin/api/v1/bank-softpos-profiles')->assertForbidden();
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', ['softpos_provider' => 'mosambee_dhofar'])->assertForbidden();
    expect(DB::table('pos_bank_softpos_profiles')->count())->toBe(0);
});

it('creates profiles with provider defaults without changing banks and audits edits', function (): void {
    $before = DB::table('banks')->get()->toJson();
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', ['softpos_provider' => 'mosambee_dhofar'])
        ->assertOk()->assertJsonPath('data.softpos_package', 'com.mosambee.dhofar.softpos')
        ->assertJsonPath('data.refund_needs_transaction_id', true)
        ->assertJsonPath('data.void_needs_session_id', false);
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99102', ['softpos_provider' => 'mosambee_muscat'])
        ->assertOk()->assertJsonPath('data.softpos_package', 'com.mosambee.muscat.softpos')
        ->assertJsonPath('data.refund_needs_transaction_id', false)
        ->assertJsonPath('data.void_needs_session_id', true);
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', [
        'softpos_provider' => 'mosambee_dhofar', 'notes' => 'Operator note', 'softpos_package' => 'com.example.bank',
    ])->assertOk()->assertJsonPath('data.softpos_package', 'com.example.bank');
    expect(DB::table('banks')->get()->toJson())->toBe($before);
    expect(AuditLog::where('event', 'bank_softpos_profile.created')->count())->toBe(2);
    expect(AuditLog::where('event', 'bank_softpos_profile.updated')->count())->toBe(1);
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', ['softpos_provider' => 'bank_name'])
        ->assertUnprocessable()->assertJsonValidationErrors('softpos_provider');
});

it('requires provider change confirmation naming assigned devices and bumps their config version', function (): void {
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', ['softpos_provider' => 'mosambee_dhofar'])->assertOk();
    $device = Device::factory()->create(['bank_id' => 99101, 'assigned_at' => now(), 'updated_at' => now()->subDay()]);
    $before = $device->updated_at->toISOString();
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', ['softpos_provider' => 'mosambee_muscat'])
        ->assertStatus(409)->assertJsonPath('code', 'softpos_provider_change_needs_confirmation')
        ->assertJsonPath('device_count', 1)->assertJsonPath('devices.0.uuid', $device->uuid);
    expect($device->fresh()->updated_at->toISOString())->toBe($before);
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', [
        'softpos_provider' => 'mosambee_muscat', 'confirm_provider_change' => true,
    ])->assertOk()->assertJsonPath('data.softpos_package', 'com.mosambee.muscat.softpos');
    expect($device->fresh()->updated_at->toISOString())->not->toBe($before);
    expect(AuditLog::where('event', 'bank_softpos_profile.provider_changed')->count())->toBe(1);
});

it('surfaces the device softpos contract and explicitly unblocks with an audit', function (): void {
    $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', ['softpos_provider' => 'mosambee_dhofar'])->assertOk();
    $device = Device::factory()->create(['bank_id' => 99101]);
    DB::table('pos_devices')->where('id', $device->id)->update([
        'card_tenders_blocked_reason' => 'softpos_mismatch', 'card_tenders_blocked_at' => now(),
    ]);
    $this->getJson("/admin/api/v1/devices/{$device->uuid}")
        ->assertOk()->assertJsonPath('data.softpos.provider', 'mosambee_dhofar')
        ->assertJsonPath('data.softpos.blocked_reason', 'softpos_mismatch');
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/unblock-card-tenders")
        ->assertOk()->assertJsonPath('data.softpos.blocked_reason', null);
    expect($device->fresh()->card_tenders_unblocked_at)->not->toBeNull();
    expect(AuditLog::where('event', 'device.card_tenders.unblocked')->count())->toBe(1);
});

it('refuses missing inactive and none bank profiles with no assignment writes', function (string $state): void {
    if ($state !== 'missing') {
        $this->putJson('/admin/api/v1/bank-softpos-profiles/99101', [
            'softpos_provider' => $state === 'none' ? 'none' : 'mosambee_dhofar',
            'is_active' => $state !== 'inactive',
        ])->assertOk();
    }
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->create(['device_type' => 'payment_station']);
    $before = $device->fresh()->getAttributes();
    $audits = AuditLog::count();
    $history = DB::table('pos_device_assignments_history')->count();
    $this->postJson("/admin/api/v1/devices/{$device->uuid}/assign", [
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'bank_id' => 99101, 'terminal_id' => 'PAY002-FIXTURE',
    ])->assertUnprocessable()->assertJsonValidationErrors('bank_id');
    expect($device->fresh()->getAttributes())->toBe($before);
    expect(AuditLog::count())->toBe($audits);
    expect(DB::table('pos_device_assignments_history')->count())->toBe($history);
})->with(['missing', 'inactive', 'none']);

it('guards immediate station registration and changing an assigned device into a station', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $before = $device->fresh()->getAttributes();
    $audits = AuditLog::count();
    $devices = Device::count();
    expect(fn () => app(RegisterDeviceAction::class)->handle(new RegisterDeviceData(
        serialNumber: 'PAY002-UNCONFIGURED', deviceType: DeviceType::PaymentStation,
        companyId: $company->id, branchId: $branch->id,
    )))->toThrow(ValidationException::class);
    $this->patchJson("/admin/api/v1/devices/{$device->uuid}", ['device_type' => 'payment_station'])
        ->assertUnprocessable()->assertJsonValidationErrors('bank_id');
    expect($device->fresh()->getAttributes())->toBe($before);
    expect(Device::count())->toBe($devices);
    expect(AuditLog::count())->toBe($audits);
});

it('lists only mismatched payments blocked devices and uncertain reversals behind device control', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'card_tenders_blocked_reason' => 'softpos_mismatch', 'card_tenders_blocked_at' => now(),
    ]);
    Device::factory()->create();
    $order = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => 5, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 5,
        'opened_at' => now(), 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payment = DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'order_id' => $order, 'method' => 'card',
        'amount' => 5, 'status' => 'success', 'softpos_mismatch' => true,
        'device_id' => $device->id, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $staff = DB::table('pos_staff')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'name' => 'Approving manager', 'pin_hash' => 'unused', 'position' => 'manager',
    ]);
    foreach (['uncertain', 'pending', 'approved', 'declined'] as $status) {
        DB::table('pos_payment_reversals')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
            'order_id' => $order, 'payment_id' => $payment, 'kind' => 'refund', 'amount' => 1,
            'amount_baisas' => 1000, 'currency_code' => '0512', 'status' => $status,
            'softpos_provider' => 'mosambee_muscat', 'softpos_package' => 'com.mosambee.muscat.softpos',
            'bank_id' => 99102, 'approved_by_staff_id' => $staff, 'device_id' => $device->id,
            'client_request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64),
            'attempted_at' => now(),
        ]);
    }
    $this->getJson('/admin/api/v1/card-terminal-issues')->assertOk()
        ->assertJsonCount(1, 'data.payments.data')->assertJsonCount(1, 'data.devices.data')
        ->assertJsonCount(1, 'data.reversals.data')->assertJsonPath('data.reversals.data.0.status', 'uncertain')
        ->assertJsonPath('data.reversals.data.0.approver', 'Approving manager');
    $viewer = User::factory()->create();
    $viewer->assignRole(PlatformRole::Support->value);
    $this->actingAs($viewer)->getJson('/admin/api/v1/card-terminal-issues')->assertForbidden();
});
