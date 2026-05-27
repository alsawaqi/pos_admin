<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the 5 default platform roles + every PlatformPermission
 * under the PLATFORM_TEAM_ID scope (team_id=0).
 *
 * Phase 4.8b changes vs the original 4.x version:
 *
 *   - Each default role is stamped `is_system=true` so the new
 *     role-builder UI (Pages/Admin/Roles/Index.vue) hides the
 *     delete button + locks rename. Admins can still mutate
 *     which permissions a system role holds (so they can
 *     tighten or loosen "Support" to taste), but the row's
 *     existence + canonical name are guaranteed.
 *
 *   - We do NOT call syncPermissions on every run any more —
 *     that would wipe a custom edit. Instead, on first
 *     creation the role gets its seeded permission set; on
 *     subsequent runs only the row's metadata (is_system,
 *     description) is refreshed.
 *
 *   - SuperAdmin is the special case: it ALWAYS gets the full
 *     permission set on every run. Protects against the
 *     "platform admin accidentally removed a permission from
 *     the owner role and now nobody can fix it" footgun. Since
 *     every new PlatformPermission case automatically lands
 *     in $all, SuperAdmin also auto-picks up future permissions
 *     without re-running the seeder.
 *
 *   - The new `roles.view` + `roles.manage` keys go ONLY to
 *     SuperAdmin in the seed — every other role gets them
 *     granted on demand via the Roles UI. This matches the
 *     "platform_users.update_roles is its own gate" pattern.
 */
class PlatformRoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);

        foreach (PlatformPermission::values() as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Flush the registrar's in-memory permission cache so the
        // role assignments below see any permissions we just
        // firstOrCreate'd. Without this, syncPermissions() on a
        // role that references a brand-new permission name throws
        // PermissionDoesNotExist because the cached collection is
        // stale.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->roleCatalogue() as $roleName => $meta) {
            $existing = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
                ->first();

            if ($existing === null) {
                // First-time seed — create + assign initial set.
                /** @var Role $role */
                $role = Role::query()->create([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'team_id' => TenantContext::PLATFORM_TEAM_ID,
                    'is_system' => true,
                    'description' => $meta['description'],
                ]);
                $role->syncPermissions($meta['permissions']);

                continue;
            }

            // Existing row — refresh metadata via a direct DB
            // update (avoids triggering observer hooks) but DO
            // NOT touch the permission pivot. Custom edits stay
            // intact.
            DB::table('pos_roles')
                ->where('id', $existing->id)
                ->update([
                    'is_system' => true,
                    'description' => $meta['description'],
                ]);

            // SuperAdmin gets force-resynced to the full
            // permission set on every run — guarantees the
            // owner can never lock themselves out by editing
            // this role.
            if ($roleName === PlatformRole::SuperAdmin->value) {
                $existing->syncPermissions(PlatformPermission::values());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, array{description: string, permissions: list<string>}>
     */
    private function roleCatalogue(): array
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
            // Branch delete is destructive — kept off Onboarding's
            // matrix; only Super Admin gets it via $all below.
            PlatformPermission::MerchantUsersView->value,
            PlatformPermission::MerchantUsersInvite->value,
            PlatformPermission::MerchantUsersRevoke->value,
            PlatformPermission::AuditLogsView->value,
            // Onboarding officers add new activity categories as
            // they discover gaps in the seeded list during pilots.
            PlatformPermission::BusinessActivitiesManage->value,
            PlatformPermission::RolesView->value,
        ];

        $deviceOps = [
            PlatformPermission::MerchantsView->value,
            PlatformPermission::BranchesView->value,
            PlatformPermission::DevicesView->value,
            PlatformPermission::DevicesRegister->value,
            PlatformPermission::DevicesAssign->value,
            PlatformPermission::DevicesUnassign->value,
            // Activation-code minting is part of the rollout
            // workflow — DeviceOps owns it end-to-end.
            PlatformPermission::DevicesActivate->value,
            PlatformPermission::DevicesDecommission->value,
            PlatformPermission::DeviceModelsManage->value,
            PlatformPermission::DeviceShipmentsManage->value,
            PlatformPermission::AuditLogsView->value,
            PlatformPermission::RolesView->value,
        ];

        $support = [
            PlatformPermission::MerchantsView->value,
            PlatformPermission::MerchantDocumentsView->value,
            PlatformPermission::BranchesView->value,
            PlatformPermission::DevicesView->value,
            PlatformPermission::MerchantUsersView->value,
            PlatformPermission::AuditLogsView->value,
            PlatformPermission::RolesView->value,
        ];

        $finance = [
            PlatformPermission::MerchantsView->value,
            PlatformPermission::BranchesView->value,
            PlatformPermission::ReportsView->value,
            PlatformPermission::ReportsExport->value,
            PlatformPermission::AuditLogsView->value,
            PlatformPermission::RolesView->value,
        ];

        return [
            PlatformRole::SuperAdmin->value => [
                'description' => 'Full access to every platform feature. Cannot be deleted.',
                'permissions' => $all,
            ],
            PlatformRole::OnboardingOfficer->value => [
                'description' => 'Onboards merchants — creates companies, uploads documents, manages branches + merchant portal users.',
                'permissions' => $onboarding,
            ],
            PlatformRole::DeviceOperations->value => [
                'description' => 'Manages the device fleet — registers, assigns, unassigns, decommissions devices; maintains makes + models + shipments.',
                'permissions' => $deviceOps,
            ],
            PlatformRole::Support->value => [
                'description' => 'Read-only across merchants, branches, devices, and audit logs. Cannot mutate anything.',
                'permissions' => $support,
            ],
            PlatformRole::FinanceViewer->value => [
                'description' => 'Reports + export only. Read-only across the rest of the catalog for context.',
                'permissions' => $finance,
            ],
        ];
    }
}
