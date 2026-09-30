<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function fix3CutoffDevice(array $extra = []): Device
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    return Device::factory()->create(array_merge([
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'assigned_at' => now()->subHour(), 'status' => 'active',
        'device_token' => hash('sha256', 'release-token-'.Str::random(8)),
        'token_company_id' => $company->id, 'token_branch_id' => $branch->id,
    ], $extra));
}

function fix3RunRepair(): void
{
    (require database_path('migrations/2026_09_30_000008_repair_pos_p0_history_and_bindings.php'))->up();
}

it('E2 a kept token whose early history is missing still gets its last activation as cut-off', function () {
    // Activated two days ago; the only history row starts an hour ago
    // (early history missing, no move proven).
    $device = fix3CutoffDevice();
    DB::table('pos_device_assignments_history')->insert(['device_id' => $device->id,
        'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
        'assigned_at' => now()->subHour(), 'unassigned_at' => null]);
    DeviceActivationToken::factory()->create(['device_id' => $device->id, 'used_at' => now()->subDays(2)]);

    fix3RunRepair();

    $device->refresh();
    expect($device->device_token)->not->toBeNull()
        ->and($device->getAttribute('assignment_activated_at'))->not->toBeNull()
        ->and((string) $device->getAttribute('assignment_activated_at'))->toStartWith(now()->subDays(2)->format('Y-m-d'))
        ->and((string) $device->getAttribute('token_issued_at'))->toStartWith(now()->subDays(2)->format('Y-m-d'));
});

it('E2 a kept token with no activation record or history falls back to its assignment time', function () {
    $device = fix3CutoffDevice(['assigned_at' => now()->subDays(5)]);

    fix3RunRepair();

    $device->refresh();
    expect($device->device_token)->not->toBeNull()
        ->and((string) $device->getAttribute('assignment_activated_at'))->toStartWith(now()->subDays(5)->format('Y-m-d'));
});

it('E2 after the repair no device keeps a token without a cut-off', function () {
    fix3CutoffDevice();
    $withHistory = fix3CutoffDevice();
    DB::table('pos_device_assignments_history')->insert(['device_id' => $withHistory->id,
        'company_id' => $withHistory->company_id, 'branch_id' => $withHistory->branch_id,
        'assigned_at' => now()->subMinutes(30), 'unassigned_at' => null]);
    DeviceActivationToken::factory()->create(['device_id' => $withHistory->id, 'used_at' => now()->subDays(3)]);

    fix3RunRepair();

    expect(DB::table('pos_devices')->whereNotNull('device_token')->whereNull('assignment_activated_at')->count())->toBe(0);
});
