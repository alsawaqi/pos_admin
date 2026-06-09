<?php

declare(strict_types=1);

/**
 * Admin platform round-up donation report (v2 #18).
 *
 *   GET /admin/api/v1/roundup-report?from=&to=&company_uuid=
 *
 * Charity raised across merchants (successful donations) + per-merchant breakdown.
 * reports.view gated; window-bounded (occurred_at); optional company_uuid scope.
 */

use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function actingAsRoundupAdmin(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

function seedAdminRoundup(int $companyId, int $orderId, string $amount, string $status, string $occurredAt = '2026-06-12 10:00:00'): void
{
    DB::table('pos_roundup_donations')->insert([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'branch_id' => 10,
        'device_id' => 1,
        'order_id' => $orderId,
        'payment_id' => $orderId,
        'amount' => $amount,
        'status' => $status,
        'source' => 'pos_roundup',
        'occurred_at' => $occurredAt,
        'created_at' => $occurredAt,
        'updated_at' => $occurredAt,
    ]);
}

it('is gated under reports.view', function (): void {
    $this->actingAs(User::factory()->create());
    $this->getJson('/admin/api/v1/roundup-report')->assertForbidden();
});

it('aggregates platform raised + per-merchant breakdown', function (): void {
    actingAsRoundupAdmin($this);
    $alpha = Company::factory()->create(['name' => 'Alpha Co']);
    $beta = Company::factory()->create(['name' => 'Beta Co']);
    seedAdminRoundup($alpha->id, 1, '0.200', 'success');
    seedAdminRoundup($alpha->id, 2, '0.150', 'success');
    seedAdminRoundup($beta->id, 3, '0.500', 'success');
    seedAdminRoundup($alpha->id, 4, '0.100', 'pending');
    seedAdminRoundup($beta->id, 5, '0.050', 'fail');
    seedAdminRoundup($beta->id, 6, '9.000', 'success', '2026-05-01 10:00:00'); // out of window

    $data = $this->getJson('/admin/api/v1/roundup-report?from=2026-06-01&to=2026-06-30')
        ->assertOk()->json('data');

    expect($data['headline']['total_raised'])->toBe('0.850'); // 0.200+0.150+0.500
    expect($data['headline']['donation_count'])->toBe(3);
    expect($data['headline']['pending_count'])->toBe(1);
    expect($data['headline']['failed_count'])->toBe(1);
    expect($data['headline']['num_merchants'])->toBe(2);

    // by_merchant sorted by raised desc → Beta (0.500) before Alpha (0.350).
    expect($data['by_merchant'][0]['company_name'])->toBe('Beta Co');
    expect($data['by_merchant'][0]['total_raised'])->toBe('0.500');
    expect($data['by_merchant'][1]['company_name'])->toBe('Alpha Co');
    expect($data['by_merchant'][1]['total_raised'])->toBe('0.350');
    expect($data['by_merchant'][1]['donation_count'])->toBe(2);
});

it('scopes to a single merchant via company_uuid', function (): void {
    actingAsRoundupAdmin($this);
    $alpha = Company::factory()->create(['name' => 'Alpha Co']);
    $beta = Company::factory()->create(['name' => 'Beta Co']);
    seedAdminRoundup($alpha->id, 1, '0.200', 'success');
    seedAdminRoundup($beta->id, 2, '5.000', 'success');

    $data = $this->getJson("/admin/api/v1/roundup-report?from=2026-06-01&to=2026-06-30&company_uuid={$alpha->uuid}")
        ->assertOk()->json('data');

    expect($data['headline']['total_raised'])->toBe('0.200');
    expect($data['headline']['num_merchants'])->toBe(1);
    expect($data['by_merchant'])->toHaveCount(1);
});
