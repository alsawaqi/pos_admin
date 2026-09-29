<?php

use App\Actions\Admin\ReserveMerchantTerminalAction;
use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->seed(PlatformRoleSeeder::class);
});

it('W9 never returns PINs and hides terminal credentials from Support', function () {
    $device = Device::factory()->create(['terminal_id' => 'SECRET-T', 'terminal_pin' => '998877']);
    foreach ([PlatformRole::SuperAdmin, PlatformRole::Support] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $response = $this->actingAs($user)->getJson("/admin/api/v1/devices/{$device->uuid}")->assertOk()
            ->assertJsonMissingPath('data.terminal_pin');
        expect($response->getContent())->not->toContain('998877');
        if ($role === PlatformRole::Support) {
            $response->assertJsonMissingPath('data.terminal_pin_set')->assertJsonMissingPath('data.terminal_id');
            expect($response->getContent())->not->toContain('SECRET-T');
        } else {
            $response->assertJsonPath('data.terminal_pin_set', true);
        }
    }
});

it('W10 retains merchant ownership after release and requires a super-admin written transfer', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $device = Device::factory()->create();
    $next = Device::factory()->create();
    $action = app(ReserveMerchantTerminalAction::class);
    $super = User::factory()->create();
    $super->assignRole(PlatformRole::SuperAdmin->value);
    $ops = User::factory()->create();
    $ops->assignRole(PlatformRole::DeviceOperations->value);
    $action->handle($device, $company->id, 5, ' t 123 ', $super, null);
    $device->update(['terminal_id' => null, 'company_id' => null, 'branch_id' => null]);
    expect(fn () => $action->handle($next, $other->id, 5, 'T123', $ops, 'Approved by bank'))
        ->toThrow(HttpException::class);
    expect(fn () => $action->handle($next, $other->id, 5, 'T123', $super, ''))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseHas('pos_terminal_reservations', ['terminal_id' => 'T123', 'company_id' => $company->id]);
    $action->handle($next, $other->id, 5, 'T123', $super, 'Bank transferred the merchant account');
    $this->assertDatabaseHas('pos_terminal_reservations', ['terminal_id' => 'T123', 'company_id' => $other->id, 'device_id' => $next->id]);
    $audit = DB::table('pos_audit_logs')->where('event', 'device.terminal_merchant_transfer')->sole();
    expect(json_decode($audit->metadata, true)['reason'])->toBe('Bank transferred the merchant account')
        ->and($audit->company_id)->toBeNull();
});

it('W5 force-logout revokes platform and merchant sessions and is audited', function (string $type) {
    $super = User::factory()->create();
    $super->assignRole(PlatformRole::SuperAdmin->value);
    $target = User::factory()->create(['user_type' => $type, 'remember_token' => 'remember']);
    $version = (int) $target->auth_version;
    $this->actingAs($super)->postJson("/admin/api/v1/users/{$target->id}/force-logout")->assertOk();
    expect((int) $target->fresh()->auth_version)->not->toBe($version)->and($target->fresh()->remember_token)->toBeNull();
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'user.force_logout', 'auditable_id' => $target->id, 'actor_user_id' => $super->id]);
})->with(['platform_admin', 'merchant']);
