<?php

declare(strict_types=1);

namespace App\Enums;

enum PlatformPermission: string
{
    case MerchantsView = 'merchants.view';
    case MerchantsCreate = 'merchants.create';
    case MerchantsUpdate = 'merchants.update';
    case MerchantsTransitionStatus = 'merchants.transition_status';
    case MerchantsDelete = 'merchants.delete';

    case MerchantDocumentsView = 'merchant_documents.view';
    case MerchantDocumentsUpload = 'merchant_documents.upload';
    case MerchantDocumentsVerify = 'merchant_documents.verify';

    case BranchesView = 'branches.view';
    case BranchesCreate = 'branches.create';
    case BranchesUpdate = 'branches.update';
    case BranchesTransitionStatus = 'branches.transition_status';
    case BranchesDelete = 'branches.delete';

    case DevicesView = 'devices.view';
    case DevicesRegister = 'devices.register';
    case DevicesAssign = 'devices.assign';
    case DevicesUnassign = 'devices.unassign';
    case DevicesDecommission = 'devices.decommission';
    // Lane A — mint one-shot activation codes for an assigned device.
    // Separate from Assign because the workflow split: assigning a
    // device to a branch is a planning step; minting the activation
    // code is the "kick it off NOW" moment when the floor tech is
    // ready to type it into the tablet. Same role gating practically
    // (DeviceOps + SuperAdmin) but semantically distinct.
    case DevicesActivate = 'devices.activate';
    case DeviceModelsManage = 'device_models.manage';
    case DeviceShipmentsManage = 'device_shipments.manage';

    case MerchantUsersView = 'merchant_users.view';
    case MerchantUsersInvite = 'merchant_users.invite';
    case MerchantUsersRevoke = 'merchant_users.revoke';

    case PlatformUsersView = 'platform_users.view';
    case PlatformUsersInvite = 'platform_users.invite';
    case PlatformUsersUpdateRoles = 'platform_users.update_roles';
    case PlatformUsersSuspend = 'platform_users.suspend';

    case AuditLogsView = 'audit_logs.view';

    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    case SettingsManage = 'settings.manage';

    // Reference-data CRUD for the platform-wide list of business
    // activities (the categories merchants pick when onboarding).
    // View is implicit — anyone with merchants.view can fetch the
    // active list. Manage is needed to add, edit, or deactivate a
    // category.
    case BusinessActivitiesManage = 'business_activities.manage';

    // Phase 4.8b — role builder. RolesView lets a user browse the
    // role catalog and the permission tree (for context); RolesManage
    // is the sharp tool that lets them create / edit / delete
    // platform roles. NOTE: assigning roles TO a platform user is a
    // different gate — that's the existing platform_users.update_roles
    // permission. Splitting CRUD-the-role from assign-it-to-someone
    // mirrors the merchant side (where it's branches.update vs
    // branches.transition_status).
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
