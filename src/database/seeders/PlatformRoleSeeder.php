<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PlatformRoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        foreach (PlatformPermission::values() as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        foreach ($this->roleMatrix() as $roleName => $permissions) {
            /** @var Role $role */
            $role = Role::query()->firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'team_id' => null,
            ]);

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, list<string>>
     */
    private function roleMatrix(): array
    {
        $all = PlatformPermission::values();

        $onboarding = [
            PlatformPermission::MerchantsView->value,
            PlatformPermission::MerchantsCreate->value,
            PlatformPermission::MerchantsUpdate->value,
            PlatformPermission::MerchantsTransitionStatus->value,
            PlatformPermission::MerchantDocumentsView->value,
            PlatformPermission::MerchantDocumentsUpload->value,
            PlatformPermission::MerchantDocumentsVerify->value,
            PlatformPermission::BranchesView->value,
            PlatformPermission::BranchesCreate->value,
            PlatformPermission::BranchesUpdate->value,
            PlatformPermission::BranchesTransitionStatus->value,
            PlatformPermission::MerchantUsersView->value,
            PlatformPermission::MerchantUsersInvite->value,
            PlatformPermission::MerchantUsersRevoke->value,
            PlatformPermission::AuditLogsView->value,
        ];

        $deviceOps = [
            PlatformPermission::MerchantsView->value,
            PlatformPermission::BranchesView->value,
            PlatformPermission::DevicesView->value,
            PlatformPermission::DevicesRegister->value,
            PlatformPermission::DevicesAssign->value,
            PlatformPermission::DevicesUnassign->value,
            PlatformPermission::DevicesDecommission->value,
            PlatformPermission::DeviceModelsManage->value,
            PlatformPermission::DeviceShipmentsManage->value,
            PlatformPermission::AuditLogsView->value,
        ];

        $support = [
            PlatformPermission::MerchantsView->value,
            PlatformPermission::MerchantDocumentsView->value,
            PlatformPermission::BranchesView->value,
            PlatformPermission::DevicesView->value,
            PlatformPermission::MerchantUsersView->value,
            PlatformPermission::AuditLogsView->value,
        ];

        $finance = [
            PlatformPermission::MerchantsView->value,
            PlatformPermission::BranchesView->value,
            PlatformPermission::ReportsView->value,
            PlatformPermission::ReportsExport->value,
            PlatformPermission::AuditLogsView->value,
        ];

        return [
            PlatformRole::SuperAdmin->value => $all,
            PlatformRole::OnboardingOfficer->value => $onboarding,
            PlatformRole::DeviceOperations->value => $deviceOps,
            PlatformRole::Support->value => $support,
            PlatformRole::FinanceViewer->value => $finance,
        ];
    }
}
