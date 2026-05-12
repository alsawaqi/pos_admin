<?php

declare(strict_types=1);

use App\Actions\Admin\CreateBranchAction;
use App\Actions\Admin\CreateCompanyAction;
use App\Actions\Admin\CreateDeviceActivationTokenAction;
use App\Actions\Admin\RegisterDeviceAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Admin\CreateBranchData;
use App\Data\Admin\CreateCompanyData;
use App\Data\Admin\RegisterDeviceData;
use App\Enums\DeviceStatus;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\DeviceActivationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a merchant company, branch, POS device, and activation token', function (): void {
    $actor = User::factory()->create([
        'user_type' => UserType::PlatformAdmin,
    ]);

    $audit = new WriteAuditLogAction;

    $company = (new CreateCompanyAction($audit))->handle(
        new CreateCompanyData(
            name: 'Demo Cafe',
            contactEmail: 'owner@example.test',
        ),
        $actor,
    );

    $branch = (new CreateBranchAction($audit))->handle(
        new CreateBranchData(
            companyId: $company->id,
            name: 'Muscat Branch',
            code: 'MCT-01',
        ),
        $actor,
    );

    $device = (new RegisterDeviceAction($audit))->handle(
        new RegisterDeviceData(
            serialNumber: 'POS-DEMO-001',
            companyId: $company->id,
            branchId: $branch->id,
        ),
        $actor,
    );

    $plainToken = (new CreateDeviceActivationTokenAction($audit))->handle($device, $actor);

    expect($device->status)->toBe(DeviceStatus::Assigned)
        ->and($plainToken)->toStartWith('mithqal_');

    $this->assertDatabaseHas(DeviceActivationToken::class, [
        'device_id' => $device->id,
        'token_hash' => hash('sha256', $plainToken),
    ]);

    $this->assertDatabaseHas(AuditLog::class, [
        'event' => 'device.activation_token.created',
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ]);
});
